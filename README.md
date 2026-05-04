# Loan Management System

PHP + MySQL loan management system for small lending businesses.

## Features
- Dark dashboard UI with sidebar + top bar
- Customer management
- Loan creation with auto installment count from timeframe
- Collection page with due list, overdue visibility, and record form
- Collection history page
- Auto-refresh (AJAX polling) for key dashboard/collection sections
- Multi-user login with roles:
  - `superadmin`
  - `admin`
  - `collector`

## Role rules
- Only one `superadmin` allowed.
- `superadmin` can add/delete users (except deleting superadmin itself).
- `admin` can add/delete users, but cannot delete superadmin.
- `collector` cannot add/delete users.

## Tech stack
- HTML
- CSS
- JavaScript
- PHP 8+
- MySQL

## Setup
1. Create database tables:
   - Open `db/schema.sql`
   - Run it in phpMyAdmin or MySQL CLI
2. Configure DB connection in `config/app.php` if needed.
3. Start Apache + MySQL in XAMPP.
4. Open:
   - `http://localhost/Loan-Management/`
5. If this is first startup and no superadmin exists:
   - You will be redirected to `setup_superadmin.php`
   - Create the first superadmin account.

## Existing installations migration
- If you already have data from old single-user build, run:
  - `db/migration_multi_user.sql`
- Then login with the promoted superadmin account.

## Next phase ideas
- Edit/delete customers and loans
- Print receipt after collection
- Reports by date range
- Penalty/late-fee rules
