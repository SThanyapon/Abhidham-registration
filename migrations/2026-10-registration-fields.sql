-- Registration form now mirrors the Google Form (รุ่น 7): adds the extra questions as nullable
-- columns (older and CSV-imported students may not have them), and fixes the สิกขมานา spelling.
-- Apply once: mysql -u root -p abhidham_registration < migrations/2026-10-registration-fields.sql
-- Take a backup first (php cron/backup.php).

ALTER TABLE students
    ADD COLUMN province VARCHAR(100) AFTER address,
    ADD COLUMN postal_code VARCHAR(5) AFTER province,
    ADD COLUMN line_name VARCHAR(255) AFTER phone,
    ADD COLUMN heard_from VARCHAR(50) AFTER line_id,
    ADD COLUMN heard_from_other VARCHAR(255) AFTER heard_from,
    ADD COLUMN student_type VARCHAR(100) AFTER heard_from_other,
    ADD COLUMN student_type_other VARCHAR(255) AFTER student_type,
    ADD COLUMN previous_student_no VARCHAR(20) AFTER student_type_other,
    ADD COLUMN study_reason TEXT AFTER reference_person,
    ADD COLUMN zoom_skill VARCHAR(50) AFTER study_reason,
    ADD COLUMN zoom_skill_other VARCHAR(255) AFTER zoom_skill,
    ADD COLUMN joined_classroom VARCHAR(50) AFTER zoom_skill_other,
    ADD COLUMN joined_classroom_other VARCHAR(255) AFTER joined_classroom;

UPDATE students SET prefix = 'สิกขมานา' WHERE prefix = 'สิกขามานา';
