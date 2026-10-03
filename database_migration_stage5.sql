USE procurement;

CREATE TABLE IF NOT EXISTS divisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  division_head VARCHAR(150) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE areas ADD COLUMN division_id INT UNSIGNED NULL AFTER id;

INSERT IGNORE INTO divisions(name, division_head)
SELECT CONCAT('Legacy - ', a.name),
       COALESCE(NULLIF(a.authorized_person,''),'Not Yet Assigned')
FROM areas a;

UPDATE areas a
JOIN divisions d ON d.name=CONCAT('Legacy - ',a.name)
SET a.division_id=d.id
WHERE a.division_id IS NULL;

ALTER TABLE areas
MODIFY COLUMN division_id INT UNSIGNED NOT NULL;

ALTER TABLE areas
ADD CONSTRAINT fk_area_division
FOREIGN KEY (division_id) REFERENCES divisions(id);

ALTER TABLE areas DROP COLUMN authorized_person;
