<<<<<<< HEAD
# HanapBahay Platform

Property listing and rental management system with marketplace listings, ML-powered recommendations, in-app chat, payment uploads, and administrative tooling. This repository contains the PHP codebase, database schema, and support scripts required to deploy the latest Ethos group build.

## Features at a Glance
- Owner & tenant dashboards with listing CRUD (`DashboardUO.php`, `DashboardT.php`, `Add_Listing.php`)
- Admin analytics for pricing, transactions, verification, and ML forecasts (`admin_*` files)
- Real-time chat and notification experience (`start_chat.php`, `includes/chat/`)
- Multi-method reservation payments with QR uploads and receipt validation (`setup_payment.php`, `submit_booking.php`)
- Machine learning microservice hooks for pricing and recommendations (`ml_service/`, `ping_ml.php`)
- Deployment helpers, rollbacks, and database migration scripts (`database_migration.php`, `ROLLBACK_PROCEDURES.md`)

## Tech Stack
- PHP 8+ (tested on XAMPP)
- MySQL 5.7+/MariaDB
- Apache with `mod_rewrite`
- Composer (for `phpmailer/phpmailer`)
- Optional: Python 3.10+ for ML notebooks/services

## Repository Layout
| Path | Purpose |
| --- | --- |
| `dbhanapbahay.sql` | Base database schema & seed data |
| `add_*`/`database_*` SQL & PHP files | Incremental migrations/scripts |
| `includes/`, `api/`, `public/` | Core app classes, REST endpoints, public assets |
| `uploads/` | Runtime storage for images, QR codes, receipts (create folders manually) |
| `ml_service/` | Flask-based helpers for price prediction |
| `*.md` files | Deployment, fixes, rollback, and quick-start guides |

## Prerequisites
1. Install [XAMPP](https://www.apachefriends.org/) or equivalent stack with PHP 8+ and MySQL.
2. Install Composer globally (`composer --version` to verify).
3. Ensure Apache `mod_rewrite` is enabled for clean URLs.
4. Python 3.10+ (optional) if you intend to run `ml_service`.

## Installation & Setup
1. **Clone / copy repository**
   ```bash
   git clone https://github.com/<your-org>/hanapbahay.git
   cd hanapbahay
   composer install   # installs PHP dependencies (PHPMailer)
   ```
   Place the folder inside `htdocs` (e.g., `C:\xampp\htdocs\public_html`).

2. **Configure environment / environment variables**
   - Duplicate `config_keys_secure.php` to `config_keys.php` if needed and update:
     - Google Maps API key
     - ML service URL (`ML_BASE`) and key (`ML_KEY`)
     - SMTP username, password, and from-address
   - Update database credentials in `mysql_connect.php` or `.env`-equivalent to match your MySQL instance.
   - Define `HANAPBAHAY_SECURE` in any entry script before loading secure keys (already done in `app_config.php`).

3. **Database**
   - Create a database named `dbhanapbahay`.
   - Import the base dump:
     ```bash
     mysql -u root -p dbhanapbahay < dbhanapbahay.sql
     ```
   - Apply any incremental migrations relevant to your deployment (`add_payment_fields.sql`, `add_property_photos.sql`, `database_updates.php`, etc.). See `QUICK_START.md`, `DEPLOYMENT_CHECKLIST.md`, and `ROLLBACK_PROCEDURES.md` for details.

4. **File storage**
   Create the folders for runtime uploads (if they do not exist):
   ```
   public_html/uploads/property_photos/
   public_html/uploads/qr_codes/
   public_html/uploads/receipts/
   public_html/uploads/profile_images/
   ```
   Ensure php/apache user has write permissions.

5. **Run / serve the application (no build step)**
   - Start Apache & MySQL in XAMPP.
   - Visit `http://localhost/public_html/` for the landing page.
   - Clean URLs such as `http://localhost/public_html/browse_listings` should resolve via `.htaccess`.

6. **ML microservice (optional, for full ML features)**
   - Activate Python virtual environment inside `ml_service/`.
   - Install requirements listed in that directory and run the Flask app.
   - Update `ML_BASE` in `config_keys.php` to point to your service URL.

## Demo Accounts / Credentials for Reviewers

For panel review, you can use the following example test accounts (update these to match what you actually seed in `dbhanapbahay.sql`):
- **Admin login**: `admin@example.com` / `admin123`
- **Owner login**: `owner@example.com` / `owner123`
- **Tenant login**: `tenant@example.com` / `tenant123`

If your actual credentials differ, update this section before submission so reviewers can log in without needing to inspect the database directly.

## Testing Checklist
- Verify login/registration flows for tenants & owners (`LoginModule.php`, `register_process.php`).
- Submit a new listing and ensure photos upload successfully (`Add_Listing.php`, `uploads/property_photos`).
- Run payment setup and tenant booking as outlined in `QUICK_START.md`.
- Send chat messages between tenant/owner and confirm Pusher or polling behavior.
- Visit admin dashboards (e.g., `admin_listings.php`, `admin_transactions.php`) to confirm analytics render.

## Deployment Notes
- Review `DEPLOYMENT_CHECKLIST.md`, `LIVE_SITE_IMPLEMENTATION.md`, and `ROLLBACK_PROCEDURES.md` before pushing to production.
- For production SMTP, replace placeholder Gmail credentials with an app password or transactional email provider.
- Keep `config_keys.php`, `config_keys_secure.php`, and any `.env`-style files out of version control; share them securely with the deployment team.

## Support Docs
- `QUICK_START.md`: five-minute checklist for payments/chat additions.
- `FIXES_README.md`: catalog of hotfixes and the files they touch.
- `IMPROVEMENTS_SUMMARY.md`: overview of enhancement iterations.
- `SECURITY_FIX_REJECTED_LISTINGS.md`: rationale behind recent security updates.

---

For questions or onboarding help, include reproduction steps, screenshots, and relevant log excerpts (`php-error.log`, `admin_listings_error.log`) when filing issues. With this README plus the existing SQL dump and source code, the GitHub submission tab requirements are satisfied.
=======
# HanapBahay
>>>>>>> ccfeabd93c7053ade978b6e9e263e3980ce52da5
