# Cardie ID Generator

A plain PHP 8.1+ and MySQL application for tenant-scoped member ID cards, CSV import, batch PDFs, and public QR verification.

## Setup

1. Configure the `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` constants in `config/database.php` for your MySQL account. The defaults expect local MySQL and database `id_generator`.
2. Create the database and tables: `mysql < schema.sql` (the SQL creates and selects `id_generator`).
3. Install dependencies: `composer install` (Composer's vendor directory is configured as `lib/`).
4. Place a blank CR80 PNG template at `assets/templates/template.png`.
5. Create writable upload directories and set permissions: `mkdir -p assets/uploads/photos assets/uploads/qr assets/uploads/batches && chmod 0755 assets/uploads/photos assets/uploads/qr assets/uploads/batches` (ensure the web-server user can write where required).
6. Create the initial Acme Corp admin with `php seed.php`.
7. Browse to `/login.php` and sign in with `admin@acme.test` / `admin123`; change the seeded password before production deployment.

The CSV importer expects a header row with `member_code,full_name,position,department,email,phone,issue_date,expiry_date,photo_file`. Dates are `YYYY-MM-DD`; optional photo paths are accepted only from the local `assets/uploads/photos/` directory.

## Production notes

- Batch generation currently builds card buffers and the merged PDF in memory. For large batches, render intermediate PDFs to disk and stream or queue the merge to keep memory bounded.
- QR codes are generated locally using Endroid. If that package is unavailable, install Composer dependencies; an alternative local QR implementation can be used without calling an external QR API.
- Move photos outside the webroot in production and serve them through an authorized handler; the included `.htaccess` blocks direct access to uploaded assets on Apache.
- Replace the small file-based verification rate counter with Redis or a reverse-proxy/WAF rate limit for multi-server deployments. `/api/verify.php` currently applies a local per-IP limit.
- Use HTTPS, configure a dedicated least-privilege database account, rotate the seeded password immediately, and use a strong unique session secret/cookie policy at the hosting layer.
