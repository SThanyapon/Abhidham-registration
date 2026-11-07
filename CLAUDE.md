# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PHP + MySQL classroom management system for the Abhidhamma course ("Abhidham Registration"): public
student registration/check-in/student-ID lookup, plus an OTP-protected admin backend for managing
admin accounts, classes, approvals, reports, promotions, CSV student imports, student details, and
backups. Plain PHP, no framework, no Composer/npm dependencies.

`DESIGN.md` is the source of truth for the data model and feature spec (derived from the original
requirements doc) — read it before making schema or business-logic changes, especially the student ID
generation rules (section 5) and the % attendance calculation (section 4.2).

## Commands

There is no build step, package manager, linter, or test suite in this repo — it's plain PHP files
served directly.

```
# Local dev server
php -S localhost:8000

# Apply/reset schema (creates DB abhidham_registration, seeds the 9 class levels)
mysql -u root -p < schema.sql

# Existing DBs: apply each new migrations/*.sql once (schema.sql already includes them)
mysql -u root -p abhidham_registration < migrations/2026-10-registration-fields.sql
mysql -u root -p abhidham_registration < migrations/2026-10-heard-from-multi.sql
mysql -u root -p abhidham_registration < migrations/2026-10-address-country.sql
mysql -u root -p abhidham_registration < migrations/2026-11-class-start-date-optional.sql
mysql -u root -p abhidham_registration < migrations/2026-11-class-closed.sql
mysql -u root -p abhidham_registration < migrations/2026-11-class-level-full-names.sql
mysql -u root -p abhidham_registration < migrations/2026-11-book-confirmations.sql

# Create an admin user (feature numbers: 0=manage admins, 1=batches/classes/schedule, 2=approvals, 3=reports,
# 4=promotions, 6=CSV import, 7=edit student, 9=backup; 5 is unused; defaults to all 8)
php scripts/create_admin.php <username> <email> <password> [0,1,2,3,4,6,7,9]

# Run a manual DB backup (also invoked from admin/backup.php and intended for a scheduled task/cron)
php cron/backup.php

# Purge expired OTP codes (intended for a scheduled task/cron; runs hourly in production)
php cron/clear_expired_otp.php

# One-off: apply includes/input.php whitespace rules to rows stored before they existed (idempotent)
php scripts/normalize_whitespace.php

# One-off: rewrite students' จังหวัด/อำเภอ/ตำบล to the postcode JSON's spelling (dry run unless --apply)
php scripts/normalize_addresses.php [--apply]
```

