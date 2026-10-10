<?php

return [
    'app_name' => 'DIO ERP',
    'language' => 'Language',

    'menu' => [
        'dashboard' => 'Dashboard',
        'modules' => 'Modules',
        'audit_log' => 'Audit log',
    ],

    'common' => [
        'sign_out' => 'Sign out',
        'cancel' => 'Cancel',
        'confirm' => 'Confirm',
        'actions' => 'Actions',
        'enable' => 'Enable',
        'disable' => 'Disable',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'enabled' => 'Enabled',
        'disabled' => 'Disabled',
        'required' => 'Required',
        'time' => 'Time',
        'user' => 'User',
        'event' => 'Event',
        'subject' => 'Subject',
        'channel' => 'Channel',
        'system' => 'System',
        'no_data' => 'Nothing to show yet.',
        'open_menu' => 'Open menu',
    ],

    'dashboard' => [
        'title' => 'Dashboard',
        'welcome' => 'Welcome, :name',
        'users' => 'Active users',
        'branches' => 'Active branches',
        'departments' => 'Active departments',
        'employees' => 'Active employees',
        'recent_activity' => 'Recent activity',
        'modules' => 'Modules',
    ],

    'modules' => [
        'title' => 'Modules',
        'subtitle' => 'Turn optional parts of the system on or off for this installation.',
        'name' => 'Module',
        'key' => 'Key',
        'depends_on' => 'Requires',
        'status' => 'Status',
        'enabled' => 'The :module module is now enabled.',
        'disabled' => 'The :module module is now disabled.',
        'confirm_disable_title' => 'Disable :module?',
        'confirm_disable_body' => 'Its menu items, pages and API endpoints will stop working until it is enabled again. Existing data is kept.',
    ],

    'audit' => [
        'title' => 'Audit log',
        'subtitle' => 'Append-only record of who changed what.',
    ],

    'notifications' => [
        'title' => 'Notifications',
        'subtitle' => 'Messages about your approvals and other work.',
        'empty' => 'You have no notifications.',
        'mark_all_read' => 'Mark all as read',
        'level' => ['info' => 'Info', 'success' => 'Done', 'warning' => 'Attention', 'danger' => 'Problem'],
    ],

    'attachments' => [
        'title' => 'Attachments',
        'upload' => 'Attach a file',
        'choose' => 'Choose file',
        'uploaded' => 'The file was attached.',
        'deleted' => 'The attachment was removed.',
        'none' => 'No files attached.',
        'download' => 'Download',
        'remove' => 'Remove',
        'hint' => 'PDF, images, Word, Excel, CSV or text, up to :size MB.',
    ],
];
