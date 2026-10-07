# EdTech Fellowship Site

PHP application for the Mastercard Foundation EdTech Fellowship, including the public website, venture workspace, and staff administration tools.

## Features

- Public programme information, cohorts, FAQs, events, blogs, and newsletters.
- Venture profiles, teams, documents, reports, resources, mentorship, and grant tracking.
- Staff administration and role-based access controls.
- Shared responsive styles, mobile navigation, and optimized image assets.
- CSRF protection for forms and AJAX, authorization-controlled confidential downloads, and secure session settings.
- Authenticated encryption for SMTP credentials, Google Calendar tokens, and backup files.

## Requirements

- PHP 8.2 or newer with the extensions required by Composer, including OpenSSL, GD, MySQLi, DOM, mbstring, and ZIP.
- MySQL or MariaDB and an existing application database schema.
- Composer.
- Apache 2.4 with URL rewriting and headers enabled, or an equivalent server configuration.
- A valid HTTPS certificate for production.

## Installation

```bash
git clone https://github.com/Engwaksken/edtech-site.git
cd edtech-site
composer install --no-dev --optimize-autoloader
```

Configure the following in the PHP/server process environment:

| Variable | Purpose |
| --- | --- |
| `DB_HOST` | Database hostname; defaults to `localhost`. |
| `DB_PORT` | Database port; defaults to `3306`. |
| `DB_USER` | Database username. |
| `DB_PASS` | Database password. |
| `DB_NAME` | Application database name. |
| `DB_SSL_CA` | Trusted CA certificate path; required for a remote database connection. |
| `SITE_URL` | Public HTTPS base URL. |
| `APP_ENCRYPTION_KEY` | Base64-encoded 32-byte encryption key. |

`.env.example` documents these variables. PHP does **not** automatically load a `.env` file in this application; set the variables through your hosting configuration or process environment.

The repository does not contain a complete database dump or customer uploads. Restore the application schema/data and uploads from an authorized private backup. Reviewed incremental SQL migrations are kept under `admin/database/` and should be applied to the corresponding existing schema after taking a backup.

The root `.htaccess` currently enforces `https://www.edtech.hivecolab.com`. Review the canonical hostname when deploying to a different domain. Preserve the confidential-upload rewrite and sensitive-file denial rules when using a different web server.

## Secret encryption and backups

Generate a permanent key:

```bash
php bin/secure-storage.php generate-key
```

Store the generated value as `APP_ENCRYPTION_KEY` outside the repository and document root. Keep a protected copy separate from database and file backups; losing it prevents decryption of encrypted secrets and backups.

With the database and key configured, encrypt existing SMTP and Google Calendar secret values:

```bash
php bin/secure-storage.php migrate-secrets
```

The migration validates existing encrypted records and can be rerun. New Google Calendar token writes are encrypted automatically.

Encrypt and restore backup files using private paths outside the public document root:

```bash
php bin/secure-storage.php backup-encrypt /private/backup.sql /private/backup.edenc
php bin/secure-storage.php backup-decrypt /private/backup.edenc /private/restored.sql
```

Output files must not already exist. Backups are processed in authenticated chunks, and an incomplete or tampered restore is not published as a completed output file. Protect any plaintext source or restored dump separately.

Full database and upload-volume encryption is a separate hosting/storage configuration. Configure that with your hosting provider alongside encrypted backups.

## Verification

```bash
php tests/security-check.php
php tests/audit-check.php
composer audit --locked --no-interaction
composer check-platform-reqs --no-interaction
node --check assets/js/security.js
node --check assets/js/script.js
```

The optional staging and browser checks in `tests/staging-check.php` and `tests/browser-check.js` use synthetic data and workstation-specific Windows/XAMPP paths. Adjust those paths for your environment. They expect an isolated MariaDB instance at `127.0.0.1:13317`, use dedicated test ports, and must not be pointed at a production database.

## Image assets

Original shared images and optimized WebP copies are included. Rebuild optimized copies with:

```bash
php bin/optimize-assets.php
```

## Repository contents

- Root PHP files: public and venture pages.
- `admin/`: staff pages, handlers, and reviewed incremental SQL migrations.
- `includes/`: configuration, authentication, security, storage, and other shared helpers.
- `assets/`: shared styles, scripts, and public images.
- `bin/`: CLI-only maintenance utilities.
- `tests/`: syntax, security, staging, and browser verification tools.

Uploaded data, `vendor/`, logs, local credentials, database dumps, and hosting validation files are excluded from Git. The protective `uploads/.htaccess` is tracked and must be deployed. Install dependencies from `composer.lock` and manage private data separately during deployment.
