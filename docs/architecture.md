# Architecture: where things are

Plain Laravel, no admin-panel package. Server-rendered Blade pages, Alpine.js for small
interactions, Tailwind for styling. Anything you know from the Laravel docs applies.

```
app/
  Console/Commands/      make:module, lang:missing, permissions:check, app:create-admin, backups, pruning
  Http/Controllers/
    Auth/                sign-in, two-factor, password reset (link and code), sign-up, Microsoft SSO
    Account/             "My account": profile, password, devices, API tokens
    Admin/               users, groups, logs, presence, announcements, settings, backups, system info
    Api/                 /api/v1 endpoints (JSON, Sanctum tokens)
    *.php                dashboard, notifications, heartbeat, language switch, changelog
  Http/Middleware/       SetLocale, EnsureAccountIsActive, EnsureTwoFactor, TrackPresence,
                         RecordUnloggedActions, SecurityHeaders  (registered in bootstrap/app.php)
  Models/                User, Group, ActivityLog, SignIn, Announcement, ChangelogEntry
  Models/Concerns/       LogsActivity: add to a model to log its changes
  Notifications/         SimpleNotification (use this one for your modules) + the built-in emails
  Services/SignInLog     records every sign-in attempt, new-device emails, lockout alerts
  Support/               the building blocks, each documented at the top of its file:
    Permissions/         PermissionTree (reads the tree), PermissionGuard (who may grant what)
    Settings             setting('key') values from the database with defaults from config
    Activity             Activity::log(...) and the change diffs
    TwoFactor            TOTP, trusted browsers, reused-code protection
    Sessions             list and sign out a person's devices
    Menu                 builds the side rail, top-bar shortcuts and "Create" menu from config/menu.php
    Backups              list, create, download backups
    UserAgent            "Chrome on Windows" from a user agent string
  helpers.php            setting(), format_date(), format_datetime(), format_bytes(), is_rtl(), display_timezone()
config/
  permission_tree.php    every permission, grouped for the Groups & Permissions screen
  settings.php           every setting: page, type, default, validation
  menu.php               the side rail, top-bar shortcuts and "Create" menu
database/
  migrations/            users, groups, settings, activity_logs, sign_ins, announcements, ...
  seeders/               DatabaseSeeder (safe to repeat), DemoSeeder (sample data)
  factories/             UserFactory (->superUser(), ->withPermissions([...]), ->withTwoFactor(), ->inactive())
resources/views/
  components/layouts/    app.blade.php (top bar + icon rail + title card), guest.blade.php (sign-in pages)
  components/            input, select, textarea, toggle, card, stat, badge, modal, empty, delete-button, ...
lang/                    ar.json (every English text → Arabic), ar/*.php and en/*.php (Laravel's messages)
routes/
  web.php                all pages, grouped by permission
  api.php                /api/v1
  console.php            the schedule (pruning, backups)
  modules/               one file per module, loaded automatically
stubs/module/            the templates make:module fills in
docker/, Dockerfile      one image for local development and production
docs/                    these guides
```

## A request, step by step

1. `SetLocale` picks the language: the person's own, else the session, else **Settings → Default language**.
2. `auth` sends guests to `/login`. `EnsureAccountIsActive` signs out deactivated accounts.
3. `EnsureTwoFactor` asks for the code (or for linking an app when two-factor is required).
4. `can:<permission>` on the route (or `HasMiddleware` in the controller) checks the permission tree.
5. The controller does the work. Models using `LogsActivity` write the activity log.
6. `RecordUnloggedActions` writes a generic log entry for any change or download that logged nothing.
7. `TrackPresence` stamps "last seen" for the presence page. `SecurityHeaders` adds the browser security headers.

## Data conventions

- Dates are stored in **UTC** and shown with `format_date()` / `format_datetime()` in the person's time zone.
- Users are **soft deleted** (the trash can be restored). A user's permissions are a JSON column;
  group permissions are a JSON column on `groups`.
- Settings are stored only when changed. Defaults stay in `config/settings.php`.
- Activity, sign-in and notification rows are pruned on a schedule (**Settings → Retention**).

## Background work

The scheduler (`routes/console.php`) prunes old logs, notifications and expired tokens daily and runs
backups at the time set in Settings. The queue sends emails and notifications.
In Docker both start with the container. Elsewhere, run `php artisan schedule:work` and
`php artisan queue:work`, or a cron for `php artisan schedule:run` every minute.
