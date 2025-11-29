<?php
session_start();

// Admin users file (array of admin objects: {user,password,superadmin})
$admin_file = __DIR__ . '/credentials.json';
$admins = [];
if (file_exists($admin_file)) {
    $json = file_get_contents($admin_file);
    $admins = json_decode($json, true) ?: [];
}

// DB credentials are read from a separate file if present
$db_cred_file = __DIR__ . '/db_credentials.json';
$dbCreds = null;
if (file_exists($db_cred_file)) {
    $json2 = file_get_contents($db_cred_file);
    $dbCreds = json_decode($json2, true);
}

$error = '';
$flash = '';
$dbError = '';
// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    // clear session and admin cookie
    session_unset();
    session_destroy();
    setcookie('sprout_admin', '', time() - 3600, '/');
    header('Location: admin.php');
    exit;
}

// Simple login using admin accounts from credentials.json (array)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($admins) || !is_array($admins)) {
        $error = 'No admin accounts configured.';
    } else {
        $found = null;
        foreach ($admins as $a) {
            if (!empty($a['user']) && strtolower($a['user']) === strtolower($username) && isset($a['password']) && $a['password'] === $password) {
                $found = $a;
                break;
            }
        }
        if ($found) {
            $_SESSION['admin'] = true;
            $_SESSION['admin_user'] = $found['user'];
            $_SESSION['is_superadmin'] = !empty($found['superadmin']);
            // set a non-HttpOnly cookie so front-end pages can detect admin presence
            setcookie('sprout_admin', $found['user'], time() + 3600, '/');
            session_regenerate_id(true);
            header('Location: admin.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

// Logged-in flags and username
$logged = !empty($_SESSION['admin']);
$current_user = $logged ? ($_SESSION['admin_user'] ?? '') : '';
$is_superadmin = $logged ? (!empty($_SESSION['is_superadmin'])) : false;

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Admin file utilities
function load_admins_file() {
    $f = __DIR__ . '/credentials.json';
    if (!file_exists($f)) return [];
    $c = json_decode(file_get_contents($f), true);
    return is_array($c) ? $c : [];
}

function save_admins_file($arr) {
    $f = __DIR__ . '/credentials.json';
    file_put_contents($f, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// JSON file utilities
function load_json_file($path) {
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function backup_file($path) {
    if (!file_exists($path)) return;
    $dir = dirname($path);
    $base = basename($path);
    $stamp = date('Ymd_His');
    @copy($path, "$dir/.bak_$base.$stamp");
}

function save_json_file($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    backup_file($path);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// --- MySQL helpers ---
// DB initialization with PDO or mysqli fallback
$pdo = null;
$mysqli = null;
$db_mode = 'json'; // 'pdo' | 'mysqli' | 'json'

function init_db($creds, &$dbError = null) {
    global $pdo, $mysqli, $db_mode;
    $db_mode = 'json';
    $dbError = null;
    if (!$creds) { $dbError = 'Missing credentials.json'; return; }
    $host = $creds['hostname'] ?? '127.0.0.1';
    $user = $creds['user'] ?? '';
    $pass = $creds['password'] ?? '';
    $dbname = $creds['database'] ?? 'sproutpoyo';

    // Try PDO (requires pdo_mysql)
    if (extension_loaded('pdo_mysql')) {
        try {
            $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $db_mode = 'pdo';
            return;
        } catch (PDOException $e) {
            // If DB doesn't exist, try create via PDO without dbname
            $msg = $e->getMessage();
            $dbError = $msg;
            try {
                $dsn2 = "mysql:host={$host};charset=utf8mb4";
                $tmp = new PDO($dsn2, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $tmp->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                // retry
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
                $db_mode = 'pdo';
                $dbError = null;
                return;
            } catch (PDOException $e2) {
                $dbError = $e2->getMessage();
                $pdo = null;
            }
        }
    }

    // Fallback to mysqli if available
    if (extension_loaded('mysqli')) {
        // connect to server
        $m = @new mysqli($host, $user, $pass);
        if ($m->connect_errno) {
            $dbError = $m->connect_error;
            $mysqli = null;
            return;
        }
        // create DB if missing
        if (! $m->select_db($dbname)) {
            if (! $m->query("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
                $dbError = $m->error;
                $m->close();
                $mysqli = null;
                return;
            }
            $m->select_db($dbname);
        }
        // reconnect selecting db
        $m->close();
        $m2 = new mysqli($host, $user, $pass, $dbname);
        if ($m2->connect_errno) { $dbError = $m2->connect_error; $mysqli = null; return; }
        $mysqli = $m2;
        $db_mode = 'mysqli';
        return;
    }

    // If neither MySQL PDO nor mysqli are available, try PDO SQLite if present
    if (extension_loaded('pdo') && (extension_loaded('pdo_sqlite') || in_array('sqlite', PDO::getAvailableDrivers()))) {
        try {
            $dbfile = __DIR__ . '/data/admin.sqlite';
            $dir = dirname($dbfile);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $dsn = 'sqlite:' . $dbfile;
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $db_mode = 'sqlite';
            $dbError = null;
            return;
        } catch (PDOException $e) {
            $dbError = $e->getMessage();
            $pdo = null;
        }
    }

    $dbError = 'No suitable MySQL extensions found (pdo_mysql or mysqli).';
}

function db_ensure_tables() {
    global $pdo, $mysqli, $db_mode;
    if ((($db_mode === 'pdo' || $db_mode === 'sqlite') && $pdo)) {
        if ($db_mode === 'pdo') {
            $sql = "CREATE TABLE IF NOT EXISTS `sprouttube` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `title` TEXT NOT NULL,
      `description` TEXT NOT NULL,
      `username` VARCHAR(255) NOT NULL,
      `url` TEXT NOT NULL,
      `source` TEXT DEFAULT NULL,
      `image` TEXT NOT NULL,
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $sql2 = str_replace('`sprouttube`','`stuff`',$sql);
            $pdo->exec($sql);
            $pdo->exec($sql2);
        } else {
            // sqlite compatible DDL
            $sql = "CREATE TABLE IF NOT EXISTS sprouttube (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      description TEXT NOT NULL,
      username TEXT NOT NULL,
      url TEXT NOT NULL,
      source TEXT DEFAULT NULL,
      image TEXT NOT NULL,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );";
            $sql2 = str_replace('sprouttube','stuff',$sql);
            $pdo->exec($sql);
            $pdo->exec($sql2);
        }
    } elseif ($db_mode === 'mysqli' && $mysqli) {
        $sql = "CREATE TABLE IF NOT EXISTS `sprouttube` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `title` TEXT NOT NULL,
      `description` TEXT NOT NULL,
      `username` VARCHAR(255) NOT NULL,
      `url` TEXT NOT NULL,
      `source` TEXT DEFAULT NULL,
      `image` TEXT NOT NULL,
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $sql2 = str_replace('`sprouttube`','`stuff`',$sql);
        $mysqli->query($sql);
        $mysqli->query($sql2);
    }
}

function db_list_global($table) {
    global $pdo, $mysqli, $db_mode;
    if ((($db_mode === 'pdo' || $db_mode === 'sqlite') && $pdo)) {
        $stmt = $pdo->prepare("SELECT * FROM `$table` ORDER BY id ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    } elseif ($db_mode === 'mysqli' && $mysqli) {
        $res = $mysqli->query("SELECT * FROM `{$table}` ORDER BY id ASC");
        $out = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) $out[] = $row;
            $res->free();
        }
        return $out;
    }
    return [];
}

function db_get_global($table, $id) {
    global $pdo, $mysqli, $db_mode;
    if ((($db_mode === 'pdo' || $db_mode === 'sqlite') && $pdo)) {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        return $stmt->fetch();
    } elseif ($db_mode === 'mysqli' && $mysqli) {
        $stmt = $mysqli->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }
    return null;
}

function db_insert_global($table, $entry) {
    global $pdo, $mysqli, $db_mode;
    if ((($db_mode === 'pdo' || $db_mode === 'sqlite') && $pdo)) {
        $stmt = $pdo->prepare("INSERT INTO `$table` (title,description,username,url,source,image,updated_at) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            $entry['title'], $entry['description'], $entry['username'], $entry['url'], $entry['source'] ?? null, $entry['image'], date('Y-m-d H:i:s')
        ]);
        return $pdo->lastInsertId();
    } elseif ($db_mode === 'mysqli' && $mysqli) {
        $stmt = $mysqli->prepare("INSERT INTO `{$table}` (title,description,username,url,source,image,updated_at) VALUES (?,?,?,?,?, ?, ?)");
        $now = date('Y-m-d H:i:s');
        $stmt->bind_param('sssssss', $entry['title'], $entry['description'], $entry['username'], $entry['url'], $entry['source'], $entry['image'], $now);
        $stmt->execute();
        $id = $mysqli->insert_id;
        $stmt->close();
        return $id;
    }
    return false;
}

function db_update_global($table, $id, $entry) {
    global $pdo, $mysqli, $db_mode;
    if ((($db_mode === 'pdo' || $db_mode === 'sqlite') && $pdo)) {
        $stmt = $pdo->prepare("UPDATE `$table` SET title=?,description=?,username=?,url=?,source=?,image=?,updated_at=? WHERE id=?");
        return $stmt->execute([
            $entry['title'], $entry['description'], $entry['username'], $entry['url'], $entry['source'] ?? null, $entry['image'], date('Y-m-d H:i:s'), (int)$id
        ]);
    } elseif ($db_mode === 'mysqli' && $mysqli) {
        $stmt = $mysqli->prepare("UPDATE `{$table}` SET title=?,description=?,username=?,url=?,source=?,image=?,updated_at=? WHERE id=?");
        $now = date('Y-m-d H:i:s');
        $stmt->bind_param('sssssssi', $entry['title'], $entry['description'], $entry['username'], $entry['url'], $entry['source'], $entry['image'], $now, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
    return false;
}

function db_delete_global($table, $id) {
    global $pdo, $mysqli, $db_mode;
    if ((($db_mode === 'pdo' || $db_mode === 'sqlite') && $pdo)) {
        $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id = ?");
        return $stmt->execute([(int)$id]);
    } elseif ($db_mode === 'mysqli' && $mysqli) {
        $stmt = $mysqli->prepare("DELETE FROM `{$table}` WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
    return false;
}

function migrate_json_to_db_global($table, $jsonPath) {
    global $db_mode;
    if ($db_mode === 'json') return ['imported'=>0,'skipped'=>0];
    $items = load_json_file($jsonPath);
    if (empty($items)) return ['imported'=>0,'skipped'=>0];
    // If table not empty, skip migration to avoid duplicates
    $existing = db_list_global($table);
    if (!empty($existing)) return ['imported'=>0,'skipped'=>count($items)];
    $imported = 0;
    foreach ($items as $it) {
        $entry = [
            'title'=>$it['title'] ?? '',
            'description'=>$it['description'] ?? '',
            'username'=>$it['username'] ?? '',
            'url'=>$it['url'] ?? '',
            'source'=>$it['source'] ?? null,
            'image'=>$it['image'] ?? '',
        ];
        if ($entry['title'] && $entry['description'] && $entry['username'] && $entry['url'] && $entry['image']) {
            db_insert_global($table, $entry);
            $imported++;
        }
    }
    return ['imported'=>$imported,'skipped'=>count($items)-$imported];
}

// Force JSON-only mode (do not use any DB). DB credentials ignored.
$db_mode = 'json';
$pdo = null;
$mysqli = null;
$dbError = '';

// Data paths
$files = [
    'sprouttube' => __DIR__ . '/data/sprouttube.json',
    'stuff' => __DIR__ . '/data/stuff.json',
];

$view = (isset($_REQUEST['view']) && in_array($_REQUEST['view'], ['sprouttube','stuff','admins'])) ? $_REQUEST['view'] : 'sprouttube';

// Handle CRUD actions (only for logged in users)
// Also handle admin-account management when logged-in user is a superadmin
if ($logged) {
    // POST actions: add or save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_entry'])) {
        $target = $_POST['target'] ?? 'sprouttube';
        if (!isset($files[$target])) { $error = 'Invalid target.'; }
        else {
            $path = $files[$target];

            // Validation
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $url = trim($_POST['url'] ?? '');
            $source = trim($_POST['source'] ?? '');
            $image = trim($_POST['image'] ?? '');
            $videoId = trim($_POST['videoId'] ?? $_POST['link'] ?? '');

            $missing = [];
            if ($title === '') $missing[] = 'Title';
            if ($description === '') $missing[] = 'Description';
            // Username optional for sprouttube (we can auto-fetch it); required for other lists
            if ($target !== 'sprouttube' && $username === '') $missing[] = 'Username';
            if ($url === '') $missing[] = 'URL';
            // Image is required for non-sprouttube entries
            if ($target !== 'sprouttube' && $image === '') $missing[] = 'Image';

            if (!empty($missing)) {
                $error = 'Missing required fields: ' . implode(', ', $missing);
            } else {
                $entry = [
                    'title' => $title,
                    'description' => $description,
                    'username' => $username ?: null,
                    'url' => $url,
                    'source' => $source ?: null,
                    'image' => $image ?: null,
                    'videoId' => $videoId ?: null,
                    'updated_at' => date('c'),
                ];

                        if ($db_mode !== 'json') {
                            // Use DB
                            if (isset($_POST['id']) && $_POST['id'] !== '') {
                                $id = (int)$_POST['id'];
                                if (db_update_global($target, $id, $entry)) {
                                    $flash = 'Entry updated (DB).';
                                } else {
                                    $error = 'Failed to update DB entry.';
                                }
                            } else {
                                db_insert_global($target, $entry);
                                $flash = 'Entry added (DB).';
                            }
                        } else {
                    // Fallback to JSON files
                    $list = load_json_file($path);
                    if (isset($_POST['id']) && $_POST['id'] !== '') {
                        $id = (int)$_POST['id'];
                        if (isset($list[$id])) {
                            $list[$id] = $entry;
                            save_json_file($path, array_values($list));
                            $flash = 'Entry updated.';
                        } else {
                            $error = 'Invalid entry id.';
                        }
                    } else {
                        $list[] = $entry;
                        save_json_file($path, array_values($list));
                        $flash = 'Entry added.';
                        }

                // After successful save, redirect back to the target view so we don't fall back to default
                if (!$error) {
                    header('Location: admin.php?view=' . urlencode($target));
                    exit;
                }
                }
            }
        }
    }

    // Admin management (superadmins only)
    if ($is_superadmin) {
        // Create new admin
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
            $newuser = trim($_POST['new_user'] ?? '');
            $newpass = $_POST['new_pass'] ?? '';
            $newsuper = isset($_POST['new_super']) ? true : false;
                if ($newuser === '' || $newpass === '') {
                $error = 'Admin username and password are required.';
            } else {
                $alist = load_admins_file();
                $exists = false;
                foreach ($alist as $a) { if (strtolower(($a['user'] ?? '')) === strtolower($newuser)) { $exists = true; break; } }
                if ($exists) { $error = 'An admin with that username already exists.'; }
                else {
                    $alist[] = ['user'=>$newuser, 'password'=>$newpass, 'superadmin'=>$newsuper];
                    save_admins_file($alist);
                    $flash = 'Admin created.';
                    // refresh in-memory list
                    $admins = $alist;
                    header('Location: admin.php?view=admins'); exit;
                }
            }
        }

        // Update existing admin (password or superadmin)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin'])) {
            $u = $_POST['edit_user'] ?? '';
            $pw = $_POST['edit_pass'] ?? null;
            $sup = isset($_POST['edit_super']) ? true : false;
            if ($u === '') { $error = 'Invalid admin.'; }
            else {
                $alist = load_admins_file();
                $found = false;
                foreach ($alist as $idx => $a) {
                    if (strtolower(($a['user'] ?? '')) === strtolower($u)) {
                        if ($pw !== null && $pw !== '') $alist[$idx]['password'] = $pw;
                        $alist[$idx]['superadmin'] = $sup;
                        $found = true; break;
                    }
                }
                if ($found) {
                    // prevent unsetting the last superadmin
                    $count = 0; foreach ($alist as $aa) { if (!empty($aa['superadmin'])) $count++; }
                    if ($count === 0) { $error = 'There must be at least one superadmin.'; }
                    else {
                        save_admins_file($alist);
                        $admins = $alist;
                        $flash = 'Admin updated.';
                        header('Location: admin.php?view=admins'); exit;
                    }
                } else { $error = 'Admin not found.'; }
            }
        }

        // Delete admin via GET (convenience) but protect current user
        if (isset($_GET['action']) && $_GET['action'] === 'deladmin' && isset($_GET['user'])) {
            $delUser = $_GET['user'];
            if ($delUser === $current_user) { $error = 'You cannot delete your own account while logged in.'; }
            else {
                $alist = load_admins_file();
                $found = false;
                foreach ($alist as $idx => $a) {
                    if (strtolower(($a['user'] ?? '')) === strtolower($delUser)) { unset($alist[$idx]); $found = true; break; }
                }
                if ($found) {
                    $alist = array_values($alist);
                    // Ensure at least one superadmin remains
                    $hasSuper = false; foreach ($alist as $aa) { if (!empty($aa['superadmin'])) { $hasSuper = true; break; } }
                    if (! $hasSuper) { $error = 'Cannot delete this admin: at least one superadmin must remain.'; }
                    else { save_admins_file($alist); $admins = $alist; $flash = 'Admin deleted.'; header('Location: admin.php?view=admins'); exit; }
                } else { $error = 'Admin not found.'; }
            }
        }

        // Toggle superadmin via GET
        if (isset($_GET['action']) && $_GET['action'] === 'togglesuper' && isset($_GET['user'])) {
            $tuser = $_GET['user'];
            $alist = load_admins_file();
            $found = false;
            foreach ($alist as $idx => $a) {
                if (($a['user'] ?? '') === $tuser) {
                    // flip
                    $alist[$idx]['superadmin'] = empty($alist[$idx]['superadmin']) ? true : false;
                    $found = true; break;
                }
            }
            if ($found) {
                // ensure at least one superadmin remains
                $hasSuper = false; foreach ($alist as $aa) { if (!empty($aa['superadmin'])) { $hasSuper = true; break; } }
                if (! $hasSuper) { $error = 'Operation would remove all superadmins; aborted.'; }
                else { save_admins_file($alist); $admins = $alist; $flash = 'Superadmin flag toggled.'; header('Location: admin.php?view=admins'); exit; }
            } else { $error = 'Admin not found.'; }
        }
    }

    // Delete action (GET)
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && isset($_GET['target']) && isset($files[$_GET['target']])) {
        $t = $_GET['target'];
        $id = (int)$_GET['id'];
        if ($db_mode !== 'json') {
            if (db_delete_global($t, $id)) {
                $flash = 'Entry deleted (DB).';
                header('Location: admin.php?view=' . urlencode($t));
                exit;
            } else {
                $error = 'Failed to delete DB entry.';
            }
        } else {
            $path = $files[$t];
            $list = load_json_file($path);
            if (isset($list[$id])) {
                array_splice($list, $id, 1);
                save_json_file($path, array_values($list));
                $flash = 'Entry deleted.';
                // Redirect to avoid resubmission
                header('Location: admin.php?view=' . urlencode($t));
                exit;
            } else {
                $error = 'Invalid id to delete.';
            }
        }
    }

    // Migration: import JSON files into DB when requested
    if ($db_mode !== 'json' && isset($_GET['action']) && $_GET['action'] === 'migrate' && isset($_GET['target']) && isset($files[$_GET['target']])) {
        $t = $_GET['target'];
        $res = migrate_json_to_db_global($t, $files[$t]);
        $flash = 'Migration result: imported=' . $res['imported'] . ', skipped=' . $res['skipped'];
        header('Location: admin.php?view=' . urlencode($t));
        exit;
    }
}

// Helper to render value or empty
function val($arr, $key) { return isset($arr[$key]) ? e($arr[$key]) : ''; }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin — Sprout Poyo</title>
    <link rel="stylesheet" href="web/main.css">
    <style>
        body{background-color:#ffe96b;font-family:Helvetica,Arial,sans-serif;margin:0}
        .wrap{max-width:1100px;margin:28px auto;padding:20px}
        .card{background:#fff;border-radius:12px;padding:18px;box-shadow:0 8px 24px rgba(0,0,0,0.08)}
        .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;padding:8px 12px}
        .tabs{display:flex;gap:8px}
        .tab{padding:10px 14px;border-radius:10px;text-decoration:none;color:#222;background:#fff;border:1px solid rgba(0,0,0,0.04)}
        .tab.active{background:var(--card);color:#000}
        .back-btn{background:#00c37a;color:#fff;border-color:transparent}
        .logout-btn{background:#ff4d4d;color:#fff;border-color:transparent}
        .list{margin-top:12px}
        .item{display:flex;justify-content:space-between;align-items:flex-start;padding:12px;border-bottom:1px dashed #eee}
        .meta{display:flex;gap:12px;flex-wrap:wrap}
        .controls{display:flex;gap:8px}
        .btn{padding:8px 12px;border-radius:8px;border:none;background:var(--accent);color:#002;font-weight:700;cursor:pointer}
        .btn.secondary{background:#ffd366;color:#000}
        form .row{margin-bottom:10px}
        label{display:block;font-weight:700;margin-bottom:6px}
        input[type=text],textarea{width:100%;padding:10px;border-radius:8px;border:1px solid #eee}
        .small{font-size:13px;color:#666}
        .danger{background:#ffdddd;color:#8a1f1f;padding:8px;border-radius:8px}
        /* Admin accounts table spacing improvements */
        #admins table { width:100%; border-collapse: separate; border-spacing: 0 10px; }
        #admins table thead tr th { text-align:left; padding:8px 12px; }
        #admins table tbody tr { background: #fff; border-radius:8px; box-shadow:0 2px 6px rgba(0,0,0,0.03); }
        #admins table tbody tr td { padding:12px; vertical-align:middle; }
        #admins table tbody tr td .tab { margin-right:6px; }
    </style>
</head>
<body>

<div class="wrap">
    <div class="topbar">
        <div style="display:flex;align-items:center;gap:16px">
            <a href="index.html" class="tab back-btn">Back to Site</a>
        </div>
        <div class="tabs" style="display:flex;align-items:center;gap:8px">
            <a class="tab <?php echo $view==='sprouttube'? 'active':'';?>" href="?view=sprouttube">SproutTube</a>
            <a class="tab <?php echo $view==='stuff'? 'active':'';?>" href="?view=stuff">Stuff</a>
            <?php if ($is_superadmin): ?>
                <a class="tab <?php echo $view==='admins'? 'active':'';?>" href="?view=admins">Admin Account</a>
            <?php endif; ?>
            <?php if ($logged): ?>
                <a class="tab logout-btn" href="?action=logout">Logout</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
            <img src="assets/pet-poyo.gif" alt="logo" style="width:56px;height:56px;object-fit:contain">
            <div>
                <h2 style="margin:0;font-family:'KirbyFont',sans-serif">Sprout Poyo — Admin</h2>
                <div class="small">Manage entries for <code>/data/<?php echo e($view); ?>.json</code></div>
            </div>
            
        </div>

        <?php if ($error): ?>
            <div class="danger"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if ($flash): ?>
            <div style="background:#e6ffef;color:#084; padding:8px;border-radius:8px;margin-bottom:12px"><?php echo e($flash); ?></div>
        <?php endif; ?>
            <?php if (!empty($dbError)): ?>
                <div class="small" style="color:#a33;margin-bottom:12px">DB connection: <?php echo e($dbError); ?> — using JSON fallback.</div>
            <?php endif; ?>

        <?php if (! $logged): ?>
            <form method="post" class="card">
                <h3>Sign in to continue</h3>
                <div class="row">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" required>
                </div>
                <div class="row">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" required>
                </div>
                <div style="display:flex;gap:12px;align-items:center">
                    <button class="btn" type="submit" name="login">Sign In</button>
                    <div class="small">Using values from <code>credentials.json</code></div>
                </div>
            </form>
        <?php else: ?>

            <?php
                $path = $files[$view];
                if ($db_mode !== 'json') {
                    $entries = db_list_global($view);
                } else {
                    $entries = load_json_file($path);
                }
            ?>

            <div class="list">
                        <div style="margin-bottom:12px">
                            <button type="button" id="addEntryBtn" class="btn">Add Entry</button>
                        </div>
                <?php if (empty($entries)): ?>
                    <div class="small">No entries yet.</div>
                <?php else: ?>
                    <?php foreach ($entries as $i => $it):
                        // Use DB/SQL id when using DB; for JSON storage always use array index
                        if ($db_mode !== 'json') {
                            $rowId = isset($it['id']) ? $it['id'] : $i;
                        } else {
                            $rowId = $i;
                        }
                    ?>
                        <div class="item">
                            <div>
                                <strong><?php echo e($it['title'] ?? 'Untitled'); ?></strong>
                                <div class="small"><?php echo e($it['description'] ?? ''); ?></div>
                                <div class="small meta">User: <?php echo e($it['username'] ?? ''); ?> · URL: <a href="<?php echo e($it['url'] ?? '#'); ?>" target="_blank"><?php echo e($it['url'] ?? ''); ?></a></div>
                            </div>
                            <div class="controls">
                                <a class="tab" href="?view=<?php echo e($view); ?>&action=edit&id=<?php echo e($rowId); ?>#form">Edit</a>
                                <a class="tab" href="?view=<?php echo e($view); ?>&action=delete&id=<?php echo e($rowId); ?>&target=<?php echo e($view); ?>" onclick="return confirm('Delete this entry?')">Delete</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            </div>

            <div id="form" style="margin-top:20px">
                <?php
                    $editing = false;
                    $editVals = ['title'=>'','description'=>'','username'=>'','url'=>'','source'=>'','image'=>''];
                    $editId = '';
                    // Admin edit defaults (initialize so template won't warn)
                    $editingAdmin = false;
                    $adminEditVals = ['user'=>'','password'=>'','superadmin'=>false];
                    $adminEditUser = '';

                    // If a GET editadmin action is present (navigating to edit an admin), prefill values
                    if ($is_superadmin && isset($_GET['action']) && $_GET['action'] === 'editadmin' && isset($_GET['user'])) {
                        $u = $_GET['user'];
                        $alist = load_admins_file();
                        foreach ($alist as $a) {
                            if (($a['user'] ?? '') === $u) {
                                $editingAdmin = true;
                                $adminEditVals = ['user'=>$a['user'],'password'=>$a['password'],'superadmin'=>!empty($a['superadmin'])];
                                $adminEditUser = $a['user'];
                                break;
                            }
                        }
                    }
                    if (isset($_GET['action']) && $_GET['action']==='edit' && isset($_GET['id'])) {
                        $id = (int)$_GET['id'];
                        if ($db_mode !== 'json') {
                            $row = db_get_global($view, $id);
                            if ($row) {
                                $editing = true;
                                $editVals = $row;
                                $editId = $id;
                            }
                        } else {
                            if (isset($entries[$id])) {
                                $editing = true;
                                $editVals = $entries[$id];
                                $editId = $id;
                            }
                        }
                        // Admin edit prefill (for superadmins)
                        $editingAdmin = false;
                        $adminEditVals = ['user'=>'','password'=>'','superadmin'=>false];
                        $adminEditUser = '';
                        if ($is_superadmin && isset($_GET['action']) && $_GET['action'] === 'editadmin' && isset($_GET['user'])) {
                            $u = $_GET['user'];
                            $alist = load_admins_file();
                            foreach ($alist as $a) {
                                if (($a['user'] ?? '') === $u) {
                                    $editingAdmin = true;
                                    $adminEditVals = ['user'=>$a['user'],'password'=>$a['password'],'superadmin'=>!empty($a['superadmin'])];
                                    $adminEditUser = $a['user'];
                                    break;
                                }
                            }
                        }
                    }
                ?>

                <!-- Hidden form/modal; opened by JS for Add or Edit -->
                <div id="entryModal" class="card" style="display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:90%;max-width:720px;z-index:2000">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <h3 id="modalTitle"></h3>
                        <button onclick="closeModal()" class="tab">Close</button>
                    </div>
                    <form method="post" id="entryForm">
                        <input type="hidden" name="view" value="<?php echo e($view); ?>">
                        <input type="hidden" name="target" value="<?php echo e($view); ?>">
                        <input type="hidden" name="id" id="entry_id" value="<?php echo e($editId ?? ''); ?>">
                        <div id="formFields">
                            <?php if ($view === 'sprouttube'): ?>
                                <div class="row">
                                    <label>Link (YouTube)</label>
                                    <input type="text" name="link" id="link_field" value="<?php echo val($editVals,'url'); ?>">
                                </div>
                                <div class="row">
                                    <label>Title *</label>
                                    <input type="text" name="title" id="title_field" value="<?php echo val($editVals,'title'); ?>" required>
                                </div>
                                <div class="row">
                                    <label>Description *</label>
                                    <textarea name="description" id="desc_field" rows="3" required><?php echo val($editVals,'description'); ?></textarea>
                                </div>
                                <div class="row">
                                    <label>Username</label>
                                    <input type="text" name="username" id="user_field" value="<?php echo val($editVals,'username'); ?>">
                                </div>
                                <div class="row">
                                    <label>Video ID</label>
                                    <input type="text" name="videoId" id="videoid_field" value="<?php echo val($editVals,'videoId'); ?>">
                                </div>
                                <input type="hidden" name="url" id="url_field" value="<?php echo val($editVals,'url'); ?>">
                            <?php else: ?>
                                <div class="row">
                                    <label>Title *</label>
                                    <input type="text" name="title" id="title_field" value="<?php echo val($editVals,'title'); ?>" required>
                                </div>
                                <div class="row">
                                    <label>Description *</label>
                                    <textarea name="description" id="desc_field" rows="3" required><?php echo val($editVals,'description'); ?></textarea>
                                </div>
                                <div class="row">
                                    <label>Username *</label>
                                    <input type="text" name="username" id="user_field" value="<?php echo val($editVals,'username'); ?>" required>
                                </div>
                                <div class="row">
                                    <label>URL *</label>
                                    <input type="text" name="url" id="url_field" value="<?php echo val($editVals,'url'); ?>" required>
                                </div>
                                <div class="row">
                                    <label>Source</label>
                                    <input type="text" name="source" id="source_field" value="<?php echo val($editVals,'source'); ?>">
                                </div>
                                <div class="row">
                                    <label>Image (URL) *</label>
                                    <input type="text" name="image" id="image_field" value="<?php echo val($editVals,'image'); ?>" required>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
                            <button class="btn" type="submit" name="save_entry" id="modalSaveBtn">Save</button>
                            <a class="tab" href="?view=<?php echo e($view); ?>">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($is_superadmin): ?>
            <?php if ($view === 'admins'): ?>
            <div id="admins" style="margin-top:20px">
                <div class="card">
                    <h3>Admin Accounts</h3>
                    <div class="small">Logged in as: <strong><?php echo e($current_user); ?></strong> — Superadmin: <?php echo $is_superadmin? 'Yes':'No'; ?></div>
                    <div style="margin-top:12px">
                        <table style="width:100%;border-collapse:collapse">
                            <thead><tr><th style="text-align:left">Username</th><th>Superadmin</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php
                                $alist = load_admins_file();
                                foreach ($alist as $a):
                            ?>
                                <tr style="border-top:1px solid #f3f3f3">
                                    <td><?php echo e($a['user'] ?? ''); ?></td>
                                    <td style="text-align:center"><?php echo !empty($a['superadmin'])? 'Yes':'No'; ?></td>
                                    <td>
                                        <a class="tab" href="?view=admins&action=editadmin&user=<?php echo urlencode($a['user'] ?? ''); ?>#admins">Edit</a>
                                        <?php if (($a['user'] ?? '') !== $current_user): ?>
                                            <a class="tab" href="?view=admins&action=deladmin&user=<?php echo urlencode($a['user'] ?? ''); ?>" onclick="return confirm('Delete admin <?php echo e($a['user'] ?? ''); ?>?')">Delete</a>
                                        <?php else: ?>
                                            <span class="small">(current)</span>
                                        <?php endif; ?>
                                        <a class="tab" href="?view=admins&action=togglesuper&user=<?php echo urlencode($a['user'] ?? ''); ?>#admins">Toggle Super</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top:18px">
                        <h4><?php echo $editingAdmin ? 'Edit Admin' : 'Create New Admin'; ?></h4>
                        <form method="post">
                            <input type="hidden" name="view" value="admins">
                            <?php if ($editingAdmin): ?>
                                <input type="hidden" name="edit_user" value="<?php echo e($adminEditUser); ?>">
                            <?php endif; ?>
                            <div class="row">
                                <label>Username *</label>
                                <?php if ($editingAdmin): ?>
                                    <input type="text" value="<?php echo e($adminEditVals['user']); ?>" disabled>
                                <?php else: ?>
                                    <input type="text" name="new_user" value="" required>
                                <?php endif; ?>
                            </div>
                            <div class="row">
                                <label><?php echo $editingAdmin ? 'New Password (leave blank to keep)' : 'Password *'; ?></label>
                                <?php if ($editingAdmin): ?>
                                    <input type="text" name="edit_pass" value="">
                                <?php else: ?>
                                    <input type="text" name="new_pass" value="" required>
                                <?php endif; ?>
                            </div>
                            <div class="row">
                                <label><input type="checkbox" name="<?php echo $editingAdmin? 'edit_super':'new_super'; ?>" <?php echo ($editingAdmin && !empty($adminEditVals['superadmin']))? 'checked':''; ?>> Superadmin</label>
                            </div>
                            <div style="display:flex;gap:8px;align-items:center">
                                <?php if ($editingAdmin): ?>
                                    <button class="btn" type="submit" name="update_admin">Save Admin</button>
                                    <a class="tab" href="admin.php#admins">Cancel</a>
                                <?php else: ?>
                                    <button class="btn" type="submit" name="create_admin">Create Admin</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

    <script>
        // Modal helpers
        function openModalForAdd(){
            document.getElementById('modalTitle').textContent = 'Add Entry';
            document.getElementById('entry_id').value = '';
            // clear fields
            var ids = ['title_field','desc_field','user_field','url_field','videoid_field','image_field','source_field','link_field'];
            ids.forEach(function(id){ var el = document.getElementById(id); if(el) el.value = ''; });
            document.getElementById('entryModal').style.display = 'block';
        }
        function closeModal(){ var m=document.getElementById('entryModal'); if(m) m.style.display = 'none'; }

        // Open modal on edit when server set $editing
        <?php if (!empty($editing) && $editing): ?>
        document.addEventListener('DOMContentLoaded', function(){
            document.getElementById('modalTitle').textContent = 'Edit Entry';
            var eid = '<?php echo e($editId); ?>';
            if(document.getElementById('entry_id')) document.getElementById('entry_id').value = eid;
            var m=document.getElementById('entryModal'); if(m) m.style.display = 'block';
        });
        <?php endif; ?>

        // sprouttube: extract YouTube id and fetch title/author via oEmbed
        function extractYouTubeId(url){
            if(!url) return '';
            var m = url.match(/(?:v=|\/videos\/|embed\/|youtu\.be\/)([A-Za-z0-9_-]{6,11})/);
            return m ? m[1] : '';
        }
        function handleLinkInput(){
            var lf = document.getElementById('link_field'); if(!lf) return;
            var val = lf.value.trim(); if(!val) return;
            var id = extractYouTubeId(val);
            if(id){
                var urlField = document.getElementById('url_field'); if(urlField) urlField.value = 'https://youtu.be/'+id;
                var vidField = document.getElementById('videoid_field'); if(vidField) vidField.value = id;
                var oembed = 'https://www.youtube.com/oembed?url=' + encodeURIComponent(val) + '&format=json';
                fetch(oembed).then(function(r){ if(!r.ok) throw r; return r.json(); }).then(function(data){
                    if(data.title){ var t = document.getElementById('title_field'); if(t && !t.value) t.value = data.title; }
                    if(data.author_name){ var u = document.getElementById('user_field'); if(u && !u.value) u.value = data.author_name; }
                }).catch(function(){});
            }
        }
        document.addEventListener('input', function(e){ if(e.target && e.target.id === 'link_field'){ handleLinkInput(); } });

        // Attach click handler to Add Entry button (fallback for inline onclick)
        document.addEventListener('DOMContentLoaded', function(){
            var b = document.getElementById('addEntryBtn');
            if (b) b.addEventListener('click', function(e){ e.preventDefault(); openModalForAdd(); });
            // Ensure before submit the url/videoId are set from link if present
            var form = document.getElementById('entryForm');
            if (form) {
                form.addEventListener('submit', function(ev){
                    var lf = document.getElementById('link_field');
                    if (lf && lf.value.trim()) {
                        handleLinkInput();
                    }
                });
            }
        });
    </script>

</body>
</html>
