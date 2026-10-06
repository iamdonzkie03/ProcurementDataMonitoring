<?php
if(ob_get_level()===0){ob_start();}
declare(strict_types=1);

session_start();

const DB_HOST = '127.0.0.1';
const DB_NAME = 'procurement';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function loginContext(): array {
    return $_SESSION['login_context'] ?? [];
}

function currentLoginDivisionId(): int {
    return (int)(loginContext()['division_id'] ?? 0);
}

function currentLoginAreaId(): int {
    return (int)(loginContext()['area_id'] ?? 0);
}

function currentUserIsPpmpSupervisor(): bool {
    $u=currentUser();
    if(!$u) return false;
    $divisionId=(int)($u['division_id']??currentLoginDivisionId());
    $name=trim((string)($u['full_name']??''));
    if($divisionId<=0 || $name==='') return false;
    try{
        $pdo=db();
        $st=$pdo->prepare("SELECT COUNT(*) FROM divisions
          WHERE id=? AND ppmp_supervisor_enabled=1
            AND LOWER(TRIM(division_head))=LOWER(TRIM(?))");
        $st->execute([$divisionId,$name]);
        return (int)$st->fetchColumn()>0;
    }catch(Throwable $e){ return false; }
}

function currentUserIsBudgetOfficer(): bool {
    $u=currentUser(); $ctx=loginContext();
    if(!$u || empty($ctx['area_id'])) return false;
    try{
        $pdo=db();
        $st=$pdo->prepare("SELECT COUNT(*) FROM area_personnel ap JOIN areas a ON a.id=ap.area_id
          WHERE ap.area_id=? AND ap.name=? AND LOWER(COALESCE(ap.position_designation,'')) LIKE '%budget officer%'
            AND LOWER(a.name) LIKE '%budget%'");
        $st->execute([(int)$ctx['area_id'],trim((string)($u['full_name']??''))]);
        return (int)$st->fetchColumn()>0;
    }catch(Throwable $e){ return false; }
}

function ensureUserAccessSchema(PDO $pdo): void {
    try {
        $cols=$pdo->query("SHOW COLUMNS FROM users LIKE 'division_id'")->fetch();
        if(!$cols) $pdo->exec("ALTER TABLE users ADD COLUMN division_id INT UNSIGNED NULL AFTER status");
        $cols=$pdo->query("SHOW COLUMNS FROM users LIKE 'area_id'")->fetch();
        if(!$cols) $pdo->exec("ALTER TABLE users ADD COLUMN area_id INT UNSIGNED NULL AFTER division_id");
    } catch(Throwable $e) {}
}

function buildLoginContext(PDO $pdo, array $u): array {
    ensureUserAccessSchema($pdo);
    $divisionId=(int)($u['division_id']??0);
    $areaId=(int)($u['area_id']??0);

    // A Division Head / Supervisor must be recognized even when no Area/Unit
    // is assigned to the user account. Supervisor access is division-wide.
    if($divisionId>0){
        $st=$pdo->prepare("SELECT id division_id,name division_name,division_head,head_position_designation,
          ppmp_supervisor_enabled
          FROM divisions
          WHERE id=? LIMIT 1");
        $st->execute([$divisionId]);
        $division=$st->fetch();
        if($division){
            $isSupervisor=((int)$division['ppmp_supervisor_enabled']===1 &&
              strcasecmp(trim((string)$division['division_head']),trim((string)($u['full_name']??'')))===0);

            $areaName='';$resolvedAreaId=0;
            if($areaId>0){
                $a=$pdo->prepare("SELECT id,name FROM areas WHERE id=? AND division_id=? LIMIT 1");
                $a->execute([$areaId,$divisionId]);
                $area=$a->fetch();
                if($area){$resolvedAreaId=(int)$area['id'];$areaName=(string)$area['name'];}
            }

            return [
              'division_id'=>(int)$division['division_id'],
              'area_id'=>$resolvedAreaId,
              'division_name'=>(string)$division['division_name'],
              'area_name'=>$areaName,
              'division_head'=>(string)$division['division_head'],
              'head_position_designation'=>(string)($division['head_position_designation']??''),
              'ppmp_supervisor_enabled'=>(int)$division['ppmp_supervisor_enabled'],
              'is_ppmp_supervisor'=>$isSupervisor
            ];
        }
    }

    return ['division_id'=>0,'area_id'=>0,'division_name'=>'','area_name'=>'','is_ppmp_supervisor'=>false];
}

function touchCurrentUserActivity(): void {
    $u=currentUser();
    if(!$u || empty($u['id'])) return;
    try{
        $pdo=db();
        ensureUserActivitySchema($pdo);
        $st=$pdo->prepare("UPDATE users SET last_activity_at=NOW() WHERE id=?");
        $st->execute([(int)$u['id']]);
    }catch(Throwable $e){}
}

function ensureUserActivitySchema(PDO $pdo): void {
    try{
        $cols=$pdo->query("SHOW COLUMNS FROM users LIKE 'last_activity_at'")->fetch();
        if(!$cols) $pdo->exec("ALTER TABLE users ADD COLUMN last_activity_at DATETIME NULL AFTER status");
    }catch(Throwable $e){}
}

function isLoggedIn(): bool {
    return currentUser() !== null;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function hasRole(array $roles): bool {
    $u = currentUser();
    return $u !== null && in_array($u['role'] ?? '', $roles, true);
}

function requireRole(array $roles): void {
    requireLogin();

    if (!hasRole($roles)) {
        http_response_code(403);
        exit('403 - Access denied');
    }
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array {
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function checkCsrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid CSRF token');
    }
}
