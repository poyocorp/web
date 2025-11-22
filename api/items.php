<?php
// Items API: GET public list, POST/PUT/DELETE require auth + CSRF token
header('Content-Type: application/json; charset=utf-8');
session_start();

$use_db = false; $pdo = null;
if (file_exists(__DIR__ . '/../config.php')){
    try{ $cfg = include __DIR__ . '/../config.php'; if (is_array($cfg) && isset($cfg['dsn'])){ $opts = $cfg['options'] ?? []; $pdo = new PDO($cfg['dsn'], $cfg['user'] ?? null, $cfg['pass'] ?? null, $opts); $use_db = true; } }catch(Exception $e){ $use_db=false; $pdo=null; }
}

$dataDir = __DIR__ . '/../data'; if (!is_dir($dataDir)) mkdir($dataDir,0755,true);
$stuffFile = $dataDir . '/stuff.json';

function read_json_items($file){ if(!file_exists($file)) return []; $s=file_get_contents($file); $a=json_decode($s,true); return is_array($a)?$a:[]; }
function write_json_items($file,$arr){ file_put_contents($file,json_encode(array_values($arr),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }

// GET: return items
if ($_SERVER['REQUEST_METHOD'] === 'GET'){
    if ($use_db && $pdo){ $stmt = $pdo->query('SELECT id, title, description, username, url FROM items ORDER BY id ASC'); $items = $stmt->fetchAll(PDO::FETCH_ASSOC); }
    else { $items = read_json_items($stuffFile); }
    echo json_encode(['ok'=>true,'items'=>$items]); exit;
}

// For state-changing methods require logged-in and CSRF
$logged = isset($_SESSION['logged_in']) && $_SESSION['logged_in'];
$token = $_SESSION['token'] ?? '';
$hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_SERVER['HTTP_X_CSRFTOKEN'] ?? '');
if (!$logged || !hash_equals($token, $hdr)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'auth_required']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_SERVER['REQUEST_METHOD'];

if ($action === 'POST'){
    $title = substr(trim($body['title'] ?? ''),0,200);
    $description = substr(trim($body['description'] ?? ''),0,1000);
    $username = substr(trim($body['username'] ?? ''),0,100);
    $url = substr(trim($body['url'] ?? ''),0,1000);
    if ($use_db && $pdo){ $stmt=$pdo->prepare('INSERT INTO items (title,description,username,url) VALUES (:t,:d,:u,:url)'); $stmt->execute([':t'=>$title,':d'=>$description,':u'=>$username,':url'=>$url]); $id=$pdo->lastInsertId(); echo json_encode(['ok'=>true,'item'=>['id'=>$id,'title'=>$title,'description'=>$description,'username'=>$username,'url'=>$url]]); }
    else { $items = read_json_items($stuffFile); $next=1; foreach($items as $it) $next = max($next,$it['id']+1); $item=['id'=>$next,'title'=>$title,'description'=>$description,'username'=>$username,'url'=>$url]; $items[]=$item; write_json_items($stuffFile,$items); echo json_encode(['ok'=>true,'item'=>$item]); }
    exit;
}

if ($action === 'PUT'){
    $id = (int)($body['id'] ?? 0);
    if ($use_db && $pdo){ $stmt=$pdo->prepare('UPDATE items SET title=:t,description=:d,username=:u,url=:url WHERE id=:id'); $stmt->execute([':t'=>$body['title']??'',' :d'=>$body['description']??'',' :u'=>$body['username']??'',' :url'=>$body['url']??'',' :id'=>$id]); echo json_encode(['ok'=>true]); }
    else { $items=read_json_items($stuffFile); foreach($items as &$it){ if($it['id']===$id){ $it['title']=$body['title']??$it['title']; $it['description']=$body['description']??$it['description']; $it['username']=$body['username']??$it['username']; $it['url']=$body['url']??$it['url']; break; } } write_json_items($stuffFile,$items); echo json_encode(['ok'=>true]); }
    exit;
}

if ($action === 'DELETE'){
    $id = (int)($body['id'] ?? 0);
    if ($use_db && $pdo){ $stmt=$pdo->prepare('DELETE FROM items WHERE id=:id'); $stmt->execute([':id'=>$id]); echo json_encode(['ok'=>true]); }
    else { $items=read_json_items($stuffFile); $items=array_values(array_filter($items,function($i)use($id){return $i['id']!==$id;})); write_json_items($stuffFile,$items); echo json_encode(['ok'=>true]); }
    exit;
}

http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unsupported_method']); exit;
