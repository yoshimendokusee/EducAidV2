<?php

return [
    // Absolute path of the current standalone EducAid project.
    'compat_root' => env('COMPAT_ROOT', base_path('..')),

    // Whitelisted roots for executable compatibility scripts.
    'allowed_roots' => [
        'website',
        'modules',
        'api',
        'includes',
        'config',
        '',
    ],

    // Session keys expected by compatibility script execution.
    'session_keys' => [
        'student_id',
        'student_username',
        'admin_id',
        'admin_username',
        'admin_role',
        'csrf_token',
        'csrf_token_time',
    ],
];
