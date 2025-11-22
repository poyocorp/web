<?php
// Copy this file to `config.php` and edit the DSN, username and password.
// Keep this file out of version control when you add your real credentials.

return [
    // Data Source Name for PDO. Example for MySQL:
    // 'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=poyoweb;charset=utf8mb4',
    'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=poyoweb;charset=utf8mb4',
    'user' => 'poyoweb_user',
    'pass' => 'change_me',
    // Optional PDO options
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
];