First-time setup requires copying `config.local.php.example` to `config.local.php` (gitignored) and
filling in DB/SMTP credentials — `includes/db.php` calls `die()` if it's missing. Before students can
register or check in, an admin must open a batch's registration on `/admin/batches.php`, create a
class instance on `/admin/classes.php` (the batch's จัดการห้องเรียน link), then generate its schedule
on `/admin/schedule.php` (the จัดการตารางเรียน link; see README.md). README.md also has the full production installation for a
Linux server (nginx allowlist + PHP-FPM, certbot, dedicated MySQL user, www-data cron, php.ini), the
shared-hosting variant (hPanel database/cron, `.htaccess`, cron schedules in server time — often UTC)
and the deploy procedure (`git pull --ff-only` on the server).

## Architecture

**Request model:** each top-level `.php` file is both the route and the handler — no router, no MVC
layer. Public pages (`index.php`, `registration-form.php` + its POST handler `register.php`,
`checkin.php`, `lookup.php`, `books.php`) render forms with inline
HTML/PHP and POST to sibling scripts. Admin pages under `admin/` follow the same pattern but require
login. There is no templating engine; HTML is written directly in the `.php` files after the PHP logic
block, using `<?= htmlspecialchars(...) ?>` for all user-supplied output. The web server is an
allowlist (`/`, the six public `.php` files, `/admin/*.php`, static `/assets/`; everything else 404):
nginx config on a Linux server, the repo's `.htaccess` on Apache/LiteSpeed shared hosting. A new
top-level page needs both updated (see README "Deploying"). Both also send the same security headers
(X-Frame-Options, nosniff, Referrer-Policy, HSTS), so a header change goes in both places.

`index.php` is a notifications page: one `.card.announcement` per thing open right now (a batch
with registration open → `registration-form.php`; ห้องเรียน with book confirmation open, listed by
name → `books.php`). With neither open it redirects to `checkin.php`. While a batch has registration
open, the first view in a session pops up the intake poster (`assets/images/landingpage.jpg`, a
`<dialog>`, flagged by `$_SESSION['landing_seen']`). Old links `?register=1` / `?success=1`
redirect to `registration-form.php` (`?success=1`). `registration-form.php` is the form (posting to
`register.php`, which redirects back) and, with `?success=1`, the classroom QR
(`assets/images/qr-code7.jpg`). These images are per-intake content; swap the files for a new รุ่น.
Every "ลงทะเบียนเลย" (the registration card's link and the poster's button) is the large blue
`.cta-button`.

**`includes/` — shared logic, loaded via `require_once`, no autoloading:**
- `db.php` — sets the PHP timezone to `Asia/Bangkok` (so `date('Y-m-d')` "today" rolls over at
  Thai midnight), `getConfig()` (lazy-loads `config.local.php` into `$GLOBALS`) and `getDbConnection()`
  (lazy mysqli singleton, `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`, utf8mb4). Every other include
  depends on this one. All queries use prepared statements (`bind_param`).
- `auth.php` — admin session management (`loginAdmin`/`logoutAdmin`/`currentAdminId`) and **per-feature**
  authorization: `requireAdminLogin()` gates on being logged in and on the account still existing with
  `is_active = 1` (so disabling/deleting an admin in `admin/admins.php` ends their session on the next
  request) and on not being idle longer than `ADMIN_IDLE_TIMEOUT_SECONDS` (1 hour), and `requireFeature($adminId, $n)` gates on the numbered feature (0-4, 6, 7, 9) via the
  `admin_permissions` table. Every admin page calls both.
- `csrf.php` — `csrfToken()`/`csrfField()`/`verifyCsrf()`. Every POST handler calls `verifyCsrf()`
  first; every form includes `<?= csrfField() ?>`. Also home of `ensureSessionStarted()`, which sets
  the session cookie to HttpOnly + SameSite=Lax (+ Secure over HTTPS) with strict mode.
- `rate_limit.php` — sliding-window limiter (`checkRateLimit($formKey, $identifier)` +
  `recordRateLimitHit`) backed by the `rate_limit_hits` table, thresholds read from
  `config.local.php['rate_limit']` (a missing key means no limit). Applied to register, check-in, lookup and
  books (`book_confirm`) by `form_key`, keyed by
  client IP. Admin login (`admin/login.php`) enforces two independent buckets — `admin_login` keyed by
  IP and `admin_login_account` keyed by username — so a distributed attack spread across many IPs is
  still capped per targeted account, not just per source. OTP verification (`admin/verify_otp.php`) adds
  its own `otp_verify` bucket keyed by the pending admin ID.
- `otp.php` — 6-digit OTP generation/verification for admin login, hashed at rest in `otp_codes`,
  single-use, TTL from config. Issuing a code retires the account's earlier unused codes.
- `mailer.php` — a hand-rolled minimal SMTP client (`sendEmail`) used for OTP codes and backup
  files. Plain-text body plus optional attachments (`multipart/mixed`, base64), with SMTP
  dot-stuffing; no Composer/PHPMailer dependency. Aborts (returns false) if STARTTLS fails, so
  credentials never go out in clear text. Swap this file if HTML email is ever needed.
- `student_id.php` — `generateStudentNo()`: the student ID allocation algorithm. Uses a
  `FOR UPDATE`-locked transaction on `student_id_sequences` (per batch + prefix group running count) to
  avoid races, then derives `{batch_no}{group_digit}{2-digit seq}`. The นาย/นาง/นางสาว/อื่นๆ group
  overflows its group digit from 2 up through 9 once a batch passes 99 in that group; the other two
  groups have no rollover and rely on the `UNIQUE` constraint on `students.student_no` plus manual
  admin override if they ever exceed 99. `noteImportedStudentNo()` is the inverse, used by the CSV
  import: it raises `last_seq` past an externally supplied ID so later generated IDs don't collide.
  Don't change the digit-math in either without re-reading DESIGN.md §5.
- `attendance.php` — `getAttendanceSummary()`: builds the check-in grid and % complete. The denominator
  is sessions whose date is on/before the *most recent past session's date* (not a fixed "today" cutoff,
  and not counting future scheduled sessions) — this is a deliberate business rule, not a bug.
  `addDisplayNumbers()` gives each session the number every UI shows (`display_number`): 1..n over
  the class's non-cancelled sessions in `session_number` order, `null` for cancelled ones, so a
  cancelled class leaves no gap (`checkin.php`, `admin/schedule.php`, `admin/reports.php`). The stored
  `session_number` is internal and never changes; un-cancelling a session renumbers the later ones.
