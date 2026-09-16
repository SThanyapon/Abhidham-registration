# Abhidham Registration

A PHP + MySQL classroom management system for the Abhidhamma course: public
registration/check-in/student-ID lookup, plus an OTP-protected admin backend for
managing classes, approvals, promotions, and backups. See `DESIGN.md` for the full
data model and feature design derived from the original requirements.

## Setup

1. Copy `config.local.php.example` to `config.local.php` and fill in your MySQL
   and SMTP (email) credentials. `config.local.php` is gitignored so secrets never
   get committed.
2. Import the schema (creates the database and seeds the 9 class levels):
   ```
   mysql -u root -p < schema.sql
   ```
3. Create your first admin user:
   ```
   php scripts/create_admin.php <username> <email> <password> 1,2,3,4
   ```
4. Run a local PHP server:
   ```
   php -S localhost:8000
   ```
5. Open `http://localhost:8000`:
   - `/` — student registration
   - `/checkin.php` — class check-in
   - `/lookup.php` — student ID lookup
   - `/admin/login.php` — admin backend (username/password, then an emailed OTP code)

## Before students can register or check in

An admin needs to, via `/admin/classes.php`:
1. Create a batch (รุ่น) and open its registration.
2. Create a class instance (batch + level) and generate its recurring session schedule.

## Scheduled backups

`cron/backup.php` is a CLI script (`php cron/backup.php`) that dumps the database
to a timestamped `.sql` file under the configured `backup.directory` and emails a
notification. Point Windows Task Scheduler (or cron on Linux) at it on whatever
interval you need.

## Known simplifications

- The SMTP mailer (`includes/mailer.php`) is a minimal hand-rolled client (OTP and
  backup notification emails only, plain text, no attachments). Swap in PHPMailer
  if you need HTML email or attaching the backup file itself.
- Only the นาย/นาง/นางสาว/อื่นๆ student-ID group has an auto-rollover for batches
  with more than 99 students; พระ and the สิกขามานา/สามเณร/สามเณรี/แม่ชี group fall
  back to manual ID entry in that (unlikely) case — see `DESIGN.md` section 5.
