# Deployment

The `Dockerfile` builds one production image (PHP 8.5 + Apache, Composer packages without dev tools,
built front-end assets). When the container starts, `docker/entrypoint.sh`:

1. waits for the database (up to 60 s),
2. runs `php artisan migrate --force`,
3. optionally runs `php artisan db:seed --force` (safe to repeat),
4. links `storage`, caches config, routes and views (`php artisan optimize`),
5. starts the scheduler and a queue worker in the background,
6. starts Apache on `$PORT` (default 80).

Switch steps off with `RUN_MIGRATIONS=false`, `RUN_SEEDERS=true`, `RUN_SCHEDULER=false`, `RUN_QUEUE=false`
(for example when you run a separate worker service).

## Required environment variables

| Variable | Value |
|---|---|
| `APP_KEY` | `php artisan key:generate --show` (the container refuses to start in production without it) |
| `APP_URL` | `https://your-domain` (links in emails, Microsoft sign-in callback) |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` (already the image's defaults) |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | your database (or `DB_URL`) |
| `MAIL_*` | your mail server (SMTP, or `MAIL_MAILER=log` until you have one) |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | first admin, created by the seeder when there are no users (with `RUN_SEEDERS=true` on the first deploy) |
| `SESSION_SECURE_COOKIE` | `true` behind HTTPS |

Everything else is optional and documented in `.env.example`.

## Railway

1. New project → **Deploy from GitHub repo** → pick your repository. `railway.json` tells Railway to build the `Dockerfile`
   and to use `/up` as the health check.
2. Add a **MySQL** service. In the app's variables add:

   ```
   APP_KEY=base64:...                 # php artisan key:generate --show
   APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}
   DB_URL=${{MySQL.MYSQL_URL}}
   DB_CONNECTION=mysql
   RUN_SEEDERS=true
   ADMIN_EMAIL=you@example.com
   ADMIN_PASSWORD=a-long-password
   SESSION_SECURE_COOKIE=true
   ```

3. Uploaded files and backups live in `storage/`: add a **volume** mounted at `/var/www/html/storage/app`
   so they survive redeploys.
4. Deploy, open the domain, sign in, change the password. You can set `RUN_SEEDERS` back to `false` (it is harmless either way).

## Any Docker host

```bash
docker build -t my-app .
docker run -d -p 80:80 --env-file .env.production -v my-app-storage:/var/www/html/storage/app my-app
```

Put a reverse proxy with HTTPS in front (Caddy, Traefik, nginx, a load balancer). Proxies are trusted, so
the app sees the real HTTPS scheme and client IP.

## A server without Docker

The usual Laravel deployment: PHP 8.3+ with the extensions in the README, the web root pointing at `public/`, then

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan optimize
```

plus a cron entry `* * * * * php /path/artisan schedule:run` and a supervised `php artisan queue:work`.

## After the first deploy

- **Settings → Mail → Send me a test email** to check mail.
- **Settings → Backups**: turn on automatic backups. Set `BACKUP_ARCHIVE_PASSWORD` to encrypt the zip files.
  Copy backups off the server: point the `backups` disk in `config/filesystems.php` at S3 if you can.
- **Settings → System information** checks the database, cache, queue, storage and the public storage link.
