<?php

return [
    'compat_root' => env('COMPAT_ROOT', base_path('..')),
    'allowed_roots' => [
        'website',
        'modules',
        'api',
        'includes',
        'config',
        '',
    ],
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
