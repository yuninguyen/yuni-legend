# 💰 RebateOps - Cashback Management System

A powerful Laravel-based system for managing cashback/rebate accounts with real-time Google Sheets synchronization.

![Laravel](https://img.shields.io/badge/Laravel-12.0-FF2D20?style=flat&logo=laravel)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat&logo=php)
![Filament](https://img.shields.io/badge/Filament-3.2-FFAA00?style=flat)

---

## 📋 Table of Contents

- [Features](#-features)
- [Tech Stack](#-tech-stack)
- [Requirements](#-requirements)
- [Installation](#-installation)
- [Google Sheets Setup](#-google-sheets-setup)
- [Usage](#-usage)
- [Project Structure](#-project-structure)
- [Contributing](#-contributing)
- [License](#-license)

---

## ✨ Features

### 🎯 Core Features
- **Account Management** - Manage multiple cashback accounts across different platforms
- **Payout Tracking** - Track withdrawals and currency liquidations
- **Rebate Monitoring** - Monitor and analyze rebate transactions
- **Google Sheets Sync** - Real-time bi-directional synchronization
- **Activity Logging** - Complete audit trail of all changes
- **Multi-User Support** - Assign accounts to different holders

### 🚀 Advanced Features
- **Automated Balance Calculation** - Automatic balance updates on payout completion
- **Parent-Child Transactions** - Link liquidations to original withdrawals
- **Bulk Operations** - Import/export data in bulk
- **Custom Formatting** - Automatic Google Sheets formatting (freeze, colors, styling)
- **Background Jobs** - Queue-based sync for performance

---

## 🛠️ Tech Stack

| Technology | Version | Purpose |
|------------|---------|---------|
| **Laravel** | 12.0 | PHP Framework |
| **Filament** | 3.2 | Admin Panel |
| **Google Sheets API** | 2.19 | Sheets Integration |
| **Spatie Activity Log** | 4.12 | Activity Tracking |
| **OpenSpout** | 4.32 | Excel/CSV Processing |
| **SQLite** | Default | Database (Dev) |

---

## 📦 Requirements

- **PHP** >= 8.2
- **Composer** (latest)
- **Node.js** >= 18.x & NPM
- **SQLite** (development) or **MySQL/PostgreSQL** (production)
- **Git**

---

## 🚀 Installation

### Step 1: Clone Repository

```bash
git clone https://github.com/yuninguyen/RebateOps.git
cd RebateOps
```

### Step 2: Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install JavaScript dependencies
npm install
```

### Step 3: Environment Setup

```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Create SQLite database
touch database/database.sqlite

# Run migrations
php artisan migrate
```

### Step 4: Create Admin User

```bash
php artisan make:filament-user
```

**Enter the following information:**
- Name: `Admin`
- Email: `admin@example.com`
- Password: `your-secure-password`

---

## 🔑 Google Sheets Setup

### Step 1: Create Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (e.g., "RebateOps")
3. Enable **Google Sheets API**:
   - Navigate to "APIs & Services" → "Library"
   - Search for "Google Sheets API"
   - Click "Enable"

### Step 2: Create Service Account

1. Go to "IAM & Admin" → "Service Accounts"
2. Click "Create Service Account"
3. Enter details:
   - Name: `rebate-ops-service`
   - Description: `Service account for RebateOps Google Sheets integration`
4. Click "Create and Continue"
5. Skip the optional steps and click "Done"

### Step 3: Generate Credentials

1. Click on the newly created service account
2. Go to "Keys" tab
3. Click "Add Key" → "Create new key"
4. Select "JSON" format
5. Download the JSON file
6. Rename it to `google-auth.json`
7. Move it to: `storage/app/google-auth.json`

**Important:** The file structure should look like `google-auth.json.example`

### Step 4: Share Google Sheet

1. Open your Google Sheet
2. Copy the **service account email** from `google-auth.json`:
   ```
   client_email: "your-service-account@your-project.iam.gserviceaccount.com"
   ```
3. Click "Share" button in Google Sheets
4. Paste the service account email
5. Set permission to **Editor**
6. Uncheck "Notify people"
7. Click "Share"

### Step 5: Configure Application

1. Open `.env` file
2. Add your Google Spreadsheet ID:
   ```env
   GOOGLE_SPREADSHEET_ID=your_spreadsheet_id_here
   ```
   
   **How to find Spreadsheet ID:**
   ```
   URL: https://docs.google.com/spreadsheets/d/[SPREADSHEET_ID]/edit
   Example: 1ChEJ3RqMAVWOPyX7ibSOoc_quMiVDBK6A7rFCqP0Ig4
   ```

3. Verify the path (optional):
   ```env
   GOOGLE_SERVICE_ACCOUNT_PATH=storage/app/google-auth.json
   ```

---

## 💻 Usage

### Development Mode

Run all services with a single command:

```bash
composer run dev
```

This starts:
- ✅ Web server (http://localhost:8000)
- ✅ Queue worker (background jobs)
- ✅ Log viewer (Laravel Pail)
- ✅ Vite dev server (hot reload)

### Individual Services

Or run services separately:

```bash
# Web server
php artisan serve

# Queue worker (for Google Sheets sync)
php artisan queue:work

# Vite (frontend assets)
npm run dev
```

### Accessing the Application

- **Homepage:** http://localhost:8000
- **Admin Panel:** http://localhost:8000/admin
  - Email: `admin@example.com`
  - Password: (the password you created)

---

## 📁 Project Structure

```
RebateOps/
├── app/
│   ├── Filament/           # Admin panel resources
│   │   ├── Resources/      # CRUD resources
│   │   ├── Widgets/        # Dashboard widgets
│   │   └── Pages/          # Custom pages
│   ├── Models/             # Eloquent models
│   │   ├── Account.php
│   │   ├── PayoutLog.php
│   │   ├── RebateTracker.php
│   │   └── ...
│   ├── Services/           # Business logic
│   │   └── GoogleSheetService.php
│   ├── Jobs/               # Background jobs
│   │   └── SyncGoogleSheetJob.php
│   └── Observers/          # Model observers
│       ├── AccountObserver.php
│       └── PayoutLogObserver.php
├── database/
│   ├── migrations/         # Database schema
│   └── database.sqlite     # SQLite database
├── storage/
│   └── app/
│       ├── google-auth.json         # Your credentials (gitignored)
│       └── google-auth.json.example # Template
└── config/
    └── services.php        # Third-party service configs
```

---

## 🔧 Configuration

### Database

**Development (Default):**
```env
DB_CONNECTION=sqlite
```

**Production (Recommended):**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rebateops
DB_USERNAME=root
DB_PASSWORD=your_password
```

Then run:
```bash
php artisan migrate:fresh
```

### Queue

For background job processing:

```env
QUEUE_CONNECTION=database
```

Make sure queue worker is running:
```bash
php artisan queue:work
```

---

## 📊 Key Models & Relationships

### Account
- **Purpose:** Manage cashback platform accounts
- **Relations:** 
  - Belongs to `User` (holder)
  - Belongs to `Email`
  - Has many `RebateTracker`

### PayoutLog
- **Purpose:** Track withdrawals and liquidations
- **Features:**
  - Automatic balance calculation
  - Parent-child relationship (withdrawal → liquidation)
  - Fee and boost calculation
- **Relations:**
  - Belongs to `User`
  - Belongs to `Account`
  - Belongs to `PayoutMethod`

### RebateTracker
- **Purpose:** Track rebate transactions
- **Relations:**
  - Belongs to `Account`

---

## 🔄 Google Sheets Sync

### Automatic Sync

Data automatically syncs to Google Sheets when:
- Creating new records
- Updating existing records
- Deleting records
- Bulk operations

### Manual Sync

Trigger sync manually via Filament:
1. Go to any resource (Accounts, Payouts, etc.)
2. Select records
3. Click "Sync to Google Sheets" bulk action

### Sync Features

- ✅ **Upsert Logic** - Updates existing, adds new
- ✅ **Batch Operations** - Multiple records at once
- ✅ **Error Handling** - Detailed logging
- ✅ **Automatic Formatting** - Headers, colors, freeze panes

---

## 🧪 Testing

```bash
# Run all tests
php artisan test

# Run specific test
php artisan test --filter=AccountTest

# With coverage
php artisan test --coverage
```

---

## 🛡️ Security

- ✅ **Environment Variables** - Sensitive data in `.env`
- ✅ **Service Account** - Google credentials secured
- ✅ **Activity Logging** - Full audit trail
- ✅ **CSRF Protection** - Laravel built-in
- ✅ **SQL Injection Prevention** - Eloquent ORM

**Important:** Never commit:
- `.env` file
- `google-auth.json`
- `database/database.sqlite`

---

## 🐛 Troubleshooting

### Google Sheets API Error

```bash
Error: "Google Spreadsheet ID is not configured"
```

**Solution:**
1. Check `.env` file has `GOOGLE_SPREADSHEET_ID`
2. Make sure it's not empty
3. Restart server: `php artisan serve`

---

### Service Account File Not Found

```bash
Error: "Google service account file not found"
```

**Solution:**
1. Verify file exists: `ls storage/app/google-auth.json`
2. Check file permissions: `chmod 644 storage/app/google-auth.json`
3. Verify path in `.env`: `GOOGLE_SERVICE_ACCOUNT_PATH`

---

### Permission Denied on Google Sheets

```bash
Error: "The caller does not have permission"
```

**Solution:**
1. Open Google Sheet
2. Click "Share"
3. Add service account email
4. Set permission to "Editor"
5. Save

---

### Queue Not Processing

```bash
# Check if queue worker is running
ps aux | grep queue:work

# Start queue worker
php artisan queue:work

# Clear failed jobs
php artisan queue:flush
```

---

## 📚 Common Commands

```bash
# Clear all caches
php artisan optimize:clear

# Run migrations
php artisan migrate

# Rollback migrations
php artisan migrate:rollback

# Seed database
php artisan db:seed

# Create new Filament resource
php artisan make:filament-resource Product

# List all routes
php artisan route:list

# Run code style fixer
./vendor/bin/pint
```

---

## 🤝 Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

---

## 📝 License

This project is licensed under the MIT License.

---

## 📞 Support & Resources

### Documentation
- [Laravel Docs](https://laravel.com/docs/12.x)
- [Filament Docs](https://filamentphp.com/docs)
- [Google Sheets API](https://developers.google.com/sheets/api)

### Communities
- [Laravel Discord](https://discord.gg/laravel)
- [Filament Discord](https://discord.gg/filamentphp)
- [Laravel Vietnam](https://www.facebook.com/groups/vietnam.laravel/)

---

## 🌟 Roadmap

### Version 1.0 (Current)
- ✅ Account Management
- ✅ Payout Tracking
- ✅ Google Sheets Sync
- ✅ Activity Logging

### Version 2.0 (Planned)
- [ ] API Endpoints (RESTful)
- [ ] Advanced Analytics Dashboard
- [ ] Multi-currency Support
- [ ] Email Notifications
- [ ] Mobile App (React Native)

---

## 👨‍💻 Author

**Yuni Nguyen**
- GitHub: [@yuninguyen](https://github.com/yuninguyen)

---

## 🙏 Acknowledgments

- [Laravel](https://laravel.com) - The PHP Framework
- [Filament](https://filamentphp.com) - The Admin Panel
- [Spatie](https://spatie.be) - Laravel Packages
- [Google](https://developers.google.com) - Google Sheets API

---

<p align="center">Made with ❤️ by Yuni Nguyen</p>
