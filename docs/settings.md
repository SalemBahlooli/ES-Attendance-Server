# Settings

Values admins change from the browser, without a deploy.

## Reading a value

```php
setting('app_name');                  // default comes from config/settings.php
setting('invoice_prefix', 'INV');     // fallback only for keys not declared in the config
```

Values are cached (and the cache is cleared when an admin saves), so calling `setting()` many times is cheap.

## Adding a setting

Add a field to a group in **`config/settings.php`**. The Settings page draws the input, validates it and saves it:

```php
'general' => [
    // ...
    'fields' => [
        // ...
        'invoice_prefix' => [
            'type' => 'text', 'label' => 'Invoice number prefix', 'default' => 'INV',
            'rules' => ['required', 'string', 'max:10'],
            'help' => 'Put before every invoice number, e.g. INV-0042.',
        ],
    ],
],
```

Types: `text`, `email`, `url`, `number`, `textarea`, `select` (with `options`), `toggle`, `color`, `image`, `password`, `time`.
Add `'encrypted' => true` for secrets. A new group (a new page) is a new key under `groups` with `label`, `icon`, `description` and `fields`.

Then run `php artisan lang:missing ar --write` for the Arabic labels.

## What is stored

Only values that differ from the default are written to the `settings` table. Change a default in the config
and every installation that never changed it follows.

## Settings that change Laravel's own config

`AppServiceProvider::applySettings()` copies some settings into Laravel's config on every request: the app name,
mail sender and session lifetime. Password rules come from `registerPasswordRules()` in the same file. Follow the same pattern if a setting
has to change a package's config.

## Built-in pages

| Page | Contains |
|---|---|
| General | name, organisation, support email, default language, time zone, date/time format, rows per page |
| Branding | logo, favicon, main colour, what the top bar shows (logo, name or both), sign-in page text, footer |
| Security | two-factor mode, code sign-in, reset by code, password rules, lockout, session length, new-device and lockout alerts |
| Accounts | self sign-up, allowed email domains, default group, whether people edit their own name and email |
| Mail | sender, alert address, a test email |
| Single sign-on | Microsoft (Entra ID) keys, create accounts on first sign-in |
| Retention | how long the activity log, sign-in log and notifications are kept |
| Backups | schedule, contents, how many to keep (own page under Settings) |
| System | PHP, Laravel, database, queue, cache, storage and scheduled tasks at a glance |

`.env` still holds what must be known before the database is available (database, mail server, `APP_KEY`)
and secrets you do not want in the database. It is documented line by line in `.env.example`.
