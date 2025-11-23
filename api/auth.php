<?php
header('Content-Type: application/json');
// Start session for login state
session_start();

// Load config
$CONFIG = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO($CONFIG['dsn'], $CONFIG['db_user'], $CONFIG['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // support login and logout actions
    $action = $input['action'] ?? 'login';
    if ($action === 'logout') {
        session_unset();
        session_destroy();
        echo json_encode(['ok' => true, 'logged_out' => true]);
        exit;
    }

    if (!isset($input['username']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing credentials']);
        exit;
    }
    $username = $input['username'];
    $password = $input['password'];
    $stmt = $pdo->prepare('SELECT id,username,pass_hash FROM admins WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['pass_hash'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid credentials']);
        exit;
    }
    // Set session
    $_SESSION['admin_id'] = (int)$user['id'];
    $_SESSION['admin_username'] = $user['username'];
    echo json_encode(['ok' => true, 'username' => $user['username']]);
    exit;
}

// GET: return auth status
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_SESSION['admin_id'])) {
        echo json_encode(['ok' => true, 'logged_in' => true, 'username' => $_SESSION['admin_username']]);
    } else {
        echo json_encode(['ok' => true, 'logged_in' => false]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'method not allowed']);
<?php
require_once __DIR__ . '/../config.sample.php';

header('Content-Type: application/json');

try {
    $pdo = new PDO($CONFIG['dsn'], $CONFIG['db_user'], $CONFIG['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (!isset($input['username']) || !isset($input['password'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing credentials']);
    exit;
}

$username = $input['username'];
$password = $input['password'];

$stmt = $pdo->prepare('SELECT id,username,pass_hash FROM admins WHERE username = :u LIMIT 1');
$stmt->execute([':u' => $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['pass_hash'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid credentials']);
    exit;
}

// Authentication successful — return a simple session token (not real session storage)
$token = base64_encode($user['id'] . ':' . bin2hex(random_bytes(16)));
echo json_encode(['ok' => true, 'token' => $token, 'username' => $user['username']]);

?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>Auth API removed</title>
	<style>body{font-family:Arial,Helvetica,sans-serif;margin:40px;color:#222}</style>
</head>
<body>
	<h1>Auth API removed</h1>
	<p>This repository no longer includes PHP APIs. Use <a href="/admin.html">admin.html</a> (static) to edit data locally, then upload `data/stuff.json` to your server to apply changes.</p>
</body>
</html>
session_start();
