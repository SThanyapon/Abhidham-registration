# Abhidham Registration — Application Design

Source: `App requirement.docx`. This document translates that requirement into a concrete
data model, feature breakdown, and folder structure for the PHP + MySQL implementation.

## 1. Actors

- **Student (public, no login)** — registers, checks in to sessions (or just views their attendance
  progress), and looks up their own student ID / registration status.
- **Admin/Staff (OTP login required)** — manages admin accounts, classes & schedules, approves
  enrollments, runs attendance reports, promotes students, imports student lists from CSV, edits
  student details, and runs backups. Access to each of the 8 backend features is granted
  per-user (not all admins can do all 8 things).

## 2. Core domain concepts

- **รุ่น (Batch/Cohort)** — a numbered intake (e.g. รุ่น 7). Drives the leading digit of the
  student ID and which registration form is currently open.
- **Class / Level** — a study level. Fixed progression path:
  `จูฬตรี → จูฬโท → จูฬเอก → มัชฌิมตรี → มัชฌิมโท → มัชฌิมเอก → มหาตรี → มหาโท → มหาเอก`.
  New registrations always start at `จูฬตรี` (requirement #14). Students imported from CSV
  (Feature 6) start at a level the admin chooses for that upload.
- **Session** — one class meeting/lecture, generated from a recurring schedule
  (e.g. every Tue & Thu) or edited individually.
- **Enrollment** — a student's registration record for a รุ่น, starting as `pending`
  until an admin approves it. CSV-imported students skip this and are stored as `approved`
  with their existing student ID.
- **Check-in** — an attendance record tying a student to a session.

## 3. Database schema

```sql
-- Batches
CREATE TABLE batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_no INT NOT NULL UNIQUE,          -- e.g. 7 for รุ่น 7
    name VARCHAR(255),                     -- e.g. "รุ่น 7 ฉัฏฐญาณะ"
    registration_open BOOLEAN NOT NULL DEFAULT FALSE
);

-- Class levels (static reference data, seeded once)
CREATE TABLE class_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,     -- จูฬตรี, จูฬโท, ...
    sort_order INT NOT NULL UNIQUE         -- 1..9, defines promotion order
);

-- One offering of a class level within a batch (this is what has sessions/schedule)
CREATE TABLE class_instances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL REFERENCES batches(id),
    class_level_id INT NOT NULL REFERENCES class_levels(id),
    start_date DATE NOT NULL,
    UNIQUE (batch_id, class_level_id)
);

-- Generated/edited session instances for a class_instance
CREATE TABLE sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_instance_id INT NOT NULL REFERENCES class_instances(id),
    session_number INT NOT NULL,           -- 1, 2, 3, ... within the class
    session_date DATE NOT NULL,
    is_cancelled BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE (class_instance_id, session_number)
);

-- Students
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_no VARCHAR(10) UNIQUE,         -- NULL until approved; see section 5 for format
    prefix VARCHAR(50) NOT NULL,           -- พระ, สิกขมานา, สามเณร, สามเณรี, แม่ชี, นาย, นาง, นางสาว, อื่นๆ
    prefix_other VARCHAR(100),             -- free text when prefix = "อื่นๆ"
    full_name VARCHAR(255) NOT NULL,       -- Name-Surname (Thai)
    age INT,
    address TEXT,                          -- street address, excluding the parts below
    country VARCHAR(100),                  -- "ประเทศไทย" for residents of Thailand, else free text
    province VARCHAR(100),                 -- จังหวัด (Thailand) / รัฐ (abroad)
    district VARCHAR(100),                 -- อำเภอ/เขต (Thailand) / เมือง (abroad)
    sub_district VARCHAR(100),             -- ตำบล/แขวง (Thailand only)
    postal_code VARCHAR(20),               -- 5 digits in Thailand, free text abroad
    phone VARCHAR(50) NOT NULL,            -- mobile
    line_name VARCHAR(255),                -- LINE display name
    line_id VARCHAR(100),                  -- LINE ID or the phone number registered with LINE
    heard_from VARCHAR(255),               -- multi-choice, options joined with ", "; see STUDENT_CHOICE_FIELDS
    heard_from_other VARCHAR(255),         -- free text when heard_from = "อื่นๆ" (same for the *_other below)
    student_type VARCHAR(100),             -- new / returning student (choice)
    student_type_other VARCHAR(255),
    previous_student_no VARCHAR(20),       -- returning students' old ID, optional
    reference_person VARCHAR(255),         -- referring friend: name + phone
    study_reason TEXT,
    zoom_skill VARCHAR(50),                -- can use ZOOM? (choice)
    zoom_skill_other VARCHAR(255),
    joined_classroom VARCHAR(50),          -- already joined the class room? (choice)
    joined_classroom_other VARCHAR(255),
    -- province .. joined_classroom_other were added by migrations/2026-10-registration-fields.sql
    -- and are NULL for students registered/imported before then. country, district and
    -- sub_district were added by migrations/2026-10-address-country.sql.
    batch_id INT NOT NULL REFERENCES batches(id),
    current_class_level_id INT REFERENCES class_levels(id),
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Attendance
CREATE TABLE checkins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL REFERENCES students(id),
    session_id INT NOT NULL REFERENCES sessions(id),
    checked_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (student_id, session_id)        -- prevents double check-in
);

-- Promotion audit trail
CREATE TABLE promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL REFERENCES students(id),
    from_class_level_id INT REFERENCES class_levels(id),
    to_class_level_id INT NOT NULL REFERENCES class_levels(id),
    promoted_by INT NOT NULL REFERENCES admin_users(id),
    promoted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tracks the running count per (batch, prefix group) for student ID generation.
-- last_seq is a running COUNT (0-based), not the last bucket-local number — see section 5
-- for how it maps to {group_digit}{2-digit seq}. CSV imports raise it past each imported ID
-- (never lower it) so generated IDs don't collide (Feature 6).
CREATE TABLE student_id_sequences (
    batch_id INT NOT NULL REFERENCES batches(id),
    prefix_group TINYINT NOT NULL,         -- 0, 1, or 2 (see section 5)
    last_seq INT NOT NULL DEFAULT 0,
    PRIMARY KEY (batch_id, prefix_group)
);

-- Admin users
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,    -- OTP delivered here
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Per-user feature access
CREATE TABLE admin_permissions (
    admin_user_id INT NOT NULL REFERENCES admin_users(id),
    feature TINYINT NOT NULL,              -- 0=admins, 1=classes, 2=approvals, 3=reports, 4=promotions, 6=import, 7=edit students, 9=backup (5 unused)
    PRIMARY KEY (admin_user_id, feature)
);

-- OTP codes (admin login)
CREATE TABLE otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL REFERENCES admin_users(id),
    code_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    consumed BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Rate limiting — sliding window per (form_key, identifier). Most forms are keyed by
-- client IP; admin login and OTP verification use narrower identifiers (see section 6).
CREATE TABLE rate_limit_hits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_key VARCHAR(50) NOT NULL,         -- 'register', 'checkin', 'lookup', 'admin_login',
                                            -- 'admin_login_account', 'otp_verify'
    identifier VARCHAR(100) NOT NULL,      -- IP address, username, or pending admin ID
    hit_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (form_key, identifier, hit_at)
);

-- Backup run log
CREATE TABLE backup_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_path VARCHAR(500) NOT NULL,
    triggered_by ENUM('manual', 'scheduled') NOT NULL,
    emailed_to VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Admin-editable key/value settings, e.g. backup_recipient_email (comma-separated)
CREATE TABLE app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL
);
```

## 4. Public features

### 4.1 Student registration (`index.php`, `register.php`)
`index.php` is a landing page — the form is not shown until the visitor clicks "ลงทะเบียน"
(`?register=1`), so a first-time visit doesn't drop straight into a form. While a batch has
`registration_open = TRUE`, the visitor's first view of the landing page in a session
(`$_SESSION['landing_seen']`) pops up the intake poster (`assets/images/landingpage.jpg`) in a modal
`<dialog>`. Clicking the poster or ลงทะเบียนเลย opens the form; ปิด, Esc or a backdrop click closes it.
It never appears over the form, an error or the success page. After a successful submit,
`?success=1` shows the classroom QR code (`assets/images/qr-code7.jpg`) and the foundation's footer
banner. Fields: prefix
(dropdown starting on a "-- เลือกคำนำหน้า --" placeholder so the choice is explicit, incl.
"อื่นๆ (ระบุ)" free-text), name-surname, age, mailing address (below), mobile phone, LINE
name, LINE ID, how they heard about the course, new/returning student, previous student ID,
referring friend, reason for studying, can they use ZOOM, have they joined the class room. The
fields, their order and their captions mirror the course's Google Form (รุ่น 7); the four
multiple-choice questions' options and control types live in `STUDENT_CHOICE_FIELDS`
(`includes/student_validation.php`), each with an "อื่นๆ (ระบุ)" free-text option stored in a
`{field}_other` column: ทราบข่าวจากช่องทางใด is the browser's multi-select list (pick one or more; stored as
the picked options joined with ", " in option order), and นักศึกษาเก่าหรือใหม่, the ZOOM and
joined-classroom questions radio buttons (no fieldset frame). Previous student ID is
hidden (and disabled) unless นักศึกษาเก่าหรือใหม่ is the เก่า option and is discarded server-side otherwise.
Mailing address (`addressFields()` in `includes/student_form.php`): first a radio, อยู่ในประเทศไทย
(default) or อยู่ต่างประเทศ; captions are Thai only. In Thailand the student types the 5-digit
postcode, then picks จังหวัด, อำเภอ/เขต and ตำบล/แขวง from dropdowns offering only that postcode's entries
(`assets/data/thai_postcodes.json`, postcode → province → district → sub-districts, generated from the
course's geography.json; some postcodes span two provinces), then types the street address last;
country is stored as ประเทศไทย. Public registration rejects a chain that isn't in the file. Abroad
the order is ที่อยู่ (street address), เมือง (→ district), รัฐ (→ province), รหัสไปรษณีย์
(letters/digits/spaces/dashes, up to 20), ประเทศ, all free text stored in the same columns (no
sub-district); street address, เมือง and ประเทศ are required, รัฐ and รหัสไปรษณีย์ optional. Each
panel has its own street-address box, so the order can differ. The CSV import and admin edit only format-check the address parts so older
answers without อำเภอ/ตำบล survive; a blank country there means Thailand. **Required** (marked `*`, enforced client- and server-side): everything
except previous student ID and referring friend — prefix (plus the free-text prefix when "อื่นๆ"),
name-surname, age (integer 1-120), street address, the address parts above, phone (digits/spaces/
`-`/`+`, 9-15 digits), LINE name, LINE ID, the four choices (plus their free text when "อื่นๆ"),
reason. Previous student ID, when given, is 4-10 digits. Name-surname may contain only Thai
letters/vowels/tone marks/digits, English letters, digits, spaces, and dashes (`isValidPersonName()`
in `includes/input.php`); symbols such as `/`, `*`, `&`, `.`, `฿` are rejected. The fields added for
the Google Form are required only on public registration (`validateStudentFields($raw, true)`); the
CSV import and admin edit page keep them optional because older students don't have them. On a validation error
`register.php` stashes the entered values in `$_SESSION['register_old']` and the message in
`$_SESSION['register_error']`, then redirects to `?register=1`. `index.php` refills the form once,
with a message naming the missing fields. The message is never put in the URL, so a crafted link
can't show arbitrary text on the site. Always targets whichever batch currently has
`registration_open = TRUE` at level `จูฬตรี`. Inserted as `status = 'pending'`. Rate-limited.
Text fields are whitespace-normalized before storage (trimmed, internal runs collapsed to one
space; see `includes/input.php`), and the same normalization is applied to the name typed at
check-in/lookup, so extra spaces never cause a name mismatch.

### 4.2 Check-in (`checkin.php`)
Menu caption "ลงชื่อ/ตรวจสอบการเข้าเรียน" — the page both records attendance and shows progress.
Student enters student ID, then name-surname (both **required**, marked `*`; the ID comes first
since students remember it more reliably than the exact registered spelling of their name), and
optionally a class (the dropdown lists only each batch's highest class level, i.e. its current
class) and then **selects the ครั้งที่ (session) themselves** from a dropdown of that
class's sessions, shown in short Thai Buddhist Era date format (e.g. `17 ก.ย. 69`; no
auto-detection by date). Only sessions dated today or earlier (Asia/Bangkok) are listed, and the
server refuses a check-in for a future-dated session. System:
1. Verifies ID + name match an approved student, and finds their current class instance
   (`studentClassInstanceId()`, batch + current level). No class instance yet → a notice, no grid.
