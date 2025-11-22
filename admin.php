<?php
// admin.php — updated to support MySQL (PDO) with a JSON fallback
session_start();

$use_db = false;
$pdo = null;

// Attempt to load DB config if present
if (file_exists(__DIR__ . '/config.php')){
  try {
    $cfg = include __DIR__ . '/config.php';
    if (is_array($cfg) && isset($cfg['dsn'])){
      $opts = $cfg['options'] ?? [];
      $pdo = new PDO($cfg['dsn'], $cfg['user'] ?? null, $cfg['pass'] ?? null, $opts);
      $use_db = true;
    }
  } catch (Exception $e) {
    // If DB connection fails, fall back to JSON storage
    $use_db = false;
    $pdo = null;
  }
}

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) { mkdir($dataDir, 0755, true); }
$adminFile = $dataDir . '/admin.json';
$stuffFile = $dataDir . '/stuff.json';

// Helper: JSON fallback readers/writers
function read_stuff_json($file){ if(!file_exists($file)) return []; $s = file_get_contents($file); $a = json_decode($s, true); return is_array($a) ? $a : []; }
function write_stuff_json($file, $arr){ file_put_contents($file, json_encode(array_values($arr), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }

// CSRF token
if (!isset($_SESSION['token'])) $_SESSION['token'] = bin2hex(random_bytes(16));

// Utility: load items either from DB or JSON
function load_items(){ global $use_db, $pdo, $stuffFile; if ($use_db && $pdo){
  $stmt = $pdo->query('SELECT id, title, description, username, url FROM items ORDER BY id ASC');
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
} return read_stuff_json($stuffFile); }

// Utility: count admins in DB or check admin file
function admin_exists(){ global $use_db, $pdo, $adminFile; if ($use_db && $pdo){
  $stmt = $pdo->query('SELECT COUNT(*) as c FROM admins'); $row = $stmt->fetch(PDO::FETCH_ASSOC); return !empty($row) && $row['c']>0;
} return file_exists($adminFile); }

// Create admin (setup)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup']) && !admin_exists()){
  $user = trim($_POST['username'] ?? '');
  $pass = $_POST['password'] ?? '';
  if ($user && $pass){
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    if ($use_db && $pdo){
      $stmt = $pdo->prepare('INSERT INTO admins (username, pass_hash) VALUES (:u, :h)');
      $stmt->execute([':u'=>$user, ':h'=>$hash]);
    } else {
      $data = ['user' => $user, 'hash' => $hash];
      file_put_contents($adminFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    $_SESSION['logged_in'] = true; $_SESSION['user'] = $user; header('Location: admin.php'); exit;
  }
}

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login']) && admin_exists()){
  $user = $_POST['username'] ?? ''; $pass = $_POST['password'] ?? '';
  $ok = false;
  if ($use_db && $pdo){
    $stmt = $pdo->prepare('SELECT username, pass_hash FROM admins WHERE username = :u LIMIT 1');
    $stmt->execute([':u'=>$user]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($pass, $row['pass_hash'])) $ok = true;
  } else {
    $creds = json_decode(file_get_contents($adminFile), true);
    if ($creds && password_verify($pass, $creds['hash']) && $user === $creds['user']) $ok = true;
  }
  if ($ok){ $_SESSION['logged_in'] = true; $_SESSION['user'] = $user; header('Location: admin.php'); exit; } else { $error = 'Invalid credentials'; }
}

// Logout
if (isset($_GET['logout'])){ session_destroy(); header('Location: admin.php'); exit; }

// Authenticated actions (add/edit/delete) use prepared statements when DB is available
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']){
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && hash_equals($_SESSION['token'], $_POST['token'] ?? '')){
    $action = $_POST['action'];
    if ($action === 'add'){
      $title = substr(trim($_POST['title'] ?? ''),0,200);
      $description = substr(trim($_POST['description'] ?? ''),0,1000);
      $username = substr(trim($_POST['username'] ?? ''),0,100);
      $url = substr(trim($_POST['url'] ?? ''),0,1000);
      if ($use_db && $pdo){
        $stmt = $pdo->prepare('INSERT INTO items (title, description, username, url) VALUES (:t, :d, :u, :url)');
        $stmt->execute([':t'=>$title, ':d'=>$description, ':u'=>$username, ':url'=>$url]);
      } else {
        $items = read_stuff_json($stuffFile);
        $next = 1; foreach($items as $it) $next = max($next, $it['id'] + 1);
        $items[] = ['id'=>$next,'title'=>$title,'description'=>$description,'username'=>$username,'url'=>$url];
        write_stuff_json($stuffFile, $items);
      }
      header('Location: admin.php'); exit;
    }
    if ($action === 'delete'){
      $id = (int)($_POST['id'] ?? 0);
      if ($use_db && $pdo){
        $stmt = $pdo->prepare('DELETE FROM items WHERE id = :id'); $stmt->execute([':id'=>$id]);
      } else {
        $items = read_stuff_json($stuffFile);
        $items = array_values(array_filter($items, function($i) use($id){ return $i['id'] !== $id; }));
        write_stuff_json($stuffFile, $items);
      }
      header('Location: admin.php'); exit;
    }
    if ($action === 'edit'){
      $id = (int)($_POST['id'] ?? 0);
      $title = substr(trim($_POST['title'] ?? ''),0,200);
      $description = substr(trim($_POST['description'] ?? ''),0,1000);
      $username = substr(trim($_POST['username'] ?? ''),0,100);
      $url = substr(trim($_POST['url'] ?? ''),0,1000);
      if ($use_db && $pdo){
        $stmt = $pdo->prepare('UPDATE items SET title=:t, description=:d, username=:u, url=:url WHERE id=:id');
        $stmt->execute([':t'=>$title, ':d'=>$description, ':u'=>$username, ':url'=>$url, ':id'=>$id]);
      } else {
        $items = read_stuff_json($stuffFile);
        foreach($items as &$it){ if($it['id'] === $id){ $it['title']=$title; $it['description']=$description; $it['username']=$username; $it['url']=$url; break; } }
        write_stuff_json($stuffFile, $items);
      }
      header('Location: admin.php'); exit;
    }
  }
}

