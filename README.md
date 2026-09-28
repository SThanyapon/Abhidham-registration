# Abhidham Registration

A PHP + MySQL classroom management system for the Abhidhamma course: public
registration/check-in/student-ID lookup, plus an OTP-protected admin backend for
managing classes, approvals, reports, promotions, and backups. See `DESIGN.md` for the full
data model and feature design derived from the original requirements.

## Requirements

- PHP 8.0+ (the codebase uses `match` expressions and typed properties). The production VM
  runs PHP 8.5.
- MySQL/MariaDB with the `mysqli` extension.

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
   php scripts/create_admin.php <username> <email> <password> 1,2,3,4,5
   ```
4. Run a local PHP server:
   ```
   php -S localhost:8000
   ```
5. Open `http://localhost:8000`:
   - `/` — landing page; click "ลงทะเบียน" (Register) (or visit `/?register=1`) to open the
     registration form
   - `/checkin.php` — "ลงชื่อ/ตรวจสอบการเข้าเรียน": student ID + name, then pick a class and
     session to check in, or leave them blank to just view attendance progress
   - `/lookup.php` — find a student ID by name; also tells pending/rejected applicants their
     registration status
   - `/admin/login.php` — admin backend (username/password, then an emailed OTP code)

The UI is entirely in Thai and loads the Sarabun font from Google Fonts (falls back to the system
font when offline).

## Before students can register or check in

An admin needs to, via `/admin/classes.php`:
1. Create a batch (รุ่น) and open its registration.
2. Create a class instance (batch + level) and generate its recurring session schedule.

## Scheduled jobs

- `cron/backup.php` — CLI script (`php cron/backup.php`) that dumps the database
  to a timestamped `.sql` file under the configured `backup.directory` and emails
  a notification. Run it weekly (e.g. Saturday night).
- `cron/clear_expired_otp.php` — CLI script that deletes expired rows from
  `otp_codes`. Run it frequently (e.g. hourly), since OTP codes are short-lived.

Point Windows Task Scheduler (or cron on Linux) at both on whatever interval you need.

## Maintenance scripts

- `scripts/create_admin.php` — create an admin user (see Setup).
- `scripts/normalize_whitespace.php` — one-off cleanup that applies the input whitespace rules
  (`includes/input.php`) to rows stored before those rules existed. Idempotent; back up first.

## Deploying

Production runs the app as a git checkout of this repo, owned by the web server user
(`www-data`), with `config.local.php`, `backups/` and `logs/` living only on the server. To deploy:

1. Commit and push to `master`.
2. On the server, back up first if the change touches data:
   `sudo -u www-data php cron/backup.php`
3. Pull as the web server user so file ownership stays consistent with the cron jobs:
   `sudo -u www-data git -C <app dir> pull --ff-only`
4. Lint: `php -l` on the changed files, then smoke-test the affected pages.

To roll back, `git reset --hard <previous commit>` in the app directory (and restore the backup
with `mysql <db name> < backups/<file>.sql` if data was changed).

## Rate limiting

`includes/rate_limit.php` throttles every public form (register, check-in, lookup) by client IP,
using the thresholds in `config.local.php['rate_limit']`. Admin login and OTP verification are
throttled more defensively, on independent buckets so neither a single source nor a distributed
one can brute-force an account:

- `admin_login` — by IP, catches one source spraying many accounts.
- `admin_login_account` — by username, catches one account attacked from many IPs.
- `otp_verify` — by the pending admin ID, caps guesses against a valid login's OTP code.

## Known simplifications

- The SMTP mailer (`includes/mailer.php`) is a minimal hand-rolled client (OTP and
  backup notification emails only, plain text, no attachments). Swap in PHPMailer
  if you need HTML email or attaching the backup file itself.
- Only the นาย/นาง/นางสาว/อื่นๆ student-ID group has an auto-rollover for batches
  with more than 99 students; พระ and the สิกขามานา/สามเณร/สามเณรี/แม่ชี group fall
  back to manual ID entry in that (unlikely) case — see `DESIGN.md` section 5.
- Sorting Thai names/prefixes in the admin report uses MySQL's collation, which doesn't apply Thai
  leading-vowel ordering (e.g. แม่ชี sorts after สามเณร). Proper Thai sorting would need PHP's
  `intl` extension.
