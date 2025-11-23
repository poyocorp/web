<?php
header('Content-Type: application/json');
session_start();

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

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $stmt = $pdo->query('SELECT id,title,description,username,url,created_at FROM items ORDER BY id DESC');
    $rows = $stmt->fetchAll();
    echo json_encode(['ok' => true, 'items' => $rows]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// require logged-in admin for mutations
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not authenticated']);
    exit;
}

$action = $input['action'] ?? null;
if ($action === 'create') {
    $stmt = $pdo->prepare('INSERT INTO items (title,description,username,url) VALUES (:t,:d,:u,:url)');
    $stmt->execute([
        ':t' => $input['title'] ?? '',
        ':d' => $input['description'] ?? null,
        ':u' => $input['username'] ?? null,
        ':url' => $input['url'] ?? null,
    ]);
    echo json_encode(['ok' => true, 'insert_id' => $pdo->lastInsertId()]);
    exit;
}

if ($action === 'update') {
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing id']); exit; }
    $stmt = $pdo->prepare('UPDATE items SET title=:t,description=:d,username=:u,url=:url WHERE id=:id');
    $stmt->execute([
        ':t' => $input['title'] ?? '',
        ':d' => $input['description'] ?? null,
        ':u' => $input['username'] ?? null,
        ':url' => $input['url'] ?? null,
        ':id' => $input['id'],
    ]);
    echo json_encode(['ok' => true, 'rows' => $stmt->rowCount()]);
    exit;
}

if ($action === 'delete') {
    if (empty($input['id'])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing id']); exit; }
    $stmt = $pdo->prepare('DELETE FROM items WHERE id=:id');
    $stmt->execute([':id' => $input['id']]);
    echo json_encode(['ok' => true, 'rows' => $stmt->rowCount()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown action']);
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

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT id,title,description,username,url,created_at FROM items ORDER BY id DESC');
    $rows = $stmt->fetchAll();
    echo json_encode(['ok' => true, 'items' => $rows]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// Basic token check (stateless) — in this simple setup we just require a token header
$auth = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($input['token'] ?? null);
if (!$auth) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'missing token']);
    exit;
}

// NOTE: For simplicity the token is base64(id:random). We'll extract id and ensure it exists.
try {
    $decoded = base64_decode($auth);
    [$admin_id] = explode(':', $decoded, 2) + [null];
} catch (Exception $e) {
    $admin_id = null;
}

if (!$admin_id) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid token']);
    exit;
}

$action = $input['action'] ?? 'list';

if ($action === 'create') {
    $stmt = $pdo->prepare('INSERT INTO items (title,description,username,url) VALUES (:t,:d,:u,:url)');
    $stmt->execute([
        ':t' => $input['title'] ?? '',
        ':d' => $input['description'] ?? null,
        ':u' => $input['username'] ?? null,
        ':url' => $input['url'] ?? null,
    ]);
    echo json_encode(['ok' => true, 'insert_id' => $pdo->lastInsertId()]);
    exit;
}

if ($action === 'update') {
    if (empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing id']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE items SET title=:t,description=:d,username=:u,url=:url WHERE id=:id');
    $stmt->execute([
        ':t' => $input['title'] ?? '',
        ':d' => $input['description'] ?? null,
        ':u' => $input['username'] ?? null,
        ':url' => $input['url'] ?? null,
        ':id' => $input['id'],
    ]);
    echo json_encode(['ok' => true, 'rows' => $stmt->rowCount()]);
    exit;
}

if ($action === 'delete') {
    if (empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing id']);
        exit;
    }
    $stmt = $pdo->prepare('DELETE FROM items WHERE id=:id');
    $stmt->execute([':id' => $input['id']]);
    echo json_encode(['ok' => true, 'rows' => $stmt->rowCount()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown action']);

?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>Items API removed</title>
	<style>body{font-family:Arial,Helvetica,sans-serif;margin:40px;color:#222}</style>
</head>
<body>
	<h1>Items API removed</h1>
	<p>This repository does not provide server-side APIs. The public list is served from <code>data/stuff.json</code>. To change items, edit that file and upload it to the server.</p>
</body>
</html>
session_start();
