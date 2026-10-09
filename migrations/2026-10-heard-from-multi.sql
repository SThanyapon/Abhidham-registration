-- ทราบข่าวการสมัครจากช่องทางใด became a multi-select: heard_from now holds several options joined
-- with ", " (STUDENT_MULTI_SEPARATOR), which can exceed 50 Thai characters.
-- Apply once after 2026-10-registration-fields.sql. Take a backup first (php cron/backup.php).

ALTER TABLE students MODIFY heard_from VARCHAR(255);
