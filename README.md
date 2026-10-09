# Abhidham Registration

A PHP + MySQL classroom management system for the Abhidhamma course: public
registration/check-in/student-ID lookup, plus an OTP-protected admin backend for
managing admin accounts, classes, approvals, reports, promotions, CSV student imports, student
details, and backups. See `DESIGN.md` for the full data model and feature design derived from the
original requirements.

## Requirements

- PHP 8.0+ (the codebase uses `match` expressions and typed properties). The production VM
  runs PHP 8.5.
- MySQL/MariaDB with the `mysqli` extension.
- PHP extensions `mbstring`, `zlib` (gzip backups) and `openssl` (SMTP over TLS/SSL).
- The `iconv` extension (bundled with most PHP builds) for importing CSV files saved in Windows-874
  (Thai Excel's default); UTF-8 CSVs don't need it.
- An SMTP account for sending admin OTP codes and backup emails. Admin login can't work without it.

## Local setup (development)

1. Copy `config.local.php.example` to `config.local.php` and fill in your MySQL
   and SMTP (email) credentials. `config.local.php` is gitignored so secrets never
   get committed. The other keys (`rate_limit`, `otp.ttl_seconds`, `backup.directory`,
   `backup.recipient_email`) have working defaults.
2. Import the schema (creates the `abhidham_registration` database and seeds the 9 class levels;
   it already includes every file in `migrations/`, so don't apply those to a fresh install):
   ```
   mysql -u root -p < schema.sql
   ```
3. Create your first admin user:
   ```
   php scripts/create_admin.php <username> <email> <password> 0,1,2,3,4,6,7,9
   ```
   Feature numbers: 0=manage admins, 1=classes, 2=approvals, 3=reports, 4=promotions, 6=CSV import,
   7=edit student, 9=backup (defaults to all 8; 5 is unused). After that, more admin staff can be added
   from `/admin/admins.php` (feature 0).
4. Run a local PHP server:
   ```
   php -S localhost:8000
   ```
5. Open `http://localhost:8000`:
   - `/` — landing page; click "ลงทะเบียน" (Register) (or visit `/?register=1`) to open the
     registration form. While a batch's registration is open, the first visit in a browser session
     pops up the intake poster (`assets/images/landingpage.jpg`); after submitting, the page shows
     the classroom QR code (`assets/images/qr-code7.jpg`). Replace those images for a new intake.
   - `/checkin.php` — "ลงชื่อ/ตรวจสอบการเข้าเรียน": student ID + name, then pick a class (each
     batch's current, highest class) and a session dated today or earlier to check in, or leave
     them blank to just view attendance progress
   - `/lookup.php` — find a student ID (and current ระดับชั้น) by name; also tells
     pending/rejected applicants their registration status
   - `/admin/login.php` — admin backend (username/password, then an emailed OTP code). The
     dashboard shows, in two columns, only the features the admin has been granted:

     | # | Page | Feature |
     |---|------|---------|
     | 0 | `admin/admins.php` | Manage admins: create, reset password, enable/disable, delete |
     | 1 | `admin/classes.php` | Batches, class instances and session schedules |
     | 2 | `admin/approvals.php` | Approve/reject registrations (assigns student IDs) |
     | 3 | `admin/reports.php` | Attendance and rejected-applicant reports, filter by รุ่น/ระดับชั้น, CSV export |
     | 4 | `admin/promotions.php` | Promote students to the next level |
     | 6 | `admin/import_students.php` | Import already-approved students from CSV (their batches must exist) |
     | 7 | `admin/students.php` | Edit one student's details (by student ID + name) |
     | 9 | `admin/backup.php` | Back up the database now, set backup email recipients, view backup history |

     Admin pages use an orange theme; public pages are light blue.

The UI is entirely in Thai and loads the Sarabun font from Google Fonts (falls back to the system
font when offline).

The session cookie is marked `Secure` only over HTTPS, so login works on plain
`http://localhost` too.

## Production installation (Linux, nginx + PHP-FPM)

The steps below match the production VM (Ubuntu, nginx, PHP-FPM, MySQL, app at `/opt/abhidham`).
Substitute your own domain, paths and PHP version.

1. **Packages**: nginx, MySQL server, `php-fpm` and `php-mysql php-mbstring` (zlib, openssl and
   iconv are built in on Ubuntu's PHP), git, and certbot with its nginx plugin.
2. **Code**: clone the repo to `/opt/abhidham`, owned by `www-data` and closed to other users:
   ```
   sudo git clone <repo url> /opt/abhidham
   sudo chown -R www-data:www-data /opt/abhidham && sudo chmod 750 /opt/abhidham
   ```
3. **Database**: import the schema, then create a dedicated MySQL user for the app instead of
   using root:
   ```
   sudo mysql < /opt/abhidham/schema.sql
   sudo mysql -e "CREATE USER 'abhidham'@'localhost' IDENTIFIED BY '<strong password>';
     GRANT SELECT, INSERT, UPDATE, DELETE ON abhidham_registration.* TO 'abhidham'@'localhost';"
   ```
   The backup only reads (`SHOW CREATE TABLE`, `SELECT`), so the app needs no DDL rights.
   Restoring a backup is done as root.
4. **Config**: copy `config.local.php.example` to `config.local.php`. Fill in the DB user from
   step 3 and the SMTP account, and set `backup.directory` (default `<app>/backups`). Make it
   readable only by `www-data`:
   ```
   sudo -u www-data cp /opt/abhidham/config.local.php.example /opt/abhidham/config.local.php
   sudo chmod 640 /opt/abhidham/config.local.php    # then edit it with sudoedit
   ```
5. **First admin**:
   `sudo -u www-data php /opt/abhidham/scripts/create_admin.php <username> <email> <password>`.
6. **nginx**: an allowlist. Only the public pages, admin pages and `assets/` are served, and
   everything else returns 404, so docs, `backups/` (full personal-data dumps), `logs/`, `.git` and
   config files are never downloadable. Site config (`/etc/nginx/sites-enabled/abhidham`):
   ```nginx
   server {
       server_name <your domain>;
       root /opt/abhidham;
       index index.php;
       server_tokens off;

       add_header X-Frame-Options "DENY" always;
       add_header X-Content-Type-Options "nosniff" always;
       add_header Referrer-Policy "same-origin" always;
       add_header Strict-Transport-Security "max-age=31536000" always;

       location = / { }
       location ^~ /assets/ { try_files $uri =404; }
       location ~ ^/(index|register|checkin|lookup)\.php$ { include snippets/abhidham-php.conf; }
       location ~ ^/admin/[a-z_]+\.php$ { include snippets/abhidham-php.conf; }
       location / { return 404; }
   }
   ```
   and `/etc/nginx/snippets/abhidham-php.conf`:
   ```nginx
   fastcgi_pass unix:/run/php/php8.5-fpm.sock;   # match your PHP-FPM version
   fastcgi_index index.php;
   fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
   include fastcgi_params;
   ```
   **A new top-level public `.php` page must be added to the regex**, or it will return 404.
   Run `sudo nginx -t && sudo systemctl reload nginx` after any change.
7. **HTTPS**: `sudo certbot --nginx -d <your domain>`. Certbot adds the `listen 443 ssl` and
   certificate lines plus the HTTP-to-HTTPS redirect. HTTPS is required: the session cookie is
   only marked `Secure` over HTTPS, and HSTS is sent.
8. **PHP settings** (FPM `php.ini`): `display_errors = Off`, `log_errors = On`, `expose_php = Off`.
   Then restart PHP-FPM.
9. **Scheduled jobs** (`sudo crontab -u www-data -e`). Output goes to `logs/`, which nginx never
   serves:
   ```
   0 23 * * 6 /usr/bin/php /opt/abhidham/cron/backup.php >> /opt/abhidham/logs/backup.log 2>&1
   0 * * * * /usr/bin/php /opt/abhidham/cron/clear_expired_otp.php >> /opt/abhidham/logs/clear_expired_otp.log 2>&1
   ```
   Create `logs/` and `backups/` first, owned by `www-data`.
10. **Check from outside**: `curl -I https://<your domain>/` should return 200 with the four
    security headers and a `Set-Cookie` showing `secure; HttpOnly; SameSite=Lax`.
    `/README.md`, `/config.local.php`, `/backups/` and `/.git/config` should return 404. Then log in
    to `/admin/login.php` (the OTP email proves SMTP works) and run a manual backup from
    `/admin/backup.php`.
11. Open a batch's registration and create its class schedule (next section).

## Before students can register or check in

An admin needs to, via `/admin/classes.php`:
1. Create a batch (รุ่น) and open its registration. Only the latest batch (highest number) can
   have its registration opened or closed.
2. Create a class instance (batch + level) and generate its recurring session schedule. The
   schedule link is shown only for each batch's highest class level.

CSV-imported students also need their batch to exist first; the import refuses a file that
references a batch that hasn't been created.

## Scheduled jobs

- `cron/backup.php` — CLI script (`php cron/backup.php`) that dumps the database
  to a timestamped, gzip-compressed `.sql.gz` file under the configured `backup.directory`
  and emails it as an attachment to the recipients set on the backup page. Run it weekly
  (e.g. Saturday night).
- `cron/clear_expired_otp.php` — CLI script that deletes expired rows from
  `otp_codes`. Run it frequently (e.g. hourly), since OTP codes are short-lived.

Point Windows Task Scheduler (or cron on Linux) at both on whatever interval you need.

## Maintenance scripts

- `scripts/create_admin.php` — create an admin user (see Local setup step 3 / Production installation step 5).
- Features 0, 6 and 7 (manage admins, CSV import, edit student) were added later. To grant them to every
  existing admin on a database set up before them, run once:
  ```sql
  INSERT IGNORE INTO admin_permissions (admin_user_id, feature)
  SELECT u.id, f.n FROM admin_users u CROSS JOIN (SELECT 0 n UNION SELECT 6 UNION SELECT 7) f;
  ```
- Backup was renumbered from feature 5 to feature 9. On a database set up before that, run once:
  ```sql
  UPDATE admin_permissions SET feature = 9 WHERE feature = 5;
  ```
- The `app_settings` table (backup email recipients) was added later. On a database set up before
  it, run once:
  ```sql
  CREATE TABLE IF NOT EXISTS app_settings (
      setting_key VARCHAR(100) PRIMARY KEY,
      setting_value TEXT NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ```
- Resetting test data (e.g. before a new round of testing): back up first, then delete, in this
  FK-safe order, `checkins`, `promotions`, `students`, `student_id_sequences`, `sessions`,
  `class_instances`, `batches` (and optionally `rate_limit_hits`, `backup_runs`). Use `DELETE`
  inside a transaction rather than `TRUNCATE` (which fails on FK-referenced tables), then reset
  `AUTO_INCREMENT = 1`. Admin accounts, permissions, `class_levels` and `app_settings` are kept.
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
4. Apply any new file in `migrations/` (after the backup), once, in name order:
   `mysql <db name> < migrations/<file>.sql`. `schema.sql` already includes them for fresh installs.
5. Lint: `php -l` on the changed files, then smoke-test the affected pages.

Migrations change the schema, so apply them as MySQL root, not the app's restricted user. nginx is
an allowlist (see Production installation step 6): a deploy that adds a new top-level public `.php`
page must also add it to the nginx regex, or it will return 404. Check from outside with
`curl -I https://<site>/<path>`; anything not on the allowlist should return 404.

To roll back, `git reset --hard <previous commit>` in the app directory (and restore the backup
with `gunzip -c backups/<file>.sql.gz | mysql <db name>` if data was changed; older backups are
plain `.sql`: `mysql <db name> < backups/<file>.sql`).

## Rate limiting

`includes/rate_limit.php` throttles every public form (register, check-in, lookup) by client IP,
using the thresholds in `config.local.php['rate_limit']`. Admin login and OTP verification are
throttled more defensively, on independent buckets so neither a single source nor a distributed
one can brute-force an account:

- `admin_login` — by IP, catches one source spraying many accounts.
- `admin_login_account` — by username, catches one account attacked from many IPs.
- `otp_verify` — by the pending admin ID, caps guesses against a valid login's OTP code.

## Security notes

- SQL: prepared statements with `bind_param` throughout. The only concatenated SQL is `(int)` casts,
  whitelisted sort columns/directions, or constant table names.
- XSS: every user-supplied value is printed with `htmlspecialchars()`.
- CSRF: every POST handler calls `verifyCsrf()`, including logout, which is a POST button
  (a GET to `admin/logout.php` just redirects).
- Session cookie: `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS, strict mode
  (`ensureSessionStarted()` in `includes/csrf.php`). Admin sessions expire after 1 hour idle
  (`ADMIN_IDLE_TIMEOUT_SECONDS` in `includes/auth.php`).
- OTP: only the newest code is valid; a new login retires earlier unused codes.
- CSV exports pass data rows through `csvSafe()` (`includes/input.php`), so applicant text starting
  with `= + - @` can't run as an Excel formula.
- Registration errors are passed back in the session, never in the URL, so nobody can craft a
  link that shows their own message on the site.
- The mailer aborts if STARTTLS fails rather than sending SMTP credentials unencrypted.
- Backups (emailed as attachments) contain all personal data and admin password hashes. Keep the
  recipient list to trusted addresses.
- By design, students identify themselves by student ID + exact name only (no password). Lookup
  returns a student ID for an exact name, and rate limiting is the main protection.

## Known simplifications

- The SMTP mailer (`includes/mailer.php`) is a minimal hand-rolled client (OTP and
  backup emails only, plain-text body with optional attachments; refuses to authenticate if
  STARTTLS fails). Swap in PHPMailer if you need HTML email.
- Resetting an admin's password doesn't end that admin's sessions that are already logged in; they
  expire after the 1-hour idle timeout. Disable the account to cut access immediately.
- Only the นาย/นาง/นางสาว/อื่นๆ student-ID group has an auto-rollover for batches
  with more than 99 students; พระ and the สิกขมานา/สามเณร/สามเณรี/แม่ชี group fall
  back to manual ID entry in that (unlikely) case — see `DESIGN.md` section 5.
- An admin's feature permissions are set when the account is created and can't be changed from
  the UI afterwards (only password, enabled state, or deletion). Adjust `admin_permissions` in SQL
  if needed.
- An admin who has promoted students can't be deleted (`promotions.promoted_by` keeps that
  history); disable the account instead.
- The CSV import reads columns by position, not by header name, and always skips the first row,
  so keep the template's column order. Columns from "จังหวัด" onward are optional, so files made
  from the older 9-column template still import. "ทราบข่าวจากช่องทางใด" may list several options
  separated by commas; "รหัสนักศึกษาเดิม" is kept only when "นักศึกษาเก่าหรือใหม่" is the เก่า option.
- Sorting Thai names/prefixes in the admin report uses MySQL's collation, which doesn't apply Thai
  leading-vowel ordering (e.g. แม่ชี sorts after สามเณร). Proper Thai sorting would need PHP's
  `intl` extension.
