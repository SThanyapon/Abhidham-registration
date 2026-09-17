# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PHP + MySQL classroom management system for the Abhidhamma course ("Abhidham Registration"): public
student registration/check-in/student-ID lookup, plus an OTP-protected admin backend for managing
classes, approvals, promotions, and backups. Plain PHP, no framework, no Composer/npm dependencies.

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

# Create an admin user (feature numbers: 1=classes, 2=approvals, 3=promotions, 4=backup; defaults to all 4)
php scripts/create_admin.php <username> <email> <password> [1,2,3,4]

# Run a manual DB backup (also invoked from admin/backup.php and intended for a scheduled task/cron)
php cron/backup.php

# Purge expired OTP codes (intended for a scheduled task/cron; runs hourly in production)
php cron/clear_expired_otp.php
```

First-time setup requires copying `config.local.php.example` to `config.local.php` (gitignored) and
filling in DB/SMTP credentials — `includes/db.php` calls `die()` if it's missing. Before students can
register or check in, an admin must open a batch's registration and generate a class schedule via
`/admin/classes.php` (see README.md).

## Architecture

**Request model:** each top-level `.php` file is both the route and the handler — no router, no MVC
layer. Public pages (`index.php`, `register.php`, `checkin.php`, `lookup.php`) render forms with inline
HTML/PHP and POST to sibling scripts. Admin pages under `admin/` follow the same pattern but require
login. There is no templating engine; HTML is written directly in the `.php` files after the PHP logic
block, using `<?= htmlspecialchars(...) ?>` for all user-supplied output.

**`includes/` — shared logic, loaded via `require_once`, no autoloading:**
- `db.php` — `getConfig()` (lazy-loads `config.local.php` into `$GLOBALS`) and `getDbConnection()`
  (lazy mysqli singleton, `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`, utf8mb4). Every other include
  depends on this one. All queries use prepared statements (`bind_param`).
- `auth.php` — admin session management (`loginAdmin`/`logoutAdmin`/`currentAdminId`) and **per-feature**
  authorization: `requireAdminLogin()` gates on being logged in, `requireFeature($adminId, $n)` gates on
  the numbered feature (1-4) via the `admin_permissions` table. Every admin page calls both.
- `csrf.php` — `csrfToken()`/`csrfField()`/`verifyCsrf()`. Every POST handler calls `verifyCsrf()`
  first; every form includes `<?= csrfField() ?>`.
- `rate_limit.php` — sliding-window limiter (`checkRateLimit($formKey, $identifier)` +
  `recordRateLimitHit`) backed by the `rate_limit_hits` table, thresholds read from
  `config.local.php['rate_limit']`. Applied to register, check-in, and lookup by `form_key`, keyed by
  client IP. Admin login (`admin/login.php`) enforces two independent buckets — `admin_login` keyed by
  IP and `admin_login_account` keyed by username — so a distributed attack spread across many IPs is
  still capped per targeted account, not just per source. OTP verification (`admin/verify_otp.php`) adds
  its own `otp_verify` bucket keyed by the pending admin ID.
- `otp.php` — 6-digit OTP generation/verification for admin login, hashed at rest in `otp_codes`,
  single-use, TTL from config.
- `mailer.php` — a hand-rolled minimal SMTP client (`sendEmail`) used for OTP codes and backup
  notifications. Plain text only, no attachments, no Composer/PHPMailer dependency. Swap this file if
  HTML email or attachments are ever needed.
- `student_id.php` — `generateStudentNo()`: the student ID allocation algorithm. Uses a
  `FOR UPDATE`-locked transaction on `student_id_sequences` (per batch + prefix group running count) to
  avoid races, then derives `{batch_no}{group_digit}{2-digit seq}`. The นาย/นาง/นางสาว/อื่นๆ group
  overflows its group digit from 2 up through 9 once a batch passes 99 in that group; the other two
  groups have no rollover and rely on the `UNIQUE` constraint on `students.student_no` plus manual
  admin override if they ever exceed 99. Don't change the digit-math without re-reading DESIGN.md §5.
- `attendance.php` — `getAttendanceSummary()`: builds the check-in grid and % complete. The denominator
  is sessions whose date is on/before the *most recent past session's date* (not a fixed "today" cutoff,
  and not counting future scheduled sessions) — this is a deliberate business rule, not a bug.
- `backup.php` — `runBackup()`: pure-PHP `SHOW CREATE TABLE` + row dump to a timestamped `.sql` file
  under `config.local.php['backup']['directory']` (no dependency on the `mysqldump` binary), logs to
  `backup_runs`, emails a notification (without the file attached, since `mailer.php` has no attachment
  support).
- `date_helpers.php` — `formatDateBEShort()`: renders an ISO date as a short Thai Buddhist-Era date
  (e.g. `17 ก.ย. 69` — day, abbreviated Thai month, 2-digit BE year). Used wherever a session/class date
  is displayed to students or admins (`checkin.php`, `admin/classes.php`); storage stays Gregorian ISO
  (`DATE` columns), conversion only happens at display time.

**`cron/backup.php`** is the CLI entry point for scheduled backups (Windows Task Scheduler or cron),
calling `includes/backup.php`'s `runBackup('scheduled')`.

**`cron/clear_expired_otp.php`** is the CLI entry point for purging expired rows from `otp_codes`
(`WHERE expires_at < NOW()`), intended to run frequently (e.g. hourly) since OTP codes are short-lived.

**Domain model** (see DESIGN.md §2-3 for full schema): batches (รุ่น, numbered cohorts with an
open/closed registration flag) contain class_instances (one per class level), which have generated
sessions. Students enroll into a batch as `pending`, get approved (which assigns `student_no` and sets
`current_class_level_id`), check in to sessions, and get promoted through the fixed level progression
`จูฬตรี → จูฬโท → จูฬเอก → มัชฌิมตรี → มัชฌิมโท → มัชฌิมเอก → มหาตรี → มหาโท → มหาเอก` (order enforced by
`class_levels.sort_order`, promotions logged in `promotions`).

**Admin feature numbering** (used throughout `admin_permissions` and `requireFeature()` calls):
1 = class/schedule management, 2 = enrollment approval, 3 = promotion, 4 = backup. Access to each is
granted per-admin-user independently — a logged-in admin may not have all four.
