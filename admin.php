<?php
require_once __DIR__ . '/config.sample.php';

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

// Support token auth header or username/password in POST body
$auth = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($input['token'] ?? null);
$username = $input['username'] ?? null;
$password = $input['password'] ?? null;

$admin_id = null;
if ($auth) {
    $decoded = @base64_decode($auth);
    if ($decoded) {
        [$aid] = explode(':', $decoded, 2) + [null];
        if ($aid) $admin_id = (int)$aid;
    }
}

if (!$admin_id) {
    if (!$username || !$password) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'missing auth']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id,pass_hash FROM admins WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['pass_hash'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid credentials']);
        exit;
    }
    $admin_id = (int)$user['id'];
}

$action = $input['action'] ?? null;
if (!$action) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing action']);
    exit;
}

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

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin (moved)</title>
  <style>body{font-family:Arial,Helvetica,sans-serif;margin:40px;color:#222}</style>
</head>
<body>
  <h1>Admin removed</h1>
  <p>This site no longer uses PHP. Use the static admin editor at <a href="/admin.html">admin.html</a> to edit items in your browser. To persist changes on the server, download the JSON and upload it to <code>data/stuff.json</code> via your file manager or FTP.</p>
</body>
</html>
