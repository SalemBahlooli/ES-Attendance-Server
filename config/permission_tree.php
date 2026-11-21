<?php

/*
|--------------------------------------------------------------------------
| Permission tree: the ONE place to add or change a permission
|--------------------------------------------------------------------------
|
| area        → a tab on the group / user permission page
|   section   → a block inside that tab
|     permission → keyed by its name: the string the code checks with
|                  $user->hasAccess('users.edit'), @can('users.edit'),
|                  ->middleware('can:users.edit') or Gate::allows(...)
|
| A permission may hold `children`: finer permissions that only make sense
| once the parent is granted ("Delete users" sits under "View users"). A DENY
| on a parent closes every child under it, and a child can only be granted
| under a granted parent. See App\Support\Permissions\PermissionTree.
|
| Optional keys on a permission:
|   note       one sentence shown under the label
|   sensitive  true → red "Sensitive" tag (other people's access, money, keys)
|   hidden     true → kept for old data, never shown
|
| Values stored on a user or group:  1 = allow,  -1 = deny,  missing/0 = inherit.
| A user's own value wins over their groups; otherwise a DENY in any group
| beats an ALLOW in another.
|
| After adding a permission run:  php artisan permissions:check
| Labels are translated through lang/<locale>.json (key = the English label).
*/

