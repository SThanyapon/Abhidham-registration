-- The class start date now comes from the schedule (the class's first non-cancelled session,
-- generated in admin/schedule.php) instead of being typed when the class instance is created in
-- admin/classes.php. The column is kept, nullable and no longer written, so existing rows keep
-- their value. Apply once, before deploying the code that stops writing it.
-- Take a backup first (php cron/backup.php).

ALTER TABLE class_instances MODIFY start_date DATE NULL;
