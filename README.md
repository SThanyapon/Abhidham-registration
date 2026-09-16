# Abhidham Registration

A simple PHP + MySQL registration form.

## Setup

1. Copy `config.local.php.example` to `config.local.php` and fill in your MySQL credentials.
2. Import the schema:
   ```
   mysql -u root -p < schema.sql
   ```
3. Run a local PHP server:
   ```
   php -S localhost:8000
   ```
4. Open `http://localhost:8000` to see the registration form, and `http://localhost:8000/admin.php` to view submissions.
