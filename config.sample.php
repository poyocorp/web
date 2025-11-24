<?php
/**
 * DB config sample. Copy to `config.php` and edit, or use this file as-is.
 * WARNING: storing credentials in plaintext is convenient for local development
 * but not recommended for production.
 */

$CONFIG = [
	'db_host' => '127.0.0.1',
	'db_name' => 'poyo_stuff',
	'db_user' => 'FryDaDog',
	'db_pass' => 'SproutPoyoCorp943/',
	// PDO DSN built from above
	'dsn' => 'mysql:host=127.0.0.1;dbname=poyo_stuff;charset=utf8mb4',
];

return isset($CONFIG) ? $CONFIG : [];

