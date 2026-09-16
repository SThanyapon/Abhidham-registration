# Abhidham Registration — Application Design

Source: `App requirement.docx`. This document translates that requirement into a concrete
data model, feature breakdown, and folder structure for the PHP + MySQL implementation.

## 1. Actors

- **Student (public, no login)** — registers, checks in to sessions, looks up their own student ID.
- **Admin/Staff (OTP login required)** — manages classes & schedules, approves enrollments,
  promotes students, and runs backups. Access to each of the 4 backend features is granted
  per-user (not all admins can do all 4 things).

## 2. Core domain concepts

- **รุ่น (Batch/Cohort)** — a numbered intake (e.g. รุ่น 7). Drives the leading digit of the
  student ID and which registration form is currently open.
- **Class / Level** — a study level. Fixed progression path:
  `จูฬตรี → จูฬโท → จูฬเอก → มัชฌิมตรี → มัชฌิมโท → มัชฌิมเอก → มหาตรี → มหาโท → มหาเอก`.
  New registrations always start at `จูฬตรี` (requirement #14).
- **Session** — one class meeting/lecture, generated from a recurring schedule
  (e.g. every Tue & Thu) or edited individually.
- **Enrollment** — a student's registration record for a รุ่น, starting as `pending`
  until an admin approves it.
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
    prefix VARCHAR(50) NOT NULL,           -- พระ, สิกขามานา, สามเณร, สามเณรี, แม่ชี, นาย, นาง, นางสาว, อื่นๆ
    prefix_other VARCHAR(100),             -- free text when prefix = "อื่นๆ"
    full_name VARCHAR(255) NOT NULL,       -- Name-Surname (Thai)
    age INT,
    address TEXT,
    phone VARCHAR(50) NOT NULL,
    line_id VARCHAR(100),
    reference_person VARCHAR(255),
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
-- for how it maps to {group_digit}{2-digit seq}.
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

-- Per-user feature access (features 1-4 from the requirement doc)
CREATE TABLE admin_permissions (
    admin_user_id INT NOT NULL REFERENCES admin_users(id),
    feature TINYINT NOT NULL,              -- 1=classes, 2=approvals, 3=promotions, 4=backup
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

-- Rate limiting (register, check-in, admin login) — sliding window per IP+form
CREATE TABLE rate_limit_hits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_key VARCHAR(50) NOT NULL,         -- 'register', 'checkin', 'admin_login'
    identifier VARCHAR(100) NOT NULL,      -- IP address (or IP+username for login)
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
```

## 4. Public features

### 4.1 Student registration (`index.php`, `register.php`)
`index.php` is a landing page — the form is not shown until the visitor clicks "Register"
(`?register=1`), so a first-time visit doesn't drop straight into a form. Fields: prefix
(dropdown incl. "อื่นๆ (ระบุ)" free-text), name-surname, age, address, phone, line ID,
reference person (optional). Always targets whichever batch currently has
`registration_open = TRUE` at level `จูฬตรี`. Inserted as `status = 'pending'`. Rate-limited.

### 4.2 Check-in (`checkin.php`)
Student enters name-surname + student ID + class, then **selects the ครั้งที่ (session)
themselves** from a dropdown of that class's sessions, shown as `dd/mm/yyyy` Buddhist Era
(no auto-detection by date). System:
1. Verifies name/ID/class match an approved student.
2. If already checked in for that session → show error.
3. Otherwise insert into `checkins`, then render a grid of all sessions so far: green =
   checked in, grey = missing.
4. `% complete = checkins / (count of sessions whose date <= the most recent past session's
   date)` — i.e. only sessions already conducted count toward the denominator, not future
   scheduled ones.
Rate-limited.

### 4.3 Student ID lookup (`lookup.php`)
Name + surname → returns `student_no` for approved students only.

## 5. Admin/backend features

OTP login: 6-digit code emailed to the admin's registered email, hashed and stored in
`otp_codes` with an expiry (e.g. 5 minutes), rate-limited per account. After OTP verification,
a session is created; every admin page checks `admin_permissions` for the relevant feature
number before allowing access.

### Feature 1 — Class management
- Open/close registration per batch (`batches.registration_open`).
- Create a `class_instance` (batch + level + start date).
- Generate a recurring schedule: given number of sessions, start date, and days-of-week
  (e.g. Tue+Thu), bulk-insert `sessions` rows numbered sequentially.
- Edit an individual session (reschedule date, cancel) without regenerating the whole series.
  Dates are displayed/edited as `dd/mm/yyyy` Buddhist Era with a weekday label; since a native
  HTML date input can't render Buddhist Era, the session date field is a BE-formatted text
  input that's parsed back to a Gregorian ISO date server-side (`includes/date_helpers.php`
  provides the shared `formatDateBE()` used here and in check-in).

### Feature 2 — Approve enrollment
List `pending` students; on approval, generate `student_no` as `{batch_no}{group_digit}{seq}`,
where `seq` is **always 2 digits** (`01`-`99`). Total length is therefore 4 digits for a
single-digit batch (e.g. รุ่น 7) and 5 digits for a two-digit batch (e.g. รุ่น 10).

- `batch_no` = the batch number as-is (`7`, `10`, `23`, ...).
- `group_digit` starts at a base value per prefix:
  - `0` = พระ
  - `1` = สิกขามานา / สามเณร / สามเณรี / แม่ชี
  - `2` = นาย / นาง / นางสาว / **อื่นๆ** (these four share one running count)
- Compute from `student_id_sequences.last_seq` (a running **count**, 0-based, per
  batch+group) as:
  - `group_digit = base_group_digit + floor(last_seq / 99)`
  - `seq = (last_seq mod 99) + 1`, zero-padded to 2 digits.

**Overflow / bucket rollover:** once the นาย/นาง/นางสาว/อื่นๆ group passes 99 students in a
batch, `group_digit` rolls from `2` to `3`, then `4`, `5`, ... up to `9` (e.g. รุ่น 7:
`7201`...`7299`, then `7301`...`7399`, `7401`...). This is safe because digits `3`-`9` are
otherwise unused. The พระ (`0`) and สิกขามานา group (`1`) groups do **not** have a defined
rollover — incrementing their digit would collide with the next real group's namespace — so
if either ever exceeds 99 students in a single batch, the admin must manually assign IDs
beyond that point (already supported via override + the `UNIQUE` constraint on
`student_no`). In practice this is expected only for the นาย/นาง/นางสาว/อื่นๆ group.

### Feature 3 — Promotion
Restricted to admins with feature 3 permission. Select approved students who passed the
exam and advance `current_class_level_id` to the next level per the fixed progression order
in `class_levels.sort_order`; every promotion is logged in `promotions`.

### Feature 4 — Backup
Manual "Backup now" action plus a scheduled job (Windows Task Scheduler / cron calling
`cron/backup.php`) that dumps the database to a timestamped `.sql` text file and emails it
to a configured recipient. Schedule and recipient are configurable in `config.local.php`.

## 6. Security

- **OTP + rate limiting on admin login**, and **rate limiting on register/check-in**, all with
  thresholds read from `config.local.php` (e.g. max attempts per IP per time window).
- Passwords hashed with `password_hash()` (bcrypt); OTP codes hashed at rest, single-use,
  short-lived.
- All admin actions (approve, promote, schedule change, backup) should be attributable to
  the acting `admin_users.id` — already covered by `promoted_by` / `backup_runs.triggered_by`
  and worth extending to an audit log if the project grows.

## 7. Proposed folder structure

```
/
├── config.local.php.example
├── config.php
├── schema.sql
├── index.php            -> student registration
├── checkin.php
├── lookup.php
├── includes/
│   ├── db.php
│   ├── auth.php          (admin session + permission checks)
│   ├── otp.php
│   ├── rate_limit.php
│   └── date_helpers.php  (shared formatDateBE(), used by check-in and class management)
├── admin/
│   ├── login.php
│   ├── verify_otp.php
│   ├── dashboard.php
│   ├── classes.php        (feature 1)
│   ├── approvals.php      (feature 2)
│   ├── promotions.php     (feature 3)
│   └── backup.php         (feature 4)
├── cron/
│   └── backup.php
└── assets/
    └── style.css
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
   formatted `dd/mm/yyyy` in the Buddhist Era (Gregorian year + 543), with a weekday label
   where relevant. Underlying storage stays Gregorian ISO (`DATE` columns); conversion only
   happens at display/input time.
7. **Registration landing page** — `index.php` shows a landing page first; the registration
   form only appears after the visitor clicks "Register" (`?register=1`), rather than being
   shown immediately on first load.

## 9. Remaining open question

- **Rate limiting on the lookup form** — the doc only names register and check-in
  explicitly; recommend limiting lookup too since it's also public and unauthenticated.
  Will apply the same configurable limiter unless you say otherwise.
