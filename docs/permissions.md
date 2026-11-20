# Permissions

## The model

- Every permission is declared once in **`config/permission_tree.php`**: area (tab) → section (block) → permission.
- A **group** stores a value per permission: **allow** (`1`), **deny** (`-1`) or **inherit** (missing).
- A **user** belongs to any number of groups and may have their own values too.
- How access is decided, in order:
  1. A deactivated account can do nothing.
  2. A **super user** (`superuser`) can do everything.
  3. The **user's own** allow/deny wins.
  4. Otherwise a **deny in any group** beats an allow in another group.
  5. Otherwise not allowed.
- Permissions can have **children** (`users.view` → `users.edit` → `users.permissions`). A child counts only
  when its parent is allowed, and denying a parent closes every child.
- Nobody can give more than they have: `PermissionGuard` stops people granting a permission they do not
  hold, making themselves a super user, or editing the access of someone with more access.

The **Groups & Permissions** page shows the tree with allow / inherit / deny switches.
A user's **Access** tab also shows, for each permission, where the result comes from (own value or which group).

## Adding a permission

```php
// config/permission_tree.php, inside an area's 'sections'
'Invoices' => [
    'icon' => 'fa-file-invoice',
    'description' => 'Bills sent to clients',
    'permissions' => [
        'invoices.view' => [
            'label' => 'View invoices',
            'children' => [
                'invoices.create'  => ['label' => 'Create'],
                'invoices.approve' => ['label' => 'Approve', 'note' => 'Marks an invoice as ready to send.', 'sensitive' => true],
            ],
        ],
    ],
],
```

Then:

```bash
php artisan permissions:check          # every name used in the code exists in the tree, and back
php artisan lang:missing ar --write    # Arabic labels
```

## Checking a permission

| Where | How |
|---|---|
| Route | `->middleware('can:invoices.approve')` |
| Controller | `implements HasMiddleware` and `new Middleware('can:invoices.edit', only: ['edit', 'update'])` (see `UserController`) |
| Code | `$user->hasAccess('invoices.approve')`, `Gate::allows('invoices.approve')`, `Gate::authorize('invoices.approve')` (403 if not) |
| Blade | `@can('invoices.approve') ... @endcan` |
| Menu | `'can' => 'invoices.view'` in `config/menu.php` |
| API | the same `can:` middleware; a token can only do what its owner can |
| Query | `User::withPermission('invoices.approve')` everyone who has it (for notifications) |

Every permission name also works with Laravel's `Gate` because of `Gate::before` in
`AppServiceProvider`, so policies stay available for record-level rules
("only the creator can edit this draft").

## Built-in permissions

| Permission | Allows |
|---|---|
| `superuser` | everything |
| `settings.manage` → `settings.backups`, `settings.system` | settings pages, backups, system information |
| `self.api` | creating personal API tokens |
| `self.two_factor_disable` | turning off one's own two-factor |
| `users.view` → `users.create`, `users.edit` (→ `users.sign_in`, `users.permissions`), `users.delete`, `users.export` | user management |
| `groups.view` → `groups.create`, `groups.edit`, `groups.delete` | groups and their permissions |
| `dashboard.view` | the dashboard figures |
| `activity.view` → `activity.export` | the activity log |
| `signins.view` | the sign-in log |
| `presence.view` | who is online |
| `announcements.manage` | site-wide announcements |
| `changelog.manage` | "What's new" entries |

## Locked out?

```bash
php artisan app:create-admin --email=you@example.com
```

creates a super user, or makes an existing account one.