return [

    /* ================================================================== */
    'system' => [
        'label' => 'System & Access',
        'icon' => 'fa-shield-halved',
        'color' => '#7c3aed',
        'description' => 'Full control and what people may do on their own account',
        'sections' => [

            'Global' => [
                'icon' => 'fa-crown',
                'description' => 'Everything, everywhere',
                'permissions' => [
                    'superuser' => [
                        'label' => 'Super User',
                        'note' => 'Full access to every part of the system. Overrides every other permission on this page.',
                        'sensitive' => true,
                    ],
                ],
            ],

            'Settings' => [
                'icon' => 'fa-sliders',
                'description' => 'System configuration',
                'permissions' => [
                    'settings.manage' => [
                        'label' => 'Manage settings',
                        'note' => 'Change the system name, branding, security rules, email and sign-in options.',
                        'sensitive' => true,
                        'children' => [
                            'settings.backups' => [
                                'label' => 'Backups',
                                'note' => 'Create, download and delete backups, and change the backup schedule.',
                                'sensitive' => true,
                            ],
                            'settings.system' => [
                                'label' => 'System information',
                                'note' => 'See the server, PHP, database and queue status page.',
                            ],
                        ],
                    ],
                ],
            ],

            'Self' => [
                'icon' => 'fa-user',
                'description' => 'What a person may do on their own account',
                'permissions' => [
                    'self.api' => [
                        'label' => 'Create API tokens',
                        'note' => 'The user may create personal API tokens to use the REST API.',
                    ],
                    'self.two_factor_disable' => [
                        'label' => 'Turn off own two-factor',
                        'note' => 'Only matters while two-factor is optional. When it is required nobody can turn it off.',
                    ],
                ],
            ],
        ],
    ],

    /* ================================================================== */
    'people' => [
        'label' => 'People',
        'icon' => 'fa-users',
        'color' => '#2563eb',
        'description' => 'User accounts and permission groups',
        'sections' => [

            'Users' => [
                'icon' => 'fa-user-group',
                'description' => 'User accounts and their sign-in',
                'permissions' => [
                    'users.view' => [
                        'label' => 'View users',
                        'children' => [
                            'users.create' => ['label' => 'Create'],
                            'users.edit' => [
                                'label' => 'Edit',
                                'children' => [
                                    'users.sign_in' => [
                                        'label' => 'Sign-in access',
                                        'note' => 'Turn an account on or off, set its password, reset its two-factor and sign it out of its devices.',
                                        'sensitive' => true,
                                    ],
                                    'users.permissions' => [
                                        'label' => 'Change permissions & groups',
                                        'note' => 'They can only grant permissions they hold themselves, and never Super User.',
                                        'sensitive' => true,
                                    ],
                                ],
                            ],
                            'users.delete' => ['label' => 'Delete'],
                            'users.export' => ['label' => 'Export (CSV)'],
                        ],
                    ],
                ],
            ],

            'Groups' => [
                'icon' => 'fa-user-shield',
                'description' => 'Permission groups (roles)',
                'permissions' => [
                    'groups.view' => [
                        'label' => 'View groups',
                        'children' => [
                            'groups.create' => ['label' => 'Create', 'note' => 'Also allows cloning a group.'],
                            'groups.edit' => [
                                'label' => 'Edit',
                                'note' => 'Change a group\'s name and permissions. Only permissions they hold themselves.',
                                'sensitive' => true,
                            ],
                            'groups.delete' => ['label' => 'Delete'],
                        ],
                    ],
                ],
            ],
        ],
    ],

    /* ================================================================== */
    'monitoring' => [
        'label' => 'Monitoring',
        'icon' => 'fa-chart-line',
        'color' => '#0d9488',
        'description' => 'Dashboard, logs and who is online',
        'sections' => [

            'Dashboard' => [
                'icon' => 'fa-gauge-high',
                'description' => 'The overview page',
                'permissions' => [
                    'dashboard.view' => [
                        'label' => 'View the dashboard figures',
                        'note' => 'Without it the home page only shows the person\'s own items.',
                    ],
                ],
            ],

            'Activity Log' => [
                'icon' => 'fa-clock-rotate-left',
                'description' => 'Everything that changed, by whom',
                'permissions' => [
                    'activity.view' => [
                        'label' => 'View the activity log',
                        'children' => [
                            'activity.export' => ['label' => 'Export (CSV)'],
                        ],
                    ],
                ],
            ],

            'Sign-ins' => [
                'icon' => 'fa-right-to-bracket',
                'description' => 'Sign-in history and presence',
                'permissions' => [
                    'signins.view' => [
                        'label' => 'View everyone\'s sign-ins',
                        'note' => 'The sign-in log with failed attempts, lockouts and new devices.',
                    ],
                    'presence.view' => [
                        'label' => 'See who is online',
                        'note' => 'The online-now widget and the Login Activity page.',
                    ],
                ],
            ],
        ],
    ],

    /* ================================================================== */
    'communication' => [
        'label' => 'Communication',
        'icon' => 'fa-bullhorn',
        'color' => '#d97706',
        'description' => 'Announcements and the changelog',
        'sections' => [

            'Announcements' => [
                'icon' => 'fa-bullhorn',
                'description' => 'Messages in everyone\'s notification bell',
                'permissions' => [
                    'announcements.manage' => ['label' => 'Manage announcements'],
                ],
            ],

            'Changelog' => [
                'icon' => 'fa-list-check',
                'description' => 'The "What\'s new" page',
                'permissions' => [
                    'changelog.manage' => ['label' => 'Manage the changelog', 'note' => 'Everyone can read published entries.'],
                ],
            ],
        ],
    ],

    /* ================================================================== */
    /*
     | Your project's modules go below. `php artisan make:module` adds a block
     | here automatically, just above the marker line. Keep the marker.
     */
    'modules' => [
        'label' => 'Modules',
        'icon' => 'fa-cubes',
        'color' => '#db2777',
        'description' => 'The features of this project',
        'sections' => [
            'Attendance' => [
                'icon' => 'fa-fingerprint',
                'description' => 'Daily attendance, reports and punches',
                'permissions' => [
                    'attendance.view' => [
                        'label' => 'View daily attendance',
                        'note' => 'The daily attendance page and the attendance figures on the home page.',
                    ],
                    'reports.view' => [
                        'label' => 'View reports',
                        'note' => 'The Overview page and the period report for every employee.',
                        'children' => [
                            'reports.export' => [
                                'label' => 'Export Excel and timesheet',
                                'note' => 'Also allows sending attendance to another branch.',
                            ],
                        ],
                    ],
                    'punches.view' => [
                        'label' => 'View punch log',
                        'children' => [
                            'punches.edit' => [
                                'label' => 'Add, edit and delete punches',
                                'note' => 'Every change is kept in the activity log with the reason.',
                                'sensitive' => true,
                            ],
                        ],
                    ],
                ],
            ],

            'Leaves' => [
                'icon' => 'fa-umbrella-beach',
                'description' => 'Employee leaves and public holidays',
                'permissions' => [
                    'leaves.view' => [
                        'label' => 'View leaves and holidays',
                        'children' => [
                            'leaves.edit' => ['label' => 'Add and delete employee leaves'],
                            'holidays.edit' => ['label' => 'Add and delete public holidays'],
                        ],
                    ],
                ],
            ],

            'Employee requests' => [
                'icon' => 'fa-inbox',
                'description' => 'Leave and punch correction requests from the employee portal',
                'permissions' => [
                    'requests.review' => [
                        'label' => 'Approve and reject employee requests',
                        'note' => 'An approved leave is added to the employee\'s leaves; an approved punch is added as a manual punch. Nobody can approve their own request.',
                    ],
                ],
            ],

            'Employees' => [
                'icon' => 'fa-id-badge',
                'description' => 'The people who punch on the devices',
                'permissions' => [
                    'employees.view' => [
                        'label' => 'View employees',
                        'children' => [
                            'employees.edit' => [
                                'label' => 'Edit employee details',
                                'children' => [
                                    'employees.delete' => [
                                        'label' => 'Delete employees',
                                        'note' => 'Removes the employee with their leaves and requests. Their punches are kept as deleted, so a re-import does not bring them back.',
                                        'sensitive' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            'Attendance setup' => [
                'icon' => 'fa-screwdriver-wrench',
                'description' => 'Shifts, departments, devices and imports',
                'permissions' => [
                    'shifts.manage' => [
                        'label' => 'Manage shifts and departments',
                        'note' => 'Also shift schedules and special working hours (Ramadan). Changes how lateness and absence are counted for everyone.',
                    ],
                    'devices.view' => [
                        'label' => 'View devices',
                        'children' => [
                            'devices.manage' => ['label' => 'Send device commands and rename devices'],
                        ],
                    ],
                    'imports.manage' => [
                        'label' => 'Import punches from USB, files or the LAN',
                        'note' => 'Also allows importing a file from another branch and using the LAN bridge.',
                    ],
                ],
            ],

            // @module-permissions (make:module inserts above this line)
        ],
    ],
];