$items = load_items();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin</title>
  <style>
    body{ font-family: Arial, sans-serif; background:#f7f7f8; color:#111; margin:0; padding:20px }
    .box{ max-width:980px; margin:12px auto; background:#fff; border-radius:10px; padding:18px; box-shadow:0 6px 18px rgba(0,0,0,0.06) }
    h1{ margin:0 0 12px }
    form{ display:flex; gap:12px; flex-wrap:wrap }
    input[type=text], input[type=url], input[type=password], textarea{ padding:10px; border-radius:8px; border:1px solid #e4e4e4; min-width:200px }
    textarea{ min-height:80px; resize:vertical }
    button{ background:#2274a5; color:#fff; border:none; padding:10px 14px; border-radius:8px; cursor:pointer }
    .item{ display:flex; justify-content:space-between; gap:12px; padding:12px; border-bottom:1px solid #f0f0f0 }
    .controls{ display:flex; gap:8px }
    .small{ padding:6px 8px; font-size:14px }
    .danger{ background:#e05858 }
    .muted{ color:#666 }
    .nav{ display:flex; justify-content:space-between; align-items:center; margin-bottom:12px }
    a.link{ text-decoration:none; color:#2274a5 }
  </style>
</head>
<body>
  <div class="box">
    <div class="nav">
      <h1>Admin</h1>
      <div>
        <a class="link" href="/">Home</a>
        <?php if(isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>
          | <a class="link" href="?logout=1">Logout</a>
        <?php endif; ?>
      </div>
    </div>

<?php if (!file_exists($adminFile)): ?>
  <p class="muted">No admin user configured. Create an admin account now.</p>
  <form method="post" class="setup">
    <input name="username" placeholder="admin username" required>
    <input name="password" type="password" placeholder="password" required>
    <button name="setup">Create Admin</button>
  </form>
<?php elseif (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']): ?>
  <?php if (!empty($error)) echo '<p style="color:#a33">'.htmlspecialchars($error).'</p>'; ?>
  <p class="muted">Please log in to manage items.</p>
  <form method="post">
    <input name="username" placeholder="username" required>
    <input name="password" type="password" placeholder="password" required>
    <button name="login">Log In</button>
  </form>
<?php else: ?>

  <section>
    <h2 style="margin-top:8px">Add New Item</h2>
    <form method="post">
      <input type="hidden" name="token" value="<?=htmlspecialchars($_SESSION['token'])?>">
      <input type="hidden" name="action" value="add">
      <input name="title" placeholder="Title" required>
      <input name="username" placeholder="@username">
      <input name="url" type="url" placeholder="https://...">
      <textarea name="description" placeholder="Short description"></textarea>
      <button type="submit">Add</button>
    </form>
  </section>

  <section style="margin-top:18px">
    <h2>Existing Items</h2>
    <?php if (empty($items)): ?>
      <p class="muted">No items yet.</p>
    <?php else: foreach($items as $it): ?>
      <div class="item">
        <div>
          <strong><?=htmlspecialchars($it['title'])?></strong>
          <div class="muted"><?=htmlspecialchars($it['description'])?></div>
        </div>
        <div class="controls">
          <form method="post" style="display:inline">
            <input type="hidden" name="token" value="<?=htmlspecialchars($_SESSION['token'])?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?=intval($it['id'])?>">
            <button class="small danger" type="submit">Delete</button>
          </form>
          <button class="small" onclick="openEdit(<?=intval($it['id'])?>)">Edit</button>
          <a class="small" href="<?=htmlspecialchars($it['url'] ?? '#')?>" target="_blank">Open</a>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </section>

  <div id="editModal" style="display:none; margin-top:18px">
    <h3>Edit Item</h3>
    <form method="post" id="editForm">
      <input type="hidden" name="token" value="<?=htmlspecialchars($_SESSION['token'])?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editId">
      <input name="title" id="editTitle" placeholder="Title" required>
      <input name="username" id="editUser" placeholder="@username">
      <input name="url" id="editUrl" type="url" placeholder="https://...">
      <textarea name="description" id="editDesc" placeholder="Short description"></textarea>
      <button type="submit">Save</button>
      <button type="button" onclick="closeEdit()">Cancel</button>
    </form>
  </div>

  <script>
    const items = <?=json_encode($items, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
    function openEdit(id){
      const it = items.find(x=>x.id==id); if(!it) return;
      document.getElementById('editId').value = it.id;
      document.getElementById('editTitle').value = it.title || '';
      document.getElementById('editDesc').value = it.description || '';
      document.getElementById('editUser').value = it.username || '';
      document.getElementById('editUrl').value = it.url || '';
      document.getElementById('editModal').style.display = 'block';
      window.scrollTo({top:document.body.scrollHeight, behavior:'smooth'});
    }
    function closeEdit(){ document.getElementById('editModal').style.display='none'; }
  </script>

<?php endif; ?>
  </div>
</body>
</html>
