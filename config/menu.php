<?php

/*
|--------------------------------------------------------------------------
| Sidebar menu (and the top bar's shortcuts and Create menu)
|--------------------------------------------------------------------------
|
| Each item:
|   label       English text (translated through lang/<locale>.json)
|   icon        Font Awesome icon name, e.g. 'fa-users'
|   route       named route to open
|   active      route-name pattern(s) that highlight the item (default: route + '.*')
|   can         permission (from config/permission_tree.php) needed to see it — optional
|   children    a sub-menu (same keys), the parent then only opens/closes it
|   badge       key of a live counter returned by HeartbeatController (optional)
|   shortcut    true → also an icon in the top bar (optional)
|   create      ['route' => 'users.create', 'can' => 'users.create', 'label' => 'User']
|               → an entry in the top bar's blue "Create" menu (optional)
|
| On large screens the sidebar is a narrow icon rail: pointing at an icon opens
| its label and sub-menu beside it. On phones it slides in with full labels.
|
| An item whose `can` fails is hidden. A parent with no visible children is hidden.
| `php artisan make:module` inserts a menu item above the marker line.
*/

return [

    ['label' => 'Dashboard', 'icon' => 'fa-gauge-high', 'route' => 'dashboard', 'active' => 'dashboard'],

    ['heading' => 'Attendance'],

    ['label' => 'Overview', 'icon' => 'fa-chart-pie', 'route' => 'overview.index', 'active' => 'overview.*', 'can' => 'reports.view'],
    ['label' => 'Daily attendance', 'icon' => 'fa-calendar-check', 'route' => 'attendance.index', 'active' => 'attendance.*', 'can' => 'attendance.view', 'shortcut' => true],
    ['label' => 'Report', 'icon' => 'fa-chart-column', 'route' => 'reports.index', 'active' => 'reports.*', 'can' => 'reports.view', 'shortcut' => true],
    ['label' => 'Leaves & holidays', 'icon' => 'fa-umbrella-beach', 'route' => 'leaves.index', 'active' => 'leaves.*', 'can' => 'leaves.view'],

    ['heading' => 'Management'],

    ['label' => 'Employees', 'icon' => 'fa-id-badge', 'route' => 'employees.index', 'active' => 'employees.*', 'can' => 'employees.view', 'shortcut' => true],
    ['label' => 'Punch log', 'icon' => 'fa-list-ul', 'route' => 'punches.index', 'active' => 'punches.*', 'can' => 'punches.view'],
    ['label' => 'Shifts & departments', 'icon' => 'fa-business-time', 'route' => 'shifts.index', 'active' => 'shifts.*', 'can' => 'shifts.manage'],
    ['label' => 'Devices', 'icon' => 'fa-tablet-screen-button', 'route' => 'devices.index', 'active' => 'devices.*', 'can' => 'devices.view'],
    ['label' => 'Import & export', 'icon' => 'fa-file-import', 'route' => 'imports.index', 'active' => 'imports.*', 'can' => 'imports.manage'],

    // @module-menu (make:module inserts above this line)

    ['heading' => 'Administration'],

    ['label' => 'Users', 'icon' => 'fa-users', 'route' => 'users.index', 'active' => 'users.*', 'can' => 'users.view', 'shortcut' => true,
        'create' => ['route' => 'users.create', 'can' => 'users.create', 'label' => 'User']],
    ['label' => 'Groups & Permissions', 'icon' => 'fa-user-shield', 'route' => 'groups.index', 'active' => 'groups.*', 'can' => 'groups.view', 'shortcut' => true,
        'create' => ['route' => 'groups.create', 'can' => 'groups.create', 'label' => 'Group']],

    ['label' => 'Monitoring', 'icon' => 'fa-chart-line', 'children' => [
        ['label' => 'Activity Log', 'icon' => 'fa-clock-rotate-left', 'route' => 'activity.index', 'active' => 'activity.*', 'can' => 'activity.view', 'shortcut' => true],
        ['label' => 'Sign-in Log', 'icon' => 'fa-right-to-bracket', 'route' => 'sign-ins.index', 'active' => 'sign-ins.*', 'can' => 'signins.view'],
        ['label' => 'Login Activity', 'icon' => 'fa-signal', 'route' => 'presence.index', 'active' => 'presence.*', 'can' => 'presence.view'],
    ]],

    ['label' => 'Announcements', 'icon' => 'fa-bullhorn', 'route' => 'announcements.index', 'active' => 'announcements.*', 'can' => 'announcements.manage'],
    ['label' => 'Settings', 'icon' => 'fa-sliders', 'route' => 'settings.index', 'active' => 'settings.*', 'can' => 'settings.manage'],

    ['heading' => 'Help'],

    ['label' => "What's new", 'icon' => 'fa-wand-magic-sparkles', 'route' => 'changelog.index', 'active' => 'changelog.*'],
];