- `backup.php` — `runBackup()`: pure-PHP `SHOW CREATE TABLE` + row dump, gzip-compressed to a
  timestamped `.sql.gz` file under `config.local.php['backup']['directory']` (no dependency on the
  `mysqldump` binary; file chmod 0600, a new directory 0700), emailed as an attachment to each recipient, logged to `backup_runs`.
  Recipients come from `getBackupRecipients()`: the comma-separated `backup_recipient_email` row in
  the `app_settings` key/value table (edited in `admin/backup.php` via `saveBackupRecipients()`),
  falling back to `config.local.php['backup']['recipient_email']` until one is saved.
- `public_nav.php` — `publicNav($mysqli)`: the `<nav>` every public page outputs (ลงทะเบียน →
  `registration-form.php`, check-in, lookup, ยืนยันการรับหนังสือ only while `bookConfirmationOpen()`,
  ผู้ดูแลระบบ). Change the student menu here, not in the pages.
- `student_helpers.php` — `studentPrefix()` (display prefix: `prefix_other` when `prefix` is
  `อื่นๆ`), `formatAddressLines()` (a students/book_confirmations address as display lines) and `studentClassInstanceId()` (class instance for a student's batch + current level;
  `null` if not created yet). Used by `checkin.php`, `lookup.php`, `admin/approvals.php`,
  `admin/reports.php`.
- `date_helpers.php` — `formatDateBEShort()`: renders an ISO date as a short Thai Buddhist-Era date
  (e.g. `17 ก.ย. 69` — day, abbreviated Thai month, 2-digit BE year). Used wherever a session/class date
  is displayed to students or admins (`checkin.php`, `admin/classes.php`, `admin/schedule.php`, `admin/reports.php`); storage stays Gregorian ISO
  (`DATE` columns), conversion only happens at display time.
- `input.php` — whitespace normalization for user input, used instead of bare `trim()` on every
  free-text `$_POST` field: `cleanText()` (trim + collapse internal whitespace, incl. NBSP and
  zero-width chars, to one space), `cleanMultilineText()` (same per line, for the address textarea),
  `cleanCode()` (strip all whitespace, for student IDs and OTP codes), and `isValidPersonName()`
  (registration name whitelist: Thai/English letters, digits, spaces, dashes). This matters for correctness,
  not just tidiness: check-in and lookup match `full_name` by exact equality. Passwords are never
  cleaned. `csvSafe()` neutralises spreadsheet formulas (leading `= + - @`) in CSV export rows; wrap
  every `fputcsv` data row that can hold user-entered text.
