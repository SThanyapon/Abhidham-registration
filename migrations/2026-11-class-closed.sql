-- A class instance (ห้องเรียน) can be closed from admin/classes.php once the batch finishes that
-- level: checkin.php then refuses check-ins to it and admin/schedule.php makes its schedule
-- read-only. Students can still view their progress. Apply once, before deploying the code that
-- reads the column. Take a backup first (php cron/backup.php).

ALTER TABLE class_instances ADD is_closed BOOLEAN NOT NULL DEFAULT FALSE;