2. **Check-in mode** (class and session both chosen): the class must be the student's current
   class; if already checked in for that session → error; otherwise insert into `checkins` and
   confirm "ลงชื่อเข้าเรียนเรียบร้อยแล้ว".
3. **View-only mode** (class or session missing): nothing is written; a notice explains that only
   progress is shown.
4. In both modes, render the student's รหัสนักศึกษา / คำนำหน้า / ชื่อ-นามสกุล, then a grid of all
   sessions so far for their current class: green = checked in, grey = missing. ID, name and
   class are refilled after submitting.
5. `% complete = checkins / (count of sessions whose date <= the most recent past session's
   date)` — i.e. only sessions already conducted count toward the denominator, not future
   scheduled ones.
Rate-limited.

### 4.3 Student ID lookup (`lookup.php`)
Name + surname → looks up the registration in any status and shows a status-specific message
(when a name has several registrations, the most relevant wins: approved, then pending, then
rejected, newest first):
- **approved** → shows `student_no` and ระดับชั้น (current class level).
- **pending** → "คุณได้ทำการลงทะเบียนแล้ว แต่ยังไม่ได้รับการตรวจสอบจากผู้ดูแลระบบ กรุณาตรวจสอบใหม่ภายหลัง
  และขอความร่วมมือไม่ลงทะเบียนซ้ำ" (discourages duplicate registrations).