- `student_validation.php` — `validateStudentFields($raw, $requireRegistrationExtras = false)`:
  cleans and validates the registration-form fields (prefix whitelist `STUDENT_PREFIXES`, the
  radio-button questions in `STUDENT_CHOICE_FIELDS` with their `{field}_other` free text, required
  fields, name/age/phone/postal-code rules) and returns `[$clean, $error]`. Shared by `register.php`,
  `admin/import_students.php` and `admin/students.php` so all three enforce identical rules; only
  `register.php` passes `true`, which makes the Google-Form fields (the address parts, LINE
  name/ID, choices, study reason) mandatory and checks a Thai postcode → จังหวัด → อำเภอ → ตำบล chain
  against `thaiPostcodes()` (`assets/data/thai_postcodes.json`) — older/imported students lack them.
  `studentResidence()` decides Thailand (country forced to `COUNTRY_THAILAND`, 5-digit postcode) vs
  abroad (free-text country/state/city/postcode, no sub-district). The three callers
  build their INSERT/UPDATE from `STUDENT_DETAIL_COLUMNS` + `studentDetailParams()`, so a new
  student field is added in this file (plus the form, schema and a migration). Change validation
  here, not in the callers. The address block is checked by `validateAddressFields($raw, $strict)`
  and the phone by `isValidPhone()`, both shared with `validateBookShipping()` (`books.php`).
- `student_form.php` — `studentChoiceField()` + `studentChoiceScript()`: renders a
  `STUDENT_CHOICE_FIELDS` question as its control type (`radio`, `select`, or `multi` = the
  browser's `<select multiple>` posted as `field[]` and stored comma-joined) with a
  show-on-"อื่นๆ" text box, and shows `previous_student_no` only for a returning student
  (`registration-form.php`, `admin/students.php`). `addressFields()` + `addressScript($dataUrl)` render the
  address block (Thai captions only): a Thailand/abroad radio, then a disabled-when-hidden
  `<fieldset>` per residence, each with its own street-address textarea (Thailand: postcode, then
  จังหวัด/อำเภอ/ตำบล dropdowns filled from the fetched postcode JSON, street address last; abroad:
  street address, เมือง, รัฐ, รหัสไปรษณีย์, ประเทศ as free text into the same columns).

**UI conventions:** every user-facing string (captions, messages, CSV headers, emails) is Thai, and
pages use `<html lang="th">` plus the Sarabun Google Font. All styling lives in `assets/style.css`
(CSS custom properties on `:root`, light-blue theme; every admin page uses `<body class="admin">`,
which overrides those properties with an orange theme, so new admin pages must add it too, and new
colours should be added as tokens rather than hard-coded); reuse its classes rather than inline styles:
`.error` / `.success` / `.notice` (red / green / amber alerts), `.card`, `.table-wrap` (wrap every
`<table>` for mobile scrolling), `.required-mark` + `.form-note` (red `*` on required fields),
`.inline-form` / `.action-row` / `.checkbox-label` (compact forms), `.sort-link` (clickable table
headers), `.view-toggle` (pill switch between views), `.field-group`, `.choice-field` (a
`studentChoiceField()` question), `.address-panel` (borderless `<fieldset>` per address
residence), `button.danger` /
`button.secondary`, `.cta-button` (large blue call-to-action for a `<button>` or a link, colours
from the `--cta*` tokens; public pages only), `.feature-links` (the dashboard's two-column card grid, one column under
480px), and the nav classes
`.nav-admin` (public pages' ผู้ดูแลระบบ link, right-aligned, orange) and `.nav-logout` (admin
pages' ออกจากระบบ, a POST form with a CSRF token styled as a right-aligned red link; `logout.php`
ignores GET). Validate on the server even when the form has
`required`/`pattern` attributes; `register.php` redirects back with the entered values in
`$_SESSION['register_old']` and the message in `$_SESSION['register_error']` (never in the URL, so
nobody can craft a link showing their own text) so the form can be refilled.

**`cron/backup.php`** is the CLI entry point for scheduled backups (Windows Task Scheduler or cron),
calling `includes/backup.php`'s `runBackup('scheduled')`.

**`cron/clear_expired_otp.php`** is the CLI entry point for purging expired rows from `otp_codes`
(`WHERE expires_at < NOW()`), intended to run frequently (e.g. hourly) since OTP codes are short-lived.

**Domain model** (see DESIGN.md §2-3 for full schema): batches (รุ่น, numbered cohorts with an
open/closed registration flag) contain class_instances (one per class level), which have generated
sessions. Students enroll into a batch as `pending`, get approved (which assigns `student_no` and sets
`current_class_level_id`), or are imported from CSV straight as `approved`; they then check in to
sessions and get promoted through the fixed level progression
`จูฬตรี → จูฬโท → จูฬเอก → มัชฌิมตรี → มัชฌิมโท → มัชฌิมเอก → มหาตรี → มหาโท → มหาเอก` (order enforced by
`class_levels.sort_order`, promotions logged in `promotions`). These are the short names; the
stored and displayed `class_levels.name` is the full title with the short name in brackets, e.g.
`จูฬอาภิธรรมิกะตรี (จูฬตรี)` (`migrations/2026-11-class-level-full-names.sql`). Code never matches on
the name, only on `sort_order`.

**Admin feature numbering** (used throughout `admin_permissions` and `requireFeature()` calls):
0 = manage admin staff (`admin/admins.php`), 1 = class/schedule management (`admin/batches.php` +
`admin/classes.php` + `admin/schedule.php`), 2 = enrollment approval,
3 = reports, 4 = promotion, 6 = CSV import (`admin/import_students.php`), 7 = edit student details
(`admin/students.php`), 9 = backup. Number 5 is unused (backup was moved from 5 to 9), and the
dashboard lists links in that numeric order. Access to each is granted per-admin-user independently,
so a logged-in admin may not have all eight.

`admin/admins.php` (feature 0) is the web equivalent of `scripts/create_admin.php`. It also has a
per-account panel (`?edit=<id>`) to reset the password (which clears pending OTP codes), enable or
disable the account, or delete it. Both password forms (create and reset) default to a
client-side generated password (`crypto.getRandomValues`, 12-50 chars via a slider) shown in the
password fields; typing one requires choosing กำหนดรหัสผ่านเอง. The server-side checks are
unchanged. Admins can't disable or delete themselves. Deleting is refused
when `promotions.promoted_by` references the account, so the audit trail stays intact. Feature
permissions can't be edited after creation.

"Current class" rules (deliberate, keep them consistent): a batch's current class is its
class_instance with the highest `class_levels.sort_order`. `checkin.php` offers only that class per
batch, only while it's open (`is_closed = 0`; a closed current class leaves the batch with nothing
to offer, no fallback to an older class), and only sessions dated today or earlier (future and
closed-class check-ins are refused server-side too). A chosen class that isn't the student's
current class is refused and shows no progress card; with no class chosen, progress is shown.
`admin/classes.php` shows the จัดการตารางเรียน link (to `admin/schedule.php?class_instance_id=`) only
for that class; `admin/schedule.php` enforces the same rule server-side (other classes and closed
classes are read-only) and redirects after each POST so a refresh can't generate the schedule
twice. `batches.php` allows opening/closing registration only for the latest batch (highest
`batch_no`, also enforced in the POST handler).

