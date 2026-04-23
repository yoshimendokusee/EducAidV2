<?php

return [
    // Absolute path of the current standalone EducAid project.
    'legacy_root' => env('LEGACY_ROOT', base_path('..')),

    // Whitelisted roots for executable legacy scripts.
    'allowed_roots' => [
        'website',
        'modules',
        'api',
        'includes',
        'config',
        '',
    ],

    // Session keys expected by legacy PHP scripts.
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