- **rejected** → "คุณไม่ได้รับการอนุมัติการลงทะเบียน ขอบคุณค่ะ".
- no match → "ไม่พบการลงทะเบียนของท่าน กรุณาทำการลงทะเบียนก่อนค่ะ".

Every result also echoes the searched name (after whitespace normalization) as
"ชื่อ-นามสกุล: …", prefixed with the registration's คำนำหน้า when one was found, so the user can
spot a typo.

## 5. Admin/backend features

OTP login: 6-digit code emailed to the admin's registered email, hashed and stored in
`otp_codes` with an expiry (e.g. 5 minutes), rate-limited per account. Issuing a code retires the
account's earlier unused codes, so only the newest is valid. After OTP verification, a session is
created (with a new session ID). Every admin page checks that the account still exists and is
active and that the session hasn't been idle for more than 1 hour (`requireAdminLogin()`). It then
checks `admin_permissions` for the relevant feature number before allowing access. Logout
(ออกจากระบบ) is a CSRF-protected POST.

The dashboard (`admin/dashboard.php`) lists only the features the admin holds, as a two-column grid
of cards (one column on screens narrower than 480px), in feature-number order: 0, 1, 2, 3, 4, 6, 7, 9.
Number 5 is unused (see section 9).

### Feature 0 — Manage admin staff
`admin/admins.php`, the web equivalent of `scripts/create_admin.php`. It creates an admin user
(username, email for OTP, password ≥ 8 chars, plus the features to grant) and lists the
existing admins with their features. Each listed admin has a จัดการ link (`?edit=<id>`) that opens:
- **Reset password**: set a new password (≥ 8 chars). Any pending OTP codes for that account are
  deleted.
