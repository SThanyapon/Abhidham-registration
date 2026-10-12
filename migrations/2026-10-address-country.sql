-- The registration address now asks where the student lives: in Thailand the postcode picks
-- จังหวัด / อำเภอ / ตำบล from assets/data/thai_postcodes.json; abroad, Country / State / City /
-- PostCode are free text. Adds country, district and sub_district, and widens postal_code for
-- foreign postcodes. Existing rows with a Thai province get country ประเทศไทย; the old
-- "อยู่ต่างประเทศ" province answer is cleared (the country is unknown - the address holds it).
-- Apply once after 2026-10-heard-from-multi.sql. Take a backup first (php cron/backup.php).

ALTER TABLE students
    ADD COLUMN country VARCHAR(100) AFTER address,
    ADD COLUMN district VARCHAR(100) AFTER province,
    ADD COLUMN sub_district VARCHAR(100) AFTER district,
    MODIFY postal_code VARCHAR(20);

UPDATE students SET country = 'ประเทศไทย' WHERE province IS NOT NULL AND province <> 'อยู่ต่างประเทศ';
UPDATE students SET province = NULL WHERE province = 'อยู่ต่างประเทศ';
