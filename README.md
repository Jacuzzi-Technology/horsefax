# Horsefax

The lasting record of a horse's identity and history: https://horsefax.com. Operated by Horsefax, LLC.

Laravel 13, Fortify, Tailwind 4, PostgreSQL. Standalone app with its own database and login (separate from Trusted Horse and HOMD).

## Local

```
createdb horsefax && createdb horsefax_test
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate   # set DB_CONNECTION=pgsql, DB_DATABASE=horsefax
php artisan migrate
php artisan serve --port=8050
php artisan test
```

## Production

trusted-core-01 (146.190.128.6): user `horsefax`, `/var/www/horsefax`, PHP-FPM pool `horsefax`, nginx site `horsefax`, Let's Encrypt. Deploy with `bin/deploy` (builds assets, uploads, `composer install`, migrates). Nightly database and file backups go to `/var/backups/horsefax`. Staff: `php artisan hf:staff <email>`; staff must turn on two-factor sign-in.

## How records work

- Every fact has a visibility (private, shared in report links, public) and a verification status (self-reported, document supplied, independently verified, disputed, superseded). Visibility is enforced in `App\Support\Views`.
- History is append-only: corrections supersede entries, and the `audit_log` table rejects updates and deletes at the database level.
- Duplicate registration or microchip numbers go to the staff review queue; nothing is merged automatically.
- Search (`App\Support\HorseSearch`) narrows from a name until 10 or fewer horses match.
