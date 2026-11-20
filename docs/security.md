# Security: what is done and what is left to you

## Already in place

| Area | What |
|---|---|
| Passwords | bcrypt, rules from **Settings → Security** (length, case, numbers, symbols, checked against known leaks), current password needed to change it |
| Sign-in | lockout after repeated failures (per account and IP), the same answer for unknown accounts and wrong passwords, session renewed on sign-in, deactivated accounts signed out on the next request |
| Two-factor | TOTP with the secret encrypted at rest, a code works once, trusted-browser cookie tied to the account and its secret (unlinking the app revokes every trusted browser) |
| Password reset | single-use links that expire, or a code from the authenticator app; changing a password signs out the other devices |
| Sessions | stored in the database so people (and admins) can see and end them; signing devices out also replaces the "remember me" token |
| Permissions | checked on the server for every route; nobody can grant beyond their own access or promote themselves |
| API | personal tokens with an expiry, rate limited, only what the owner can do, revoked when the account is turned off |
| Requests | CSRF on every form, rate limits on sensitive actions, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS over HTTPS (`app/Http/Middleware/SecurityHeaders.php`) |
| Uploads | images only for photos and logos, size limits, stored outside the web root except the public disk |
| Logs | every change with who and from where; secrets never written; sign-in log with alerts on new devices and lockouts |
| Errors | friendly error pages; `APP_DEBUG=false` in the production image so no stack traces leak |

## Your part

- **Change the first admin's password** and use a real email for it.
- Serve over **HTTPS** and set `SESSION_SECURE_COOKIE=true`.
- Keep `APP_KEY` secret and stable. Changing it signs everyone out and makes encrypted settings and two-factor secrets unreadable.
- Consider **Settings → Security → Two-factor: required** for admins, or for everyone.
- Turn on **backups** and keep a copy off the server. Set `BACKUP_ARCHIVE_PASSWORD`.
- Check new routes with `can:` middleware and add a test that someone without the permission gets 403.
  `php artisan permissions:check` catches permission names that do not exist.
- Escape output: Blade's `{{ }}` does it, `{!! !!}` does not. Use it only for HTML you produced yourself.
- Validate every input with a Form Request (see the generated modules).
- Run `composer audit` and `npm audit` now and then, and keep Laravel up to date (`composer update`).

Found a security problem in the template itself? Open a private security advisory on the GitHub repository.
