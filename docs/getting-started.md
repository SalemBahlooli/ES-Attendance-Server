# Getting started: a new project from the template

## 1. Create the repository

On GitHub open the template and press **Use this template → Create a new repository**,
or copy it by hand:

```bash
git clone https://github.com/SalemBahlooli/Laravel-Template.git my-app
cd my-app
rm -rf .git && git init && git add . && git commit -m "Start from Laravel Template"
```

## 2. Run it

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app npm install && docker compose exec app npm run build
```

Open http://localhost:8080 and sign in as `admin@example.com` / `password`.
See the [README](../README.md#quick-start-without-docker) to run it without Docker.

## 3. Make it yours (checklist)

| What | Where |
|---|---|
| Project name | `APP_NAME` in `.env` and `.env.example`; also **Settings → General → System name** (that one wins once saved) |
| Composer package name and description | `composer.json` → `name`, `description` |
| Docker project name | `docker-compose.yml` → `name:` (keeps containers of different projects apart) |
| First admin | `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` in `.env` (used once, when there are no users) or `php artisan app:create-admin` |
| Default language and time zone | `APP_LOCALE`, `APP_DISPLAY_TIMEZONE` in `.env`, or **Settings → General** |
| Brand colour, logo, favicon, sign-in text | **Settings → Branding** (no code change needed). The default colour is `--brand` in `resources/css/app.css` |
| Default groups | `database/seeders/DatabaseSeeder.php` (Administrators, Managers, Staff) |
| "What's new" first entry | `DatabaseSeeder::changelog()` |
| License | `LICENSE`: keep MIT or replace it with your project's license |
| This README | Replace it with your project's own; keep `docs/` as reference |

Groups, settings and seed data are **idempotent**: running `php artisan db:seed` again never
duplicates anything, so it is safe on every deploy.

## 4. Build features

- A list/form/details feature → `php artisan make:module` ([modules guide](modules.md)).
- A permission for an action → add it to `config/permission_tree.php` ([permissions](permissions.md)).
- A value admins can change → add it to `config/settings.php` ([settings](settings.md)).
- Tell people something → `$user->notify(new SimpleNotification(...))` ([notifications](activity-and-notifications.md)).
- Every English text in a view goes through `__('...')`, then `php artisan lang:missing ar --write` ([translations](translations.md)).

## 5. What not to delete

The template is small on purpose: every part depends on the others. Before removing something, check:

| Keep | Because |
|---|---|
| `app/Support/*` | permissions, settings, two-factor, activity log, menu: used everywhere |
| `app/Http/Middleware/*` | language, deactivated accounts, required two-factor, presence, catch-all logging, security headers |
| The marker comments `// @module-permissions` and `// @module-menu` | `make:module` inserts new modules above them |
| `routes/modules/` | module routes are loaded from this folder automatically |
| The `superuser` permission and the Administrators group | the only way back in when permissions go wrong (or `php artisan app:create-admin`) |
| `tests/` | run `php artisan test` after every change; CI runs it on every push |

Features you do not need can be switched off instead of deleted: self sign-up, Microsoft sign-in,
two-factor, code sign-in and backups are all toggles in **Settings**.