- **Enable/disable**: toggles `admin_users.is_active`. Login already refuses disabled accounts, and
  `requireAdminLogin()` also logs out a disabled or deleted account on its next request.
- **Delete**: removes the account with its `otp_codes` and `admin_permissions` rows. It's refused
  when the admin has `promotions` history (`promoted_by` is a NOT NULL audit trail), and the admin is
  told to disable the account instead.

Both password forms (create and reset) default to ให้ระบบสร้างรหัสผ่าน: a password generated in the
browser (`crypto.getRandomValues`, 20 characters by default, 12-50 via a slider, skipping
look-alike characters `0 O o 1 l I`) fills both password fields, with สร้างใหม่ (regenerate) and
คัดลอก (copy) buttons. Typing a password requires choosing กำหนดรหัสผ่านเอง. The server only sees
the two password fields and applies the same ≥ 8 chars check either way.

An admin can't disable or delete their own account, but can reset their own password. The acting
admin is always active and holds feature 0, so at least one admin who can manage accounts always
remains. Changing an existing admin's feature permissions isn't supported.

### Feature 1 — Class management
- Open/close registration (`batches.registration_open`) — only for the latest batch (highest
  `batch_no`); older batches keep their current flag and show no toggle (also enforced server-side).
- The จัดการตารางเรียน link is shown only for each batch's highest class level.
- Create a `class_instance` (batch + level + start date).
- Generate a recurring schedule: given number of sessions, start date, and days-of-week
  (e.g. Tue+Thu), bulk-insert `sessions` rows numbered sequentially.
- Cancel an individual session (toggle `is_cancelled`) without regenerating the whole series.
  Rescheduling a single session's date is not supported from the UI — regenerate/adjust via
  the schedule generator instead. Each session is shown with a weekday label and its date in
  short Thai Buddhist Era format (e.g. `17 ก.ย. 69`, via the shared `formatDateBEShort()` in
  `includes/date_helpers.php`, also used in check-in). Cancelled sessions are rendered with a
  grey row background for quick scanning.

### Feature 2 — Approve enrollment
`admin/approvals.php`. Lists `pending` students with everything they entered on the registration
form: prefix and name, batch, age, phone, LINE name and ID, the full address (street address,
ตำบล, อำเภอ, จังหวัด, postcode and country, blanks skipped), the choice answers, previous student
ID, referrer and study reason. Each has อนุมัติ (approve; an optional override box takes a manual
student ID) and ไม่อนุมัติ (reject, which sets `status = 'rejected'`). Approval sets the level to
`จูฬตรี`. Without an override, it generates `student_no` as `{batch_no}{group_digit}{seq}`,
where `seq` is **always 2 digits** (`01`-`99`). Total length is therefore 4 digits for a
single-digit batch (e.g. รุ่น 7) and 5 digits for a two-digit batch (e.g. รุ่น 10).

- `batch_no` = the batch number as-is (`7`, `10`, `23`, ...).
- `group_digit` starts at a base value per prefix:
  - `0` = พระ
  - `1` = สิกขมานา / สามเณร / สามเณรี / แม่ชี
  - `2` = นาย / นาง / นางสาว / **อื่นๆ** (these four share one running count)
- Compute from `student_id_sequences.last_seq` (a running **count**, 0-based, per
  batch+group) as:
  - `group_digit = base_group_digit + floor(last_seq / 99)`
  - `seq = (last_seq mod 99) + 1`, zero-padded to 2 digits.

**Overflow / bucket rollover:** once the นาย/นาง/นางสาว/อื่นๆ group passes 99 students in a
batch, `group_digit` rolls from `2` to `3`, then `4`, `5`, ... up to `9` (e.g. รุ่น 7:
`7201`...`7299`, then `7301`...`7399`, `7401`...). This is safe because digits `3`-`9` are
otherwise unused. The พระ (`0`) and สิกขมานา group (`1`) groups do **not** have a defined
rollover — incrementing their digit would collide with the next real group's namespace — so
if either ever exceeds 99 students in a single batch, the admin must manually assign IDs
beyond that point (already supported via override + the `UNIQUE` constraint on
`student_no`). In practice this is expected only for the นาย/นาง/นางสาว/อื่นๆ group.

