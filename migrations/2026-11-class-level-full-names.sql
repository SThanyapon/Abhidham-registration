-- The 9 class levels get their full Thai titles, with the short name in brackets. Every page and
-- CSV shows class_levels.name; no code compares level names (logic uses sort_order), so this can
-- be applied before or after deploying. Idempotent. Take a backup first (php cron/backup.php).

UPDATE class_levels SET name = 'จูฬอาภิธรรมิกะตรี (จูฬตรี)' WHERE sort_order = 1;
UPDATE class_levels SET name = 'จูฬอาภิธรรมิกะโท (จูฬโท)' WHERE sort_order = 2;
UPDATE class_levels SET name = 'จูฬอาภิธรรมิกะเอก (จูฬเอก)' WHERE sort_order = 3;
UPDATE class_levels SET name = 'มัชฌิมอาภิธรรมิกะตรี (มัชฌิมตรี)' WHERE sort_order = 4;
UPDATE class_levels SET name = 'มัชฌิมอาภิธรรมิกะโท (มัชฌิมโท)' WHERE sort_order = 5;
UPDATE class_levels SET name = 'มัชฌิมอาภิธรรมิกะเอก (มัชฌิมเอก)' WHERE sort_order = 6;
UPDATE class_levels SET name = 'มหาอาภิธรรมิกะตรี (มหาตรี)' WHERE sort_order = 7;
UPDATE class_levels SET name = 'มหาอาภิธรรมิกะโท (มหาโท)' WHERE sort_order = 8;
UPDATE class_levels SET name = 'มหาอาภิธรรมิกะเอก (มหาเอก)' WHERE sort_order = 9;
