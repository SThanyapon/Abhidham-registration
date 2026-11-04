-- ยืนยันการรับหนังสือ: an admin opens book-shipping confirmation per ห้องเรียน on admin/classes.php;
-- students of that class confirm a shipping address once on books.php (one row per student and
-- class, enforced by the UNIQUE key); admin/reports.php?view=books counts and exports them.
-- Apply once, before deploying the code that reads them. Take a backup first (php cron/backup.php).

ALTER TABLE class_instances ADD book_confirmation_open BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE book_confirmations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_instance_id INT NOT NULL,
    recipient_name VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NOT NULL,
    address TEXT NOT NULL,
    country VARCHAR(100),
    province VARCHAR(100),
    district VARCHAR(100),
    sub_district VARCHAR(100),
    postal_code VARCHAR(20),
    same_as_registration BOOLEAN NOT NULL DEFAULT FALSE,
    confirmed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (student_id, class_instance_id),
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (class_instance_id) REFERENCES class_instances(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
