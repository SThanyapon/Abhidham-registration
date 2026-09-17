CREATE DATABASE IF NOT EXISTS abhidham_registration
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE abhidham_registration;

CREATE TABLE batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_no INT NOT NULL UNIQUE,
    name VARCHAR(255),
    registration_open BOOLEAN NOT NULL DEFAULT FALSE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE class_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    sort_order INT NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE class_instances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    class_level_id INT NOT NULL,
    start_date DATE NOT NULL,
    UNIQUE (batch_id, class_level_id),
    FOREIGN KEY (batch_id) REFERENCES batches(id),
    FOREIGN KEY (class_level_id) REFERENCES class_levels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_instance_id INT NOT NULL,
    session_number INT NOT NULL,
    session_date DATE NOT NULL,
    is_cancelled BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE (class_instance_id, session_number),
    FOREIGN KEY (class_instance_id) REFERENCES class_instances(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_no VARCHAR(10) UNIQUE,
    prefix VARCHAR(50) NOT NULL,
    prefix_other VARCHAR(100),
    full_name VARCHAR(255) NOT NULL,
    age INT,
    address TEXT,
    phone VARCHAR(50) NOT NULL,
    line_id VARCHAR(100),
    reference_person VARCHAR(255),
    batch_id INT NOT NULL,
    current_class_level_id INT,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES batches(id),
    FOREIGN KEY (current_class_level_id) REFERENCES class_levels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE checkins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    checked_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (student_id, session_id),
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (session_id) REFERENCES sessions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    from_class_level_id INT,
    to_class_level_id INT NOT NULL,
    promoted_by INT NOT NULL,
    promoted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (from_class_level_id) REFERENCES class_levels(id),
    FOREIGN KEY (to_class_level_id) REFERENCES class_levels(id),
    FOREIGN KEY (promoted_by) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE student_id_sequences (
    batch_id INT NOT NULL,
    prefix_group TINYINT NOT NULL,
    last_seq INT NOT NULL DEFAULT 0,
    PRIMARY KEY (batch_id, prefix_group),
    FOREIGN KEY (batch_id) REFERENCES batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_permissions (
    admin_user_id INT NOT NULL,
    feature TINYINT NOT NULL, -- 1=classes, 2=approvals, 3=reports, 4=promotions, 5=backup
    PRIMARY KEY (admin_user_id, feature),
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    consumed BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rate_limit_hits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_key VARCHAR(50) NOT NULL,
    identifier VARCHAR(100) NOT NULL,
    hit_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (form_key, identifier, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE backup_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_path VARCHAR(500) NOT NULL,
    triggered_by ENUM('manual', 'scheduled') NOT NULL,
    emailed_to VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the fixed class level progression path
INSERT INTO class_levels (name, sort_order) VALUES
    ('จูฬตรี', 1),
    ('จูฬโท', 2),
    ('จูฬเอก', 3),
    ('มัชฌิมตรี', 4),
    ('มัชฌิมโท', 5),
    ('มัชฌิมเอก', 6),
    ('มหาตรี', 7),
    ('มหาโท', 8),
    ('มหาเอก', 9);