Feature 1 is three pages, รุ่น → ห้องเรียน → ตารางเรียน. `admin/batches.php` handles batches (create,
registration toggle) and links each batch to its classes. `admin/classes.php` (optional
`?batch_id=` filter, which also preselects the create form's batch; unknown ID = all classes)
handles class instances: created with รุ่น + ระดับ only, the table shows each class's start date,
number of non-cancelled sessions and open/closed status. Any class can be closed (`ปิดห้องเรียน`,
`class_instances.is_closed`, `migrations/2026-11-class-closed.sql`) once the batch finishes that
level, and reopened. A closed class takes no check-ins and its schedule is read-only, but students
still see their progress on `checkin.php`. The close form posts the target value explicitly and the
page redirects after each POST. `admin/schedule.php?class_instance_id=` generates a class's sessions and
cancels/un-cancels them; an unknown ID redirects back to `classes.php`. A class's start date is
always its **first non-cancelled session's date**, derived in both pages (`—` / ยังไม่มีตารางเรียน
when there's no schedule). `class_instances.start_date` is a legacy nullable column
(`migrations/2026-11-class-start-date-optional.sql`) that nothing reads or writes; don't bring it back.

**ยืนยันการรับหนังสือ (book shipping).** The books are free, so wording says รับหนังสือ /
จัดส่งหนังสือ, never สั่งซื้อ/ซื้อ. `admin/classes.php` opens or closes it per ห้องเรียน
(`class_instances.book_confirmation_open`, `migrations/2026-11-book-confirmations.sql`), for any
class whatever its `is_closed`. While any class is open, every public page shows the menu link (`publicNav()`) and `index.php` a card. `books.php`
works like this: student ID + name, then the student's current class must be open. The verified
IDs go into `$_SESSION['book_confirm']`, never hidden fields. The page then shows the registration
address on the left. The "ยืนยันการรับหนังสือ" button reveals the shipping form on the right
(recipient name, phone, `addressFields()`). "ใช้ที่อยู่เดียวกับที่ลงทะเบียน" copies the
registration address client-side. A confirmation is stored once per student per ห้องเรียน
(`book_confirmations` UNIQUE key; a duplicate-key race is treated as already confirmed) and shown
read-only afterwards. `same_as_registration` is computed server-side by comparing addresses.
`admin/reports.php?view=books` shows per-class counts, a per-class shipping list and a per-student
view. It uses the same four filters, and its CSV is the shipping list (`BOOK_COLUMNS` /
`bookRowValues()`). It stays available after closing.

`admin/import_students.php` (feature 6): CSV-imported students skip approval. They're inserted as
`approved` with the file's student ID, a batch derived from that ID (all but the last 3 digits) and
a starting level the admin chooses. Batches are never auto-created: if any row references a batch
that doesn't exist, the whole file is refused (nothing imported) and the missing batch numbers listed.
`noteImportedStudentNo()` bumps `student_id_sequences`. A Thai address is checked against the
postcode JSON by `matchThaiPostcodeAddress()` (`includes/student_validation.php`). Each filled
จังหวัด/อำเภอ/ตำบล must belong to the postcode; variant spellings are rewritten through
`thaiAddressKey()`, e.g. กรุงเทพฯ → กรุงเทพมหานคร, and the page lists these corrections. Blank parts
are allowed. `scripts/normalize_addresses.php` applies the same check to students already stored.
Invalid rows are skipped and reported, not
fatal. Columns are read by position (`IMPORT_COLUMNS`), so new columns are only ever appended to
the end (the latest are ประเทศ, อำเภอ/เขต, ตำบล/แขวง; a blank country means Thailand) and older,
shorter files keep importing. `admin/students.php` (feature 7) looks a student up by exact ID +
name and edits every registration-form field (same `addressFields()` block, lenient validation);
student ID, batch, level and status are read-only there.

`admin/reports.php` (feature 3) reuses `includes/attendance.php`'s `getAttendanceSummary()` to show
per-session attendance for one selected student or a summary table for every approved student, with
a CSV export (`?export=csv`, carries the current `student_id` selection) for either view. The
summary table sorts by clickable column headers (`?sort=student_no|prefix|full_name|batch|level|completed|percent`
+ `&dir=asc|desc`, whitelisted; default `student_no` ascending, ordered by length then value so
IDs sort numerically). Stored columns sort in SQL; the computed attendance columns sort in PHP, and
the CSV export follows the same order. A `?view=approved|rejected|books` toggle (default `approved`;
`books` is described under ยืนยันการรับหนังสือ above) switches to a list of rejected applicants (contact details, address parts other than the street
address, the form answers and the application date, sortable by
prefix/name/batch/date, newest first by default, with its own CSV export); rejected rows have no
`student_no` or level, so that view skips the `class_levels` join. Optional filters `?batch_id=`
(every view) and `?level_id=` (current level; approved and books views) narrow the table, the student
dropdown and the CSV export; `filterQuery()` carries them through sort, export and view links.
Student names in the tables link to the single-student grid (current class), which has a back
link to the list. A `?class_instance_id=` (ห้องเรียน) filter, approved view only, switches the
table to a per-session matrix for that class (green = checked in, grey = missed, blank = not held
yet, via `matrixCell()`), and the CSV export becomes the same matrix (`✓` / `-` / blank). Any class can be chosen, closed
ones too. It lists every approved student of that batch whose current level is at or past the
class's level, so a finished class still reports after promotions. It overrides the รุ่น filter
and ignores the ระดับชั้น one. Changing either of those clears it.
