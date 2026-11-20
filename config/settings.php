<?php

/*
|--------------------------------------------------------------------------
| System settings: what can be changed from Settings in the app
|--------------------------------------------------------------------------
|
| Every setting is declared here once: its page (group), type, default and
| validation. The Settings pages are drawn from this file, so adding a
| setting = adding an entry here. Read a value anywhere with:
|
|     setting('app_name')              // helper
|     app(\App\Support\Settings::class)->get('app_name')
|
| Field keys:
|   type      text | email | url | number | textarea | select | toggle | color | image | password | time
|   label     English label (translated through lang/<locale>.json)
|   help      one sentence under the field (optional)
|   default   value used until an admin saves one
|   rules     Laravel validation rules (optional, sensible ones are added per type)
|   options   for select: [value => label]  (or a string naming a provider, see Settings::options())
|   encrypted true → stored encrypted (secrets)
|
| Values live in the `settings` table; only what was changed is stored.
*/

return [

    'groups' => [

        'general' => [
            'label' => 'General',
            'icon' => 'fa-gear',
            'description' => 'Name, language, time zone and date formats',
            'fields' => [
                'app_name' => [
                    'type' => 'text', 'label' => 'System name', 'default' => env('APP_NAME', 'Laravel Template'),
                    'rules' => ['required', 'string', 'max:80'],
                    'help' => 'Shown in the browser tab, the sidebar and every email.',
                ],
                'company_name' => [
                    'type' => 'text', 'label' => 'Organization name', 'default' => '',
                    'rules' => ['nullable', 'string', 'max:120'],
                ],
                'support_email' => [
                    'type' => 'email', 'label' => 'Support email', 'default' => '',
                    'help' => 'Shown on the sign-in and error pages so people know whom to contact.',
                ],
                'default_locale' => [
                    'type' => 'select', 'label' => 'Default language', 'default' => env('APP_LOCALE', 'en'),
                    'options' => 'locales',
                    'help' => 'Each person can still pick their own language on their profile.',
                ],
                'timezone' => [
                    'type' => 'select', 'label' => 'Time zone', 'default' => env('APP_DISPLAY_TIMEZONE', 'Asia/Riyadh'),
                    'options' => 'timezones',
                    'help' => 'Dates are stored in UTC and shown in this time zone (or the person\'s own).',
                ],
                'date_format' => [
                    'type' => 'select', 'label' => 'Date format', 'default' => 'Y-m-d',
                    'options' => ['Y-m-d' => '2026-01-31', 'd/m/Y' => '31/01/2026', 'm/d/Y' => '01/31/2026', 'd M Y' => '31 Jan 2026', 'M j, Y' => 'Jan 31, 2026'],
                ],
                'time_format' => [
                    'type' => 'select', 'label' => 'Time format', 'default' => 'H:i',
                    'options' => ['H:i' => '14:30', 'h:i A' => '02:30 PM'],
                ],
                'per_page' => [
                    'type' => 'number', 'label' => 'Rows per page', 'default' => 25,
                    'rules' => ['required', 'integer', 'min:5', 'max:200'],
                ],
            ],
        ],

        'branding' => [
            'label' => 'Branding',
            'icon' => 'fa-palette',
            'description' => 'Logo, colors and the sign-in page',
            'fields' => [
                'logo' => [
                    'type' => 'image', 'label' => 'Logo', 'default' => null,
                    'help' => 'PNG, JPG, SVG or WebP, up to 2 MB. Shown in the sidebar, the sign-in page and emails.',
                ],
                'favicon' => [
                    'type' => 'image', 'label' => 'Browser icon (favicon)', 'default' => null,
                    'help' => 'A square PNG or ICO, at least 64×64.',
                ],
                'primary_color' => [
                    'type' => 'color', 'label' => 'Main color', 'default' => '#2563eb',
                    'help' => 'Buttons, links and highlights.',
                ],
                'header_brand' => [
                    'type' => 'select', 'label' => 'Top bar shows', 'default' => 'both',
                    'options' => ['both' => 'Logo and name', 'logo' => 'Logo only', 'name' => 'Name only'],
                    'help' => 'Without a logo, the first letter of the name is shown.',
                ],
                'login_title' => [
                    'type' => 'text', 'label' => 'Sign-in page title', 'default' => 'Welcome back',
                    'rules' => ['nullable', 'string', 'max:120'],
                ],
                'login_subtitle' => [
                    'type' => 'textarea', 'label' => 'Sign-in page text', 'default' => 'Sign in to continue to your workspace.',
                    'rules' => ['nullable', 'string', 'max:500'],
                ],
                'footer_text' => [
                    'type' => 'text', 'label' => 'Footer text', 'default' => '',
                    'rules' => ['nullable', 'string', 'max:200'],
                ],
            ],
        ],

        'attendance' => [
            'label' => 'Attendance',
            'icon' => 'fa-fingerprint',
            'description' => 'This branch and the fingerprint devices',
            'fields' => [
                'branch_name' => [
                    'type' => 'text', 'label' => 'This branch name', 'default' => '',
                    'rules' => ['nullable', 'string', 'max:100'],
                    'help' => 'Written in the files you send to another branch.',
                ],
                'allowed_devices' => [
                    'type' => 'textarea', 'label' => 'Allowed device serial numbers', 'default' => '',
                    'rules' => ['nullable', 'string', 'max:2000'],
                    'help' => 'One per line or comma separated. Empty = any device may send punches.',
                ],
            ],
        ],

        'security' => [
            'label' => 'Security',
            'icon' => 'fa-lock',
            'description' => 'Two-factor, passwords, lockout and sessions',
            'fields' => [
                'two_factor_mode' => [
                    'type' => 'select', 'label' => 'Two-factor authentication', 'default' => 'optional',
                    'options' => ['off' => 'Off', 'optional' => 'Optional — each person decides', 'required' => 'Required for everyone'],
                    'help' => 'With an authenticator app (Microsoft / Google Authenticator, 1Password...).',
                ],
                'code_sign_in' => [
                    'type' => 'toggle', 'label' => 'Sign in with the authenticator code', 'default' => true,
                    'help' => 'People who linked an authenticator app may sign in with their email and the 6-digit code, without a password.',
                ],
                'password_reset_by_code' => [
                    'type' => 'toggle', 'label' => 'Reset password with the authenticator code', 'default' => true,
                    'help' => 'Works without email. Only for accounts that linked an authenticator app.',
                ],
                'password_login' => [
                    'type' => 'toggle', 'label' => 'Allow sign-in with a password', 'default' => true,
                    'help' => 'Turn off to allow only single sign-on. Super users can always use a password.',
                ],
                'password_min_length' => [
                    'type' => 'number', 'label' => 'Minimum password length', 'default' => 8,
                    'rules' => ['required', 'integer', 'min:6', 'max:64'],
                ],
                'password_mixed_case' => ['type' => 'toggle', 'label' => 'Require upper and lower case letters', 'default' => false],
                'password_numbers' => ['type' => 'toggle', 'label' => 'Require a number', 'default' => true],
                'password_symbols' => ['type' => 'toggle', 'label' => 'Require a symbol', 'default' => false],
                'password_uncompromised' => [
                    'type' => 'toggle', 'label' => 'Refuse leaked passwords', 'default' => false,
                    'help' => 'Checks haveibeenpwned.com (only a hash prefix is sent). Needs internet access.',
                ],
                'login_max_attempts' => [
                    'type' => 'number', 'label' => 'Failed attempts before lockout', 'default' => 5,
                    'rules' => ['required', 'integer', 'min:3', 'max:50'],
                ],
                'login_lockout_minutes' => [
                    'type' => 'number', 'label' => 'Lockout length (minutes)', 'default' => 15,
                    'rules' => ['required', 'integer', 'min:1', 'max:1440'],
                ],
                'session_lifetime' => [
                    'type' => 'number', 'label' => 'Sign out after inactivity (minutes)', 'default' => (int) env('SESSION_LIFETIME', 120),
                    'rules' => ['required', 'integer', 'min:5', 'max:525600'],
                ],
                'new_device_alerts' => [
                    'type' => 'toggle', 'label' => 'Email people about sign-ins from a new device', 'default' => true,
                ],
                'lockout_alert_threshold' => [
                    'type' => 'number', 'label' => 'Alert admins after this many lockouts in a day', 'default' => 3,
                    'rules' => ['required', 'integer', 'min:0', 'max:100'],
                    'help' => '0 turns the alert off.',
                ],
            ],
        ],

        'registration' => [
            'label' => 'Accounts',
            'icon' => 'fa-user-plus',
            'description' => 'Self sign-up and new accounts',
            'fields' => [
                'allow_registration' => [
                    'type' => 'toggle', 'label' => 'Let people create their own account', 'default' => false,
                    'help' => 'Adds a "Create an account" link to the sign-in page.',
                ],
                'registration_domains' => [
                    'type' => 'text', 'label' => 'Allowed email domains', 'default' => '',
                    'rules' => ['nullable', 'string', 'max:500'],
                    'help' => 'Comma separated, e.g. example.com, example.org. Empty = any domain.',
                ],
                'default_group' => [
                    'type' => 'select', 'label' => 'Group for new accounts', 'default' => '',
                    'options' => 'groups',
                    'help' => 'Self sign-ups and single sign-on accounts join this group.',
                ],
                'profile_edit' => [
                    'type' => 'toggle', 'label' => 'People may edit their own name and email', 'default' => true,
                ],
            ],
        ],

        'mail' => [
            'label' => 'Email & Alerts',
            'icon' => 'fa-envelope',
            'description' => 'Sender, admin alerts and test email',
            'fields' => [
                'mail_from_name' => [
                    'type' => 'text', 'label' => 'Sender name', 'default' => env('MAIL_FROM_NAME', ''),
                    'rules' => ['nullable', 'string', 'max:120'],
                    'help' => 'Empty = the system name. The mail server itself is set in .env (MAIL_*).',
                ],
                'mail_from_address' => [
                    'type' => 'email', 'label' => 'Sender address', 'default' => env('MAIL_FROM_ADDRESS', ''),
                ],
                'alert_email' => [
                    'type' => 'text', 'label' => 'Admin alert addresses', 'default' => '',
                    'rules' => ['nullable', 'string', 'max:500'],
                    'help' => 'Comma separated. Empty = every super user.',
                ],
            ],
        ],

        'sso' => [
            'label' => 'Single sign-on',
            'icon' => 'fa-brands fa-microsoft',
            'description' => 'Sign in with Microsoft (Entra ID / Office 365)',
            'fields' => [
                'microsoft_enabled' => [
                    'type' => 'toggle', 'label' => 'Show "Sign in with Microsoft"', 'default' => false,
                ],
                'microsoft_client_id' => [
                    'type' => 'text', 'label' => 'Application (client) ID', 'default' => env('MICROSOFT_CLIENT_ID', ''),
                    'rules' => ['nullable', 'string', 'max:200'],
                ],
                'microsoft_client_secret' => [
                    'type' => 'password', 'label' => 'Client secret', 'default' => env('MICROSOFT_CLIENT_SECRET', ''),
                    'encrypted' => true,
                    'help' => 'Leave empty to keep the saved secret.',
                ],
                'microsoft_tenant' => [
                    'type' => 'text', 'label' => 'Directory (tenant) ID', 'default' => env('MICROSOFT_TENANT', 'common'),
                    'rules' => ['nullable', 'string', 'max:200'],
                    'help' => 'Your tenant ID to allow only your organization, or "common".',
                ],
                'microsoft_auto_create' => [
                    'type' => 'toggle', 'label' => 'Create an account on first Microsoft sign-in', 'default' => false,
                    'help' => 'Off = only people who already have an account (matched by email) can sign in.',
                ],
            ],
        ],

        'retention' => [
            'label' => 'Data retention',
            'icon' => 'fa-box-archive',
            'description' => 'How long logs are kept',
            'fields' => [
                'activity_retention_days' => [
                    'type' => 'number', 'label' => 'Keep the activity log (days)', 'default' => 730,
                    'rules' => ['required', 'integer', 'min:0', 'max:36500'],
                    'help' => '0 = keep forever. Older rows are deleted every night.',
                ],
                'sign_in_retention_days' => [
                    'type' => 'number', 'label' => 'Keep the sign-in log (days)', 'default' => 365,
                    'rules' => ['required', 'integer', 'min:0', 'max:36500'],
                    'help' => '0 = keep forever.',
                ],
                'notification_retention_days' => [
                    'type' => 'number', 'label' => 'Keep read notifications (days)', 'default' => 90,
                    'rules' => ['required', 'integer', 'min:0', 'max:36500'],
                    'help' => '0 = keep forever.',
                ],
            ],
        ],

        // Not shown as a settings page: the Backups page edits these itself.
        'backups' => [
            'label' => 'Backups',
            'icon' => 'fa-database',
            'hidden' => true,
            'fields' => [
                'backup_enabled' => ['type' => 'toggle', 'label' => 'Automatic backups', 'default' => false],
                'backup_frequency' => ['type' => 'select', 'label' => 'Frequency', 'default' => 'daily', 'options' => ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly']],
                'backup_time' => ['type' => 'time', 'label' => 'Time', 'default' => '03:00'],
                'backup_weekday' => ['type' => 'number', 'label' => 'Day of the week', 'default' => 5, 'rules' => ['integer', 'min:0', 'max:6']],
                'backup_month_day' => ['type' => 'number', 'label' => 'Day of the month', 'default' => 1, 'rules' => ['integer', 'min:1', 'max:28']],
                'backup_contents' => ['type' => 'select', 'label' => 'Contents', 'default' => 'full', 'options' => ['full' => 'Database + uploaded files', 'db' => 'Database only']],
                'backup_keep_count' => ['type' => 'number', 'label' => 'Keep the newest', 'default' => 10, 'rules' => ['integer', 'min:0', 'max:500']],
                'backup_keep_days' => ['type' => 'number', 'label' => 'Delete older than (days)', 'default' => 30, 'rules' => ['integer', 'min:0', 'max:3650']],
            ],
        ],
    ],

    // Languages the app ships. Add one: create lang/<code>.json and add it here.
    'locales' => [
        'en' => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr'],
        'ar' => ['name' => 'Arabic',  'native' => 'العربية', 'dir' => 'rtl'],
    ],
];
