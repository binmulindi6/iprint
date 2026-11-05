<?php
// iPrint configuration
return [
    'admin_user'      => 'admin',
    // password_hash() of "admin". Generate another with: php -r 'echo password_hash("newpass", PASSWORD_DEFAULT);'
    'admin_pass_hash' => '$2y$12$HopFHbkldzQk2CqM60LDIO69UDCpcKZzjhD.29ancTnYq8bCQA8b2',

    'max_size'        => 50 * 1024 * 1024, // bytes per file
    'allowed_ext'     => [
        'pdf',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'odt', 'ods', 'odp', 'rtf', 'txt', 'csv',
    ],

    // "Open on server" launches the file in the server's default app.
    // By default only allowed when the admin browses from the server machine itself.
    'allow_remote_open' => false,

    'max_login_attempts' => 5,   // failed logins per IP...
    'login_window'       => 600, // ...within this many seconds
];
