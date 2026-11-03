-- Import once into an empty database. No account or password is included.
CREATE TABLE members (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 email VARCHAR(150) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE tasks (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(200) NOT NULL,
 description TEXT NOT NULL,
 member_id INT UNSIGNED NOT NULL,
 due_date DATE NOT NULL,
 status ENUM('todo','doing','done') NOT NULL DEFAULT 'todo',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_task_member FOREIGN KEY (member_id) REFERENCES members(id)
 ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE app_sessions (
 id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 data MEDIUMBLOB NOT NULL,
 expires_at BIGINT UNSIGNED NOT NULL,
 INDEX idx_session_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
