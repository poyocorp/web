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