### Feature 3 — Reports
Restricted to admins with feature 3 permission (`admin/reports.php`). A toggle at the top
switches between two views (`?view=approved|rejected`, default `approved`):

**นักศึกษาที่อนุมัติแล้ว (approved)** — attendance report for either a single selected student or
all approved students at once:
- **Single student** — the same per-session grid and % complete shown after check-in
  (`includes/attendance.php`'s `getAttendanceSummary()`, reused as-is), plus the student's
  prefix, batch and level.
- **All students** (default) — one row per approved student: รหัสนักศึกษา, คำนำหน้า,
  ชื่อ-นามสกุล, รุ่น, ระดับชั้น, completed/conducted counts and % complete against their own
  current class instance; students whose level has no `class_instance` yet (schedule not
  generated) show `-`. Sorted by รหัสนักศึกษา ascending by default (numeric-aware: by length,
  then value, so `7201 < 10101`); every column header is clickable to toggle ascending/descending
  (`?sort=…&dir=asc|desc`, whitelisted). Stored columns sort in SQL; the computed attendance
  columns sort in PHP. The student dropdown uses the same order.

**ผู้สมัครที่ไม่ได้รับการอนุมัติ (rejected)** — every `status = 'rejected'` registration with
คำนำหน้า, ชื่อ-นามสกุล, รุ่น, อายุ, เบอร์โทรศัพท์, Line ID, ชื่อไลน์, ผู้แนะนำ, ประเทศ, จังหวัด,
อำเภอ/เขต, ตำบล/แขวง, รหัสไปรษณีย์, the choice answers, รหัสนักศึกษาเดิม, เหตุผลที่มาเรียน and
วันที่สมัคร (BE short date). Sortable by prefix/name/batch/date, newest first by default. Rejected rows have no
`student_no` or `current_class_level_id`, so this view queries without the `class_levels` join.

**Filters** — a รุ่น dropdown (both views) and a ระดับชั้น dropdown (current level; approved view
only), `?batch_id=` / `?level_id=`, default all. They narrow the summary table, the student
dropdown and the CSV export, and are kept across sorting and view switching (ระดับชั้น is dropped
on the rejected view). Changing a filter resets the student selection to all students.

Every view can be exported to CSV (`?export=csv`, carrying the current view, filters, sort and student
selection) — session-by-session for a single student, the summary table for all students, or the
rejected list. UTF-8 BOM-prefixed so Thai text renders correctly in Excel. Data rows go through
`csvSafe()` (`includes/input.php`), which prefixes `'` to any value starting with `= + - @`, so
applicant free text can't run as a spreadsheet formula.

Known limitation: MySQL's collation doesn't apply Thai leading-vowel ordering (e.g. แม่ชี sorts
after สามเณร instead of by its consonant ม). Proper Thai collation would need PHP's `intl`
extension, which the production VM doesn't have.

### Feature 4 — Promotion
Restricted to admins with feature 4 permission. Select approved students who passed the
exam and advance `current_class_level_id` to the next level per the fixed progression order
in `class_levels.sort_order`; every promotion is logged in `promotions`.

### Feature 6 — Import students from CSV
`admin/import_students.php`. Upload a CSV (≤ 2 MB, header row first; a template is downloadable
via `?template=1`) with the registration-form columns plus student ID, read **by position** in
this order (`IMPORT_COLUMNS` in `admin/import_students.php`): รหัสนักศึกษา, คำนำหน้า, ระบุคำนำหน้า,
ชื่อ-นามสกุล, อายุ, ที่อยู่, เบอร์โทรศัพท์, Line ID, ผู้แนะนำ, then the optional later additions
จังหวัด, รหัสไปรษณีย์, ชื่อไลน์, ทราบข่าวจากช่องทางใด, นักศึกษาเก่าหรือใหม่, รหัสนักศึกษาเดิม,
เหตุผลที่มาเรียน, ใช้ ZOOM เป็นหรือไม่, เข้าห้องเรียนแล้วหรือยัง, ประเทศ, อำเภอ/เขต, ตำบล/แขวง.
New columns are only ever appended, so files made from an older, shorter template still import.
A choice column holds one of its options or free text (stored as อื่นๆ + that text);
ทราบข่าวจากช่องทางใด may list several options separated by commas. A blank ประเทศ means Thailand
(stored as ประเทศไทย). The address parts are only format-checked (no postcode lookup).
UTF-8 (with or without BOM) and Windows-874 files are both accepted.
- Imported students are inserted directly as `approved` with the student ID from the file — no
  approval step.
- The **batch** comes from the student ID: every digit except the last 3 (the group digit and
  2-digit sequence, section 5). Batches are **not** created by the import: if any row's batch
  doesn't exist yet, the whole file is refused (nothing imported) with "แฟ้มข้อมูลปรากฎรุ่นนักศึกษาที่ยังไม่มีในระบบ
  กรุณาทำการสร้างรุ่นของนักศึกษาในระบบเสียก่อน" plus the missing batch numbers; the admin creates
  them in Feature 1 and re-uploads.
- The **starting class level** is chosen by the admin on the import screen and applies to every
  student in that upload.
- Rows are validated with the same rules as registration (section 4.1). A row is **skipped**, and
  listed with its row number and reason, if it fails validation, its student ID already exists (in
  the DB or earlier in the file), or its name already belongs to an approved/pending student.
  Valid rows are still imported.
- Each imported ID raises `student_id_sequences.last_seq` for its batch + group (inverting the
  section 5 digit math, `noteImportedStudentNo()`), so later auto-generated IDs don't collide.

### Feature 7 — Edit student details
`admin/students.php`. The admin enters a student ID **and** full name (both must match exactly),
then can edit every registration-form field (prefix, name, age, the address block of section 4.1,
phone, LINE name and ID, the choice questions, previous student ID, referrer and study reason) with
the same validation as registration. The Google-Form questions are optional here and the address is
only format-checked, so older students who lack them can still be saved. The address block opens on
the student's stored residence (blank or ประเทศไทย country means Thailand) and keeps a stored
จังหวัด/อำเภอ/ตำบล that isn't in the postcode list. Student ID, batch, level and status are not
editable here. A new name may not clash
with another approved/pending student, because check-in and lookup match on exact name.

### Feature 9 — Backup
Manual "สำรองข้อมูลตอนนี้" (backup now) action plus a scheduled job (Windows Task Scheduler / cron calling
`cron/backup.php`) that dumps the database to a timestamped, gzip-compressed `.sql.gz` file on
the server and emails it as an attachment to each backup recipient. Recipients are edited on the
backup page (comma-separated, each validated as an email) and stored in
`app_settings.backup_recipient_email`; until saved, `config.local.php['backup']['recipient_email']`
is used. The backup directory is configured in `config.local.php`; the schedule lives in the
cron/Task Scheduler entry. Restore with `gunzip -c <file>.sql.gz | mysql <db name>`.

## 6. Security

- **Rate limiting**, thresholds read from `config.local.php['rate_limit']`, sliding window per
  `(form_key, identifier)`:
  - `register`, `checkin`, `lookup` — keyed by client IP.
  - `admin_login` — keyed by client IP, catches one source spraying many accounts.
  - `admin_login_account` — keyed by username, catches one account attacked from many IPs
    (a purely IP-based limit can't stop a distributed brute force against a single account).
  - `otp_verify` — keyed by the pending admin ID, caps guesses against a valid login's OTP
    code independent of source IP.
- Passwords hashed with `password_hash()` (bcrypt). OTP codes are hashed at rest, single-use and
  short-lived, and only the newest code per account is valid.
- **Injection and XSS**: all queries that take input are prepared statements (`bind_param`). The only
  concatenated SQL is `(int)` casts, whitelisted sort keys/directions, or constant table names.
  All user data is output through `htmlspecialchars()`.
- **CSRF**: every POST handler calls `verifyCsrf()` (per-session 32-byte token, `hash_equals`),
  including logout.
- **Sessions**: the cookie is `HttpOnly`, `SameSite=Lax` and `Secure` over HTTPS, with
  `session.use_strict_mode` (`ensureSessionStarted()`). The session ID is regenerated at admin login.
  Admin sessions expire after `ADMIN_IDLE_TIMEOUT_SECONDS` (1 hour) idle.
- **CSV exports**: `csvSafe()` neutralises formula-leading values (CSV/Excel injection).
- **Mail**: the SMTP client aborts instead of authenticating if STARTTLS fails.
- **Deployment**: nginx serves an allowlist (`/`, the four public `.php` entry points,
  `/admin/*.php`, `/assets/`) and returns 404 for everything else, so config (incl. `.bak` copies),
  `includes/`, `cron/`, `scripts/`, `migrations/`, `backups/`, `logs/`, `.git` and the docs are
  unreachable. An earlier deny-list config missed `backups/`, so backups were downloadable by exact
  file name. Access logs showed no outside download before the switch (October 2026). `cron/` and
  `scripts/` also refuse non-CLI execution. See README "Deploying".
- Admin accounts: a disabled or deleted account is rejected at login and also logged out on
  its next request (`requireAdminLogin()` re-checks `is_active`). Resetting a password deletes the
  account's pending OTP codes. An admin can't disable or delete their own account, so at least one
  active admin with feature 0 always remains.
- Attribution: only promotions record the acting admin (`promotions.promoted_by`).
  `backup_runs.triggered_by` records manual vs. scheduled, not who. Approvals, schedule changes,
  CSV imports, student edits and admin-account changes aren't attributed. Worth adding an audit log
  if the project grows.

## 7. Folder structure

```
/
├── config.local.php.example
├── config.php
├── schema.sql           -> full schema for a fresh install (includes every migration)
├── migrations/          -> one-off ALTERs for existing databases, applied once in name order
├── index.php            -> landing page + registration form
├── register.php         -> registration POST handler (validation, insert as pending)
├── checkin.php          -> check-in / view attendance progress
├── lookup.php           -> student ID + registration status lookup
├── includes/
│   ├── db.php
│   ├── auth.php            (admin session + permission checks)
│   ├── csrf.php
│   ├── otp.php
│   ├── rate_limit.php
│   ├── mailer.php          (minimal SMTP client)
│   ├── student_id.php      (generateStudentNo(), see section 5)
│   ├── attendance.php      (getAttendanceSummary(), see section 4.2)
│   ├── backup.php          (runBackup())
│   ├── input.php           (whitespace cleanup + name validation for user input)
│   ├── student_validation.php (validateStudentFields(), shared by register/import/edit)
│   ├── student_form.php    (studentChoiceField(), addressFields() + their scripts)
│   ├── student_helpers.php (studentPrefix(), studentClassInstanceId())
│   └── date_helpers.php    (formatDateBEShort())
├── scripts/
│   ├── create_admin.php
│   └── normalize_whitespace.php  (one-off cleanup of rows stored before input.php)
├── admin/
│   ├── login.php
│   ├── verify_otp.php
│   ├── logout.php          (POST only)
│   ├── dashboard.php
│   ├── admins.php         (feature 0)
│   ├── classes.php        (feature 1)
│   ├── approvals.php      (feature 2)
│   ├── reports.php        (feature 3)
│   ├── promotions.php     (feature 4)
│   ├── import_students.php (feature 6)
│   ├── students.php       (feature 7)
│   └── backup.php         (feature 9)
├── cron/
│   ├── backup.php
│   └── clear_expired_otp.php
└── assets/
    ├── style.css
    ├── data/
    │   └── thai_postcodes.json (postcode → จังหวัด → อำเภอ → ตำบล, for the registration address)
    └── images/             (landingpage.jpg intake poster, qr-code7.jpg classroom QR, bottom_banner.jpg)
```

## 8. Confirmed decisions

1. **OTP delivery** — email.
2. **Student ID for batch_no ≥ 10** — sequence stays 2 digits (`01`-`99`); total length is
   4 digits for a single-digit batch, 5 for a two-digit batch (e.g. รุ่น 10 พระ → `10001`).
3. **"อื่นๆ" prefix group** — group digit `2`, same running count as นาย/นาง/นางสาว (e.g.
   `72xx`), with rollover to `73xx`, `74xx`, `75xx`, ... once a batch passes 99 in that
   group. See section 5.
4. **Check-in session selection** — the attendee selects the Class and Session ID themselves
   (dropdowns); the system does not auto-detect "today's session".
5. **Recurring schedule** — generating a schedule (e.g. every Mon & Thu) produces a concrete
   date for every session number; % complete is based on sessions up to the most recent
   past session's date, not a fixed "today" cutoff.
6. **Date display** — dates shown to users (class start dates, session dates/dropdowns) are
   formatted as a short Thai Buddhist Era date, e.g. `17 ก.ย. 69` (day, abbreviated Thai
   month, 2-digit BE year — Gregorian year + 543), with a weekday label where relevant.
   Underlying storage stays Gregorian ISO (`DATE` columns); conversion only happens at
   display time (`formatDateBEShort()` in `includes/date_helpers.php`).
7. **Registration landing page** — `index.php` shows a landing page first; the registration
   form only appears after the visitor clicks "ลงทะเบียน" (`?register=1`), rather than being
   shown immediately on first load.

## 9. Resolved decisions since initial design

- **Rate limiting on the lookup form** — the original requirement doc only named register
  and check-in explicitly; `lookup.php` is rate-limited the same way (`form_key = 'lookup'`),
  since it's also public and unauthenticated.
- **Expired OTP cleanup** — `cron/clear_expired_otp.php` purges expired `otp_codes` rows on a
  schedule (hourly in production), keeping the table small.
- **Per-account rate limiting on admin login and OTP verification** — the original design only
  called for rate limiting "per IP per time window" (section 6). In practice a single IP-based
  bucket on `admin/login.php` doesn't stop a botnet spreading guesses across many IPs at one
  account, and `admin/verify_otp.php` had no throttle at all, so a stolen/guessed password
  could be paired with unlimited OTP brute-forcing. Added two more buckets: `admin_login_account`
  (keyed by username) and `otp_verify` (keyed by the pending admin ID) — see section 6.
- **Feature 3 — Reports, added after initial design** — a new admin feature for attendance
  reporting (`admin/reports.php`), inserted at number 3. This pushed the original Feature 3
  (Promotion) to 4 and Feature 4 (Backup) to 5 — see section 5. Existing `admin_permissions`
  rows for those two features must be renumbered (4→5, then 3→4, in that order to avoid a
  primary-key collision on admins who already hold both) when deploying this change to an
  existing database; a fresh `schema.sql` install is unaffected since it seeds no permission
  rows itself.
- **Thai UI; light-blue public / orange admin theme** — every user-facing caption, message, CSV header and email is
  in Thai (`lang="th"`, Sarabun font). The ผู้ดูแลระบบ link on public pages sits apart at the right
  of the menu on an orange background (matching the admin theme); ออกจากระบบ on admin pages sits at the right in red.
  Admin pages (`<body class="admin">`) use an orange theme so staff can tell at a glance that
  they're in the backend.
- **Features 0, 6 and 7 added after initial design** — manage admins, CSV import and edit student. On an
  existing database, grant them to current admins with the one-off SQL in README.md.
- **Backup moved from feature 5 to feature 9** — feature 5 is now unused. On an existing database
  run `UPDATE admin_permissions SET feature = 9 WHERE feature = 5;` (no collision, since 9 was
  unused before).
- **Two-column dashboard** — the admin dashboard's feature cards are a fixed two-column grid
  (one column on phones) instead of an auto-fill grid.
- **Admin account management** — Feature 0 originally only created admins. It now also resets
  passwords, enables/disables accounts and deletes them (Feature 0, section 5). Deletion is refused
  for admins with promotion history, so disabling is the normal way to retire an account.
- **Whitespace normalization** — all free-text input is trimmed and internal whitespace (incl.
  non-breaking and zero-width spaces from Thai keyboards) collapsed, because check-in and lookup
  match `full_name` by exact equality. `scripts/normalize_whitespace.php` cleaned rows stored
  before this rule (it found none to change in production).
- **Stricter registration validation** — required fields expanded to prefix, name, age, address
  and phone, with format rules for age, phone and name (section 4.1).
- **Lookup shows registration status** — originally approved-only; now also tells pending and
  rejected applicants their status, to discourage duplicate registrations (section 4.3).
- **Check-in doubles as a progress view** — class and session became optional; leaving either
  out shows progress without recording attendance (section 4.2).
- **Rejected applicants in reports** — added as a second report view (section 5, Feature 3).
- **Only the current class is offered** — the check-in class dropdown lists only each batch's
  highest class level, and in Feature 1 the จัดการตารางเรียน link appears only for that level.
  Registration can be opened/closed only for the latest batch (enforced server-side too).
- **No check-in to future sessions; Asia/Bangkok time** — check-in used to accept any session,
  including future ones, which progress ignores (it counts only sessions already conducted), so
  students saw 0 / 0 despite having check-ins. The session dropdown now lists only sessions dated
  today or earlier, the server refuses future ones, and PHP's timezone is `Asia/Bangkok`
  (set in `includes/db.php`) so "today" rolls over at Thai midnight rather than 07:00 UTC.
- **Lookup shows ระดับชั้น** — an approved match also shows the student's current level.
- **CSV import no longer creates batches** — a file referencing a missing batch is refused
  whole, so batches are only ever created deliberately in Feature 1.
- **Report filters** — รุ่น and ระดับชั้น filters on the reports page (section 5, Feature 3).
- **Backups compressed and emailed** — backups are `.sql.gz` and attached to the email; the
  recipients (several allowed) are edited on the backup page and stored in the new
  `app_settings` table. On an existing database, create it with the one-off SQL in README.md.
- **Intake poster on the landing page** — while registration is open, the first visit in a
  session pops up the intake poster, and the success page shows the classroom QR code (section 4.1).
- **Security review hardening (2026-10)** — a code review found no SQL injection or XSS. It
  added: CSV formula neutralisation in report exports, registration errors carried in the session
  instead of `?error=` (which allowed crafted messages), hardened session cookies plus a 1-hour
  admin idle timeout, POST-only logout, retiring older OTP codes when a new one is issued, and
  STARTTLS fail-closed in the mailer (section 6). Deferred: ending other sessions when an admin's
  password is reset (needs a schema change), and encrypting emailed backups.
- **Registration form mirrors the Google Form (รุ่น 7)** — added LINE name, the four choice
  questions with "อื่นๆ (ระบุ)" free text, previous student ID and study reason
  (`migrations/2026-10-registration-fields.sql`). They're required on public registration only, so
  older and imported students stay valid. ทราบข่าวจากช่องทางใด later became a multi-select
  (`migrations/2026-10-heard-from-multi.sql`). นักศึกษาเก่าหรือใหม่ went from a dropdown to radio buttons.
- **Address by postcode, or abroad (2026-10)** — the province dropdown (77 provinces +
  อยู่ต่างประเทศ) was replaced by the address block in section 4.1: a Thailand/abroad choice,
  postcode-driven จังหวัด/อำเภอ/ตำบล dropdowns checked server-side, and free text abroad. New
  columns country, district and sub_district, with postal_code widened to 20 characters
  (`migrations/2026-10-address-country.sql`). That migration set country = ประเทศไทย on rows with
  a Thai province and cleared the old อยู่ต่างประเทศ province value. Captions are Thai only.
- **Generated admin passwords** — admin create/reset forms default to a browser-generated
  password (Feature 0, section 5) instead of asking the admin to invent one.
