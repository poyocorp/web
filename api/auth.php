<?php
// Simple auth API for admin (JSON responses)
header('Content-Type: application/json; charset=utf-8');
session_start();

$use_db = false; $pdo = null;
if (file_exists(__DIR__ . '/../config.php')){
    try{
        $cfg = include __DIR__ . '/../config.php';
        if (is_array($cfg) && isset($cfg['dsn'])){
            $opts = $cfg['options'] ?? [];
            $pdo = new PDO($cfg['dsn'], $cfg['user'] ?? null, $cfg['pass'] ?? null, $opts);
            $use_db = true;
        }
    }catch(Exception $e){ $use_db = false; $pdo = null; }
}

$dataDir = __DIR__ . '/../data';
$adminFile = $dataDir . '/admin.json';
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);

function admin_exists_db($pdo){ $stmt = $pdo->query('SELECT COUNT(*) AS c FROM admins'); $r = $stmt->fetch(PDO::FETCH_ASSOC); return !empty($r) && $r['c']>0; }

// GET: status
if ($_SERVER['REQUEST_METHOD'] === 'GET'){
    if (isset($_GET['logout'])){ session_destroy(); echo json_encode(['ok'=>true,'logged_in'=>false]); exit; }
    $logged = isset($_SESSION['logged_in']) && $_SESSION['logged_in'];
    $token = $logged ? ($_SESSION['token'] ?? '') : '';
    echo json_encode(['ok'=>true,'logged_in'=>$logged,'token'=>$token,'admin_exists'=>($use_db && $pdo) ? admin_exists_db($pdo) : file_exists($adminFile)]);
    exit;
}

// POST: login or setup
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? ($_POST['login'] ? 'login' : ($input['setup'] ?? null));

if ($action === 'login'){
    $user = $input['username'] ?? '';
    $pass = $input['password'] ?? '';
    $ok = false;
    if ($use_db && $pdo){
        $stmt = $pdo->prepare('SELECT username, pass_hash FROM admins WHERE username = :u LIMIT 1');
        $stmt->execute([':u'=>$user]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($pass, $row['pass_hash'])) $ok = true;
    } else {
        if (file_exists($adminFile)){
            $creds = json_decode(file_get_contents($adminFile), true);
            if ($creds && password_verify($pass, $creds['hash']) && $user === $creds['user']) $ok = true;
        }
    }
    if ($ok){ $_SESSION['logged_in']=true; $_SESSION['user']=$user; if (!isset($_SESSION['token'])) $_SESSION['token']=bin2hex(random_bytes(16)); echo json_encode(['ok'=>true,'logged_in'=>true,'token'=>$_SESSION['token']]); }
    else { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Invalid credentials']); }
    exit;
}

if ($action === 'setup'){
    $user = trim($input['username'] ?? ''); $pass = $input['password'] ?? '';
    if (!$user || !$pass){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'username/password required']); exit; }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    if ($use_db && $pdo){
        $stmt = $pdo->prepare('INSERT INTO admins (username, pass_hash) VALUES (:u, :h)');
        $stmt->execute([':u'=>$user,':h'=>$hash]);
    } else {
        file_put_contents($adminFile, json_encode(['user'=>$user,'hash'=>$hash], JSON_PRETTY_PRINT));
    }
    $_SESSION['logged_in']=true; $_SESSION['user']=$user; if (!isset($_SESSION['token'])) $_SESSION['token']=bin2hex(random_bytes(16));
    echo json_encode(['ok'=>true,'logged_in'=>true,'token'=>$_SESSION['token']]); exit;
}

http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unknown action']); exit;
