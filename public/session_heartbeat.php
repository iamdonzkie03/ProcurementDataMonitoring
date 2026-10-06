<?php
require_once __DIR__.'/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
if(!isLoggedIn()){
    http_response_code(401);
    echo json_encode(['ok'=>false]);
    exit;
}
touchCurrentUserActivity();
echo json_encode(['ok'=>true]);
