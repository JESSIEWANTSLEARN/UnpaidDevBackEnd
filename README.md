# Walang BrownOut — Backend

Backend API and legacy Laravel interface for the **Walang BrownOut** inventory and e-commerce management system.

This repository contains the Laravel backend responsible for authentication, customer orders, inventory operations, payments, wallet transactions, notifications, support features, administrative workflows, and database access.

## System Architecture

```text
React / Vite Frontend
        ↓
Laravel REST API
        ↓
MySQL Database
```

### Production

**Main React Frontend**  
https://unpaiddevfrontend.onrender.com/

**Laravel Backend / Legacy Interface**  
https://unpaiddev.onrender.com/

The standalone React frontend is the primary current user interface.

The Laravel-hosted interface is retained as the project's legacy/reference interface while the backend continues to provide the API and business logic used by the React application.

---

## Technology Stack

### Backend
- Laravel
- PHP
- REST API
- Laravel authentication/session handling
- Laravel migrations
- Service-based business logic

### Database
- MySQL
- Local development: XAMPP MySQL
- Production: Aiven MySQL

### Deployment
- Render

### Source Control
- Git
- GitHub

---

## Main Backend Features

The backend currently supports:

- User authentication
- Role-based system access
- Customer accounts
- Product management
- Categories and suppliers
- Inventory management
- Purchase orders
- Customer orders
- Order processing
- Payment workflows
- Customer wallet
- Wallet top-up
- Wallet purchase transactions
- Wallet refunds and reversals
- Order cancellation
- Stock reservation and release
- Notifications
- Newsletter subscription
- Customer support messaging
- Returns workflow
- Data import tools
- Administrative and staff workflows
- Audit-related operations

---

## Wallet System

Customers can maintain an internal wallet.

Supported wallet transaction types include:

```text
TOP_UP
PURCHASE
REFUND
REVERSAL
ADJUSTMENT
```

Important wallet rules:

- One wallet per customer
- Balance changes are handled by the backend
- Wallet purchases use database transactions
- Wallet rows are locked during balance-changing operations
- Duplicate transaction references are protected
- Refunds create separate REFUND transactions
- Original PURCHASE history is preserved
- Wallet checkout is handled atomically with order creation

Demo wallet top-up methods currently include:

```text
GCASH
BANK_TRANSFER
```

---

## Repository Structure

```text
UnpaidDevBackEnd/
│
├── framework/
│   ├── app/
│   │   ├── Http/
│   │   │   └── Controllers/
│   │   ├── Models/
│   │   └── Services/
│   │
│   ├── config/
│   ├── database/
│   │   └── migrations/
│   ├── resources/
│   ├── routes/
│   ├── storage/
│   ├── tests/
│   ├── composer.json
│   └── artisan
│
├── README.md
└── .gitignore
```

### Important folders

`app/`  
Main Laravel application logic.

`app/Http/Controllers/`  
Receives requests and coordinates application actions.

`app/Services/`  
Contains reusable business logic such as wallet, orders, notifications, and other workflows.

`routes/`  
Contains API and web route definitions.

`database/migrations/`  
Contains database schema changes.

`config/`  
Laravel configuration.

`resources/`  
Contains the retained Laravel interface and related assets.

`storage/`  
Laravel runtime files and logs.

`tests/`  
Automated backend tests.

---

## Local Development

### Requirements

Install:

- PHP
- Composer
- XAMPP / MySQL
- Git

### Project Location

Example:

```powershell
cd "C:\xampp\htdocs\WalangBrownoutFolderFiles\UnpaidDev\framework"
```

### Install PHP Dependencies

```powershell
composer install
```

### Configure Environment

Create/configure the Laravel `.env` file.

Typical local database configuration:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=WalangBrownout
DB_USERNAME=root
DB_PASSWORD=
```

Do not commit `.env` files containing private credentials.

---

## Start the Backend

From the Laravel `framework` directory:

```powershell
php artisan optimize:clear
php artisan serve
```

Default local address:

```text
http://127.0.0.1:8000
```

Keep the terminal running while using the application.

---

## Database

Make sure MySQL is running.

Check the MySQL port:

```powershell
Test-NetConnection 127.0.0.1 -Port 3306
```

Check migration status:

```powershell
php artisan migrate:status
```

Do not use destructive database commands such as:

```powershell
php artisan migrate:fresh
```

unless you intentionally want to recreate the local database.

Production migrations must be handled carefully because the production database may already contain manually synchronized schema changes.

---

## Backend Health Checks

Clear Laravel caches:

```powershell
php artisan optimize:clear
```

Check routes:

```powershell
php artisan route:list
```

Run Laravel tests:

```powershell
php artisan test
```

Validate Composer configuration:

```powershell
composer validate --no-check-publish
```

Check dependency vulnerabilities:

```powershell
composer audit
```

### PHP Syntax Check

Individual file:

```powershell
php -l app\Http\Controllers\SomeController.php
```

Project source scan:

```powershell
$folders = @(
    "app",
    "routes",
    "config",
    "database"
)

$failed = $false

foreach ($folder in $folders) {
    Get-ChildItem $folder -Recurse -File -Filter *.php | ForEach-Object {
        php -l $_.FullName

        if ($LASTEXITCODE -ne 0) {
            $failed = $true
        }
    }
}

if ($failed) {
    Write-Host "`nPHP SYNTAX SC
