<?php
declare(strict_types=1);

session_start();

$root = dirname(__DIR__);
$dataDir = $root . '/storage';
$configFile = $dataDir . '/config.php';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0750, true);
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function slug(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: bin2hex(random_bytes(4));
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function verifyCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('İstek doğrulanamadı.');
    }
}

function layout(string $title, string $content, array $settings = [], bool $admin = false): void
{
    $siteTitle = $settings['site_title'] ?? 'Blog Ve1';
    $description = $settings['site_description'] ?? '';
    $keywords = $settings['keywords'] ?? '';
    $seoDescription = $settings['seo_description'] ?? $description;
    $logo = $settings['logo'] ?? '';
    $themeCss = $settings['theme_css'] ?? '';
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' - ' . h($siteTitle) . '</title>';
    echo '<meta name="description" content="' . h($seoDescription) . '"><meta name="keywords" content="' . h($keywords) . '">';
    if ($themeCss !== '') {
        echo '<style>' . preg_replace('~</style~i', '<\\/style', $themeCss) . '</style>';
    } else {
        echo '<style>
            :root{font-family:system-ui,sans-serif;color:#202532;background:#f4f6fa}*{box-sizing:border-box}
            body{margin:0}header{background:#172554;color:white;padding:1rem max(1rem,calc((100% - 960px)/2));}
            header a{color:white;text-decoration:none}main{max-width:960px;margin:2rem auto;padding:0 1rem}
            .card{background:#fff;border:1px solid #dce1ea;border-radius:10px;padding:1.25rem;margin:1rem 0}
            nav{display:flex;gap:1rem;flex-wrap:wrap;margin:.5rem 0}form{margin:.7rem 0}
            input,textarea,select,button{font:inherit;padding:.65rem;border:1px solid #bbc3d0;border-radius:5px;max-width:100%}
            input[type=text],input[type=password],input[type=email],input[type=url],input[type=number],textarea,select{width:100%;margin:.25rem 0 .65rem}
            textarea{min-height:120px}button,.button{background:#1d4ed8;color:white;border:0;cursor:pointer;text-decoration:none;padding:.65rem 1rem}
            table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:.55rem;border-bottom:1px solid #e5e7eb;vertical-align:top}
            .muted{color:#667085}.error{color:#b42318}.success{color:#067647}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem}
            article{line-height:1.7}.logo{max-height:48px;max-width:180px;vertical-align:middle;margin-right:.6rem}
            </style>';
    }
    echo '</head><body><header><a href="/">';
    if ($logo !== '') {
        echo '<img class="logo" src="/media/' . rawurlencode(basename($logo)) . '" alt="' . h($siteTitle) . '">';
    }
    echo '<strong>' . h($siteTitle) . '</strong></a><nav><a href="/">Ana Sayfa</a><a href="/ara">Ara</a>';
    foreach ($GLOBALS['publicCategories'] ?? [] as $category) {
        echo '<a href="/kategori/' . rawurlencode($category['slug']) . '">' . h($category['name']) . '</a>';
    }
    if ($admin) {
        echo '<a href="' . h($settings['admin_path'] ?? '/yonetim') . '">Yönetim</a>';
    }
    echo '</nav></header><main>' . $content . '</main></body></html>';
}

function field(string $label, string $name, string $value = '', string $type = 'text'): string
{
    return '<label>' . h($label) . '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '"></label>';
}

function dbConnect(array $config): PDO
{
    $host = (string) ($config['host'] ?? '');
    $port = (string) ($config['port'] ?? '3306');
    $database = (string) ($config['database'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535 || !preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
        throw new RuntimeException('MySQL sunucusu, portu veya veritabanı adı geçersiz.');
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function installSchema(PDO $db): void
{
    $statements = [
        'CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(100) PRIMARY KEY, value TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS categories (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, slug VARCHAR(190) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        "CREATE TABLE IF NOT EXISTS posts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id INT UNSIGNED NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE, content MEDIUMTEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT posts_category_fk FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT, INDEX posts_status_created (status, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'CREATE TABLE IF NOT EXISTS forms (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, fields_json TEXT NOT NULL, post_id INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT forms_post_fk FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS messages (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, form_id INT UNSIGNED NOT NULL, post_id INT UNSIGNED NULL, values_json MEDIUMTEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT messages_form_fk FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE, CONSTRAINT messages_post_fk FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS polls (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, question VARCHAR(500) NOT NULL, options_json TEXT NOT NULL, post_id INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT polls_post_fk FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS poll_votes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, poll_id INT UNSIGNED NOT NULL, option_index INT UNSIGNED NOT NULL, voter_hash CHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY poll_one_vote (poll_id, voter_hash), CONSTRAINT votes_poll_fk FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS extensions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) NOT NULL, name VARCHAR(190) NOT NULL, directory VARCHAR(190) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 0, metadata TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];
    foreach ($statements as $statement) {
        $db->exec($statement);
    }
}

function settings(PDO $db): array
{
    $result = [];
    foreach ($db->query('SELECT `key`, value FROM settings') as $row) {
        $result[$row['key']] = $row['value'];
    }
    return $result;
}

function saveSettings(PDO $db, array $values): void
{
    $statement = $db->prepare('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    foreach ($values as $key => $value) {
        $statement->execute([$key, (string) $value]);
    }
}

function adminPath(array $settings): string
{
    $path = trim($settings['admin_path'] ?? 'yonetim', '/');
    return '/' . (preg_match('/^[a-zA-Z0-9_-]{3,80}$/', $path) ? $path : 'yonetim');
}

function adminOnly(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect((string) ($GLOBALS['adminUrl'] ?? '/yonetim') . '/giris');
    }
}

function tableRows(PDO $db, string $sql): array
{
    return $db->query($sql)->fetchAll();
}

function packageUpload(PDO $db, string $type, string $storage): string
{
    if (!class_exists(ZipArchive::class) || ($_FILES['package']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Geçerli bir ZIP paketi seçin.');
    }
    if ($_FILES['package']['size'] > 20 * 1024 * 1024 || strtolower(pathinfo($_FILES['package']['name'], PATHINFO_EXTENSION)) !== 'zip') {
        throw new RuntimeException('Paket ZIP olmalı ve 20 MB sınırını aşmamalı.');
    }
    $zip = new ZipArchive();
    if ($zip->open($_FILES['package']['tmp_name']) !== true) {
        throw new RuntimeException('ZIP dosyası okunamadı.');
    }
    $rootName = '';
    $entries = [];
    $totalSize = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        $stat = $zip->statIndex($i);
        $system = 0;
        $attributes = 0;
        $zip->getExternalAttributesIndex($i, $system, $attributes);
        $entryPath = str_replace('\\', '/', (string) $entry);
        $unixType = (((int) $attributes >> 16) & 0170000);
        if ($entry === false || $stat === false || str_contains($entryPath, "\0") || str_starts_with($entryPath, '/') || preg_match('/^[a-zA-Z]:/', $entryPath) || preg_match('~(^|/)\.\.(/|$)~', $entryPath) || ($system === ZipArchive::OPSYS_UNIX && $unixType === 0120000)) {
            $zip->close();
            throw new RuntimeException('Güvenli olmayan ZIP yolu.');
        }
        $totalSize += (int) $stat['size'];
        if ($totalSize > 50 * 1024 * 1024 || $zip->numFiles > 2000) {
            $zip->close();
            throw new RuntimeException('Paket açılmış halde 50 MB veya 2.000 dosya sınırını aşamaz.');
        }
        $entries[] = [$entry, $entryPath, $stat];
        $first = explode('/', trim($entryPath, '/'))[0] ?? '';
        if ($first !== '') {
            $rootName = $first;
        }
    }
    $rootName = slug($rootName ?: pathinfo($_FILES['package']['name'], PATHINFO_FILENAME));
    $target = $storage . '/extensions/' . $rootName;
    if (is_dir($target) || is_file($target)) {
        $rootName .= '-' . bin2hex(random_bytes(3));
        $target = $storage . '/extensions/' . $rootName;
    }
    if (!mkdir($target, 0750, true)) {
        $zip->close();
        throw new RuntimeException('Paket dizini oluşturulamadı.');
    }
    foreach ($entries as [$originalPath, $entryPath, $stat]) {
        $destination = $target . '/' . $entryPath;
        if (str_ends_with($entryPath, '/')) {
            if (!is_dir($destination) && !mkdir($destination, 0750, true)) {
                $zip->close();
                throw new RuntimeException('Paket dizini oluşturulamadı.');
            }
            continue;
        }
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0750, true)) {
            $zip->close();
            throw new RuntimeException('Paket dizini oluşturulamadı.');
        }
        $source = $zip->getStream($originalPath);
        if ($source === false) {
            $zip->close();
            throw new RuntimeException('Paket dosyası okunamadı.');
        }
        $output = fopen($destination, 'wb');
        if ($output === false) {
            fclose($source);
            $zip->close();
            throw new RuntimeException('Paket dosyası yazılamadı.');
        }
        stream_copy_to_stream($source, $output, (int) $stat['size']);
        fclose($source);
        fclose($output);
    }
    $zip->close();
    $metadata = '';
    $name = $rootName;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $base = $file->getFilename();
        if ($type === 'theme' && $base === 'style.css') {
            $metadata = (string) file_get_contents($file->getPathname());
            if (preg_match('/^\s*Theme Name\s*:\s*(.+)$/mi', $metadata, $match)) {
                $name = trim($match[1]);
            }
            break;
        }
        if ($type === 'plugin' && str_ends_with(strtolower($base), '.php')) {
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/^\s*\*\s*Plugin Name\s*:\s*(.+)$/mi', $contents, $match)) {
                $name = trim($match[1]);
                $metadata = $match[0];
                break;
            }
        }
    }
    $insert = $db->prepare('INSERT INTO extensions (type, name, directory, active, metadata) VALUES (?, ?, ?, 0, ?)');
    $insert->execute([$type, substr($name, 0, 190), $rootName, substr($metadata, 0, 10000)]);
    return $name;
}

if (!is_file($configFile)) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $config = [
            'host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
            'port' => trim((string) ($_POST['db_port'] ?? '3306')),
            'database' => trim((string) ($_POST['db_name'] ?? '')),
            'username' => trim((string) ($_POST['db_user'] ?? '')),
            'password' => (string) ($_POST['db_password'] ?? ''),
        ];
        $adminName = trim((string) ($_POST['admin_user'] ?? ''));
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        try {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $config['database']) || $adminName === '' || strlen($adminPassword) < 12) {
                throw new RuntimeException('Veritabanı adı geçerli olmalı; yönetici parolası en az 12 karakter olmalı.');
            }
            $db = dbConnect($config);
            installSchema($db);
            $user = $db->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
            $user->execute([$adminName, password_hash($adminPassword, PASSWORD_DEFAULT)]);
            saveSettings($db, ['site_title' => 'Blog Ve1', 'site_description' => 'Blog yazıları', 'keywords' => '', 'seo_description' => '', 'privacy' => 'public', 'admin_path' => 'yonetim']);
            if (file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX) === false) {
                throw new RuntimeException('storage/config.php yazılamadı. storage dizini yazılabilir olmalı.');
            }
            chmod($configFile, 0600);
            redirect('/');
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
    $content = '<h1>Blog Ve1 kurulumu</h1><p>MySQL/MariaDB bilgilerinizi ve ilk yönetici hesabını girin.</p>';
    if ($error !== '') {
        $content .= '<p class="error">' . h($error) . '</p>';
    }
    $content .= '<form method="post">' . csrf() . field('MySQL sunucusu', 'db_host', 'localhost') . field('MySQL portu', 'db_port', '3306') . field('Veritabanı adı', 'db_name') . field('Veritabanı kullanıcısı', 'db_user') . field('Veritabanı parolası', 'db_password', '', 'password') . field('Yönetici kullanıcı adı', 'admin_user') . field('Yönetici parolası (en az 12 karakter)', 'admin_password', '', 'password') . '<button>Kurulumu tamamla</button></form>';
    layout('Kurulum', $content);
    exit;
}

$config = require $configFile;
try {
    $db = dbConnect($config);
} catch (Throwable) {
    http_response_code(503);
    exit('MySQL bağlantısı kurulamadı. storage/config.php ayarlarını kontrol edin.');
}
$settings = settings($db);
$GLOBALS['publicCategories'] = tableRows($db, 'SELECT name,slug FROM categories ORDER BY name');
$adminUrl = adminPath($settings);
$GLOBALS['adminUrl'] = $adminUrl;
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uriPath = '/' . trim(rawurldecode($uriPath), '/');
if ($uriPath === '//') {
    $uriPath = '/';
}
$isAdmin = $uriPath === $adminUrl || str_starts_with($uriPath, $adminUrl . '/');

if (preg_match('~^/media/([a-zA-Z0-9._-]+)$~', $uriPath, $match)) {
    $path = $dataDir . '/media/' . basename($match[1]);
    if (is_file($path)) {
        header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit;
}

if ($isAdmin && $uriPath === $adminUrl . '/giris') {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $user = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $user->execute([trim((string) ($_POST['username'] ?? ''))]);
        $record = $user->fetch();
        if ($record && password_verify((string) ($_POST['password'] ?? ''), $record['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $record['id'];
            redirect($adminUrl);
        }
        $error = 'Kullanıcı adı veya parola hatalı.';
    }
    $content = '<h1>Yönetici girişi</h1>' . ($error ? '<p class="error">' . h($error) . '</p>' : '') . '<form method="post">' . csrf() . field('Kullanıcı adı', 'username') . field('Parola', 'password', '', 'password') . '<button>Giriş yap</button></form>';
    layout('Giriş', $content, $settings);
    exit;
}

if ($isAdmin && $uriPath === $adminUrl . '/cikis') {
    $_SESSION = [];
    session_destroy();
    redirect('/');
}

if ($isAdmin) {
    adminOnly();
    $notice = '';
    $error = '';
    $view = $_GET['view'] ?? 'dashboard';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'category') {
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('Kategori adı gerekli.');
                }
                $insert = $db->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
                $insert->execute([$name, slug($name)]);
                $notice = 'Kategori eklendi.';
                $view = 'categories';
            } elseif ($action === 'delete_category') {
                $delete = $db->prepare('DELETE FROM categories WHERE id = ?');
                $delete->execute([(int) $_POST['id']]);
                $notice = 'Kategori silindi.';
                $view = 'categories';
            } elseif ($action === 'post') {
                $title = trim((string) ($_POST['title'] ?? ''));
                $category = (int) ($_POST['category_id'] ?? 0);
                $content = trim((string) ($_POST['content'] ?? ''));
                if ($title === '' || $content === '' || $category < 1) {
                    throw new RuntimeException('Başlık, içerik ve kategori zorunludur.');
                }
                $newSlug = slug($title);
                $check = $db->prepare('SELECT id FROM posts WHERE slug = ? AND id <> ?');
                $id = (int) ($_POST['id'] ?? 0);
                $check->execute([$newSlug, $id]);
                if ($check->fetch()) {
                    $newSlug .= '-' . bin2hex(random_bytes(3));
                }
                if ($id > 0) {
                    $update = $db->prepare('UPDATE posts SET title=?, slug=?, content=?, category_id=?, status=? WHERE id=?');
                    $update->execute([$title, $newSlug, $content, $category, $_POST['status'] === 'published' ? 'published' : 'draft', $id]);
                } else {
                    $insert = $db->prepare('INSERT INTO posts (title, slug, content, category_id, status) VALUES (?, ?, ?, ?, ?)');
                    $insert->execute([$title, $newSlug, $content, $category, $_POST['status'] === 'published' ? 'published' : 'draft']);
                }
                $notice = 'Yazı kaydedildi.';
                $view = 'posts';
            } elseif ($action === 'delete_post') {
                $delete = $db->prepare('DELETE FROM posts WHERE id = ?');
                $delete->execute([(int) $_POST['id']]);
                $notice = 'Yazı silindi.';
                $view = 'posts';
            } elseif ($action === 'form') {
                $name = trim((string) ($_POST['name'] ?? ''));
                $fields = array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['fields'] ?? '')))));
                if ($name === '' || !$fields) {
                    throw new RuntimeException('Form adı ve en az bir alan gerekli.');
                }
                $postId = (int) ($_POST['post_id'] ?? 0) ?: null;
                $insert = $db->prepare('INSERT INTO forms (name, fields_json, post_id) VALUES (?, ?, ?)');
                $insert->execute([$name, json_encode($fields, JSON_UNESCAPED_UNICODE), $postId]);
                $notice = 'İletişim formu oluşturuldu.';
                $view = 'forms';
            } elseif ($action === 'poll') {
                $name = trim((string) ($_POST['name'] ?? ''));
                $question = trim((string) ($_POST['question'] ?? ''));
                $options = array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['options'] ?? '')))));
                if ($name === '' || $question === '' || count($options) < 2) {
                    throw new RuntimeException('Anket adı, soru ve en az iki seçenek gerekli.');
                }
                $postId = (int) ($_POST['post_id'] ?? 0) ?: null;
                $insert = $db->prepare('INSERT INTO polls (name, question, options_json, post_id) VALUES (?, ?, ?, ?)');
                $insert->execute([$name, $question, json_encode($options, JSON_UNESCAPED_UNICODE), $postId]);
                $notice = 'Anket oluşturuldu.';
                $view = 'polls';
            } elseif ($action === 'settings') {
                $path = trim((string) ($_POST['admin_path'] ?? 'yonetim'), '/');
                if (!preg_match('/^[a-zA-Z0-9_-]{3,80}$/', $path)) {
                    throw new RuntimeException('Yönetim paneli yolu 3-80 karakter, harf, sayı, tire veya alt çizgi içermeli.');
                }
                $values = [
                    'site_title' => trim((string) $_POST['site_title']),
                    'site_description' => trim((string) $_POST['site_description']),
                    'keywords' => trim((string) $_POST['keywords']),
                    'seo_description' => trim((string) $_POST['seo_description']),
                    'privacy' => ($_POST['privacy'] ?? '') === 'members' ? 'members' : 'public',
                    'admin_path' => $path,
                    'smtp_host' => trim((string) $_POST['smtp_host']),
                    'smtp_port' => trim((string) $_POST['smtp_port']),
                    'smtp_username' => trim((string) $_POST['smtp_username']),
                    'smtp_password' => (string) $_POST['smtp_password'],
                    'smtp_from' => trim((string) $_POST['smtp_from']),
                ];
                if (!empty($_FILES['logo']['name'])) {
                    if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK || $_FILES['logo']['size'] > 3 * 1024 * 1024) {
                        throw new RuntimeException('Logo 3 MB altında olmalı.');
                    }
                    $mime = mime_content_type($_FILES['logo']['tmp_name']);
                    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                    if (!isset($extensions[$mime])) {
                        throw new RuntimeException('Logo PNG, JPG, GIF, WEBP veya SVG olmalı.');
                    }
                    $fileName = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                    if (!is_dir($dataDir . '/media')) {
                        mkdir($dataDir . '/media', 0750, true);
                    }
                    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dataDir . '/media/' . $fileName)) {
                        throw new RuntimeException('Logo kaydedilemedi.');
                    }
                    $values['logo'] = $fileName;
                }
                saveSettings($db, $values);
                $settings = settings($db);
                $adminUrl = adminPath($settings);
                $GLOBALS['adminUrl'] = $adminUrl;
                $notice = 'Site ayarları kaydedildi.';
                $view = 'settings';
            } elseif ($action === 'extension_upload') {
                $type = $_POST['type'] === 'theme' ? 'theme' : 'plugin';
                $name = packageUpload($db, $type, $dataDir);
                $notice = h($name) . ' paketi eklendi. Güvenlik için eklenti kodu çalıştırılmaz; temalarda stil dosyası uygulanır.';
                $view = 'extensions';
            } elseif ($action === 'extension_toggle') {
                $id = (int) $_POST['id'];
                $extension = $db->prepare('SELECT type FROM extensions WHERE id=?');
                $extension->execute([$id]);
                $item = $extension->fetch();
                if ($item) {
                    if ($item['type'] === 'theme') {
                        $db->prepare('UPDATE extensions SET active=0 WHERE type=?')->execute([$item['type']]);
                    }
                    $db->prepare('UPDATE extensions SET active=1 WHERE id=?')->execute([$id]);
                    if ($item['type'] === 'theme') {
                        $row = $db->prepare('SELECT directory FROM extensions WHERE id=?');
                        $row->execute([$id]);
                        $directory = $row->fetchColumn();
                        $css = '';
                        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dataDir . '/extensions/' . $directory, FilesystemIterator::SKIP_DOTS));
                        foreach ($iterator as $file) {
                            if ($file->isFile() && $file->getFilename() === 'style.css') {
                                $css = (string) file_get_contents($file->getPathname());
                                break;
                            }
                        }
                        saveSettings($db, ['theme_css' => substr($css, 0, 100000)]);
                        $settings['theme_css'] = substr($css, 0, 100000);
                    }
                    $notice = 'Tema/eklenti etkinleştirildi.';
                }
                $view = 'extensions';
            } elseif ($action === 'extension_disable') {
                $extension = $db->prepare('SELECT type FROM extensions WHERE id=?');
                $extension->execute([(int) $_POST['id']]);
                $type = $extension->fetchColumn();
                $db->prepare('UPDATE extensions SET active=0 WHERE id=?')->execute([(int) $_POST['id']]);
                if ($type === 'theme') {
                    saveSettings($db, ['theme_css' => '']);
                    $settings['theme_css'] = '';
                }
                $notice = 'Tema/eklenti devre dışı bırakıldı.';
                $view = 'extensions';
            } elseif ($action === 'extension_delete') {
                $id = (int) $_POST['id'];
                $row = $db->prepare('SELECT directory, type, active FROM extensions WHERE id=?');
                $row->execute([$id]);
                $item = $row->fetch();
                if ($item) {
                    $path = $dataDir . '/extensions/' . basename($item['directory']);
                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($iterator as $file) {
                        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                    }
                    if (is_dir($path)) {
                        rmdir($path);
                    }
                    $db->prepare('DELETE FROM extensions WHERE id=?')->execute([$id]);
                    if ($item['type'] === 'theme' && $item['active']) {
                        saveSettings($db, ['theme_css' => '']);
                        $settings['theme_css'] = '';
                    }
                }
                $notice = 'Paket kaldırıldı.';
                $view = 'extensions';
            } elseif ($action === 'delete_message') {
                $db->prepare('DELETE FROM messages WHERE id=?')->execute([(int) $_POST['id']]);
                $notice = 'Mesaj silindi.';
                $view = 'inbox';
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    $content = '<h1>Yönetim paneli</h1><nav>';
    foreach (['dashboard' => 'Özet', 'posts' => 'Yazılar', 'categories' => 'Kategoriler', 'forms' => 'Formlar', 'polls' => 'Anketler', 'inbox' => 'Gelen Kutusu', 'extensions' => 'Tema / Eklentiler', 'settings' => 'Site Ayarları'] as $key => $label) {
        $content .= '<a href="' . h($adminUrl) . '?view=' . h($key) . '">' . h($label) . '</a>';
    }
    $content .= '<a href="' . h($adminUrl) . '/cikis">Çıkış</a></nav>';
    $content .= '<form method="get"><input type="hidden" name="view" value="search">' . field('Yazı, kategori, anket veya form ara', 'q', $search) . '<button>Ara</button></form>';
    if ($notice !== '') {
        $content .= '<p class="success">' . $notice . '</p>';
    }
    if ($error !== '') {
        $content .= '<p class="error">' . h($error) . '</p>';
    }
    $content .= '<section class="card">';
    if ($view === 'categories') {
        $content .= '<h2>Kategoriler</h2><form method="post">' . csrf() . '<input type="hidden" name="action" value="category">' . field('Yeni kategori', 'name') . '<button>Kategori ekle</button></form><ul>';
        foreach (tableRows($db, 'SELECT id,name FROM categories ORDER BY name') as $row) {
            $content .= '<li>' . h($row['name']) . ' <form method="post" style="display:inline">' . csrf() . '<input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button>Sil</button></form></li>';
        }
        $content .= '</ul><p>Yazısı bulunan kategoriler, zorunlu kategori ilişkisi nedeniyle silinemez.</p>';
    } elseif ($view === 'posts' || (isset($_GET['edit']) && $view !== 'search')) {
        $edit = null;
        if (isset($_GET['edit'])) {
            $select = $db->prepare('SELECT * FROM posts WHERE id=?');
            $select->execute([(int) $_GET['edit']]);
            $edit = $select->fetch() ?: null;
        }
        $content .= '<h2>' . ($edit ? 'Yazıyı düzenle' : 'Yeni yazı') . '</h2>';
        $content .= '<form method="post">' . csrf() . '<input type="hidden" name="action" value="post"><input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '">' . field('Başlık', 'title', $edit['title'] ?? '');
        $content .= '<label>Kategori<select name="category_id" required><option value="">Kategori seçin</option>';
        foreach (tableRows($db, 'SELECT id,name FROM categories ORDER BY name') as $category) {
            $selected = (int) ($edit['category_id'] ?? 0) === (int) $category['id'] ? ' selected' : '';
            $content .= '<option value="' . (int) $category['id'] . '"' . $selected . '>' . h($category['name']) . '</option>';
        }
        $content .= '</select></label><label>İçerik<textarea name="content" required>' . h($edit['content'] ?? '') . '</textarea></label><label>Durum<select name="status"><option value="draft">Taslak</option><option value="published"' . (($edit['status'] ?? '') === 'published' ? ' selected' : '') . '>Yayımla</option></select></label><button>Yazıyı kaydet</button></form>';
        $content .= '<h2>Yazılar</h2><table><tr><th>Başlık</th><th>Kategori</th><th>Durum</th><th>İşlem</th></tr>';
        foreach (tableRows($db, 'SELECT p.id,p.title,p.slug,p.status,c.name category FROM posts p JOIN categories c ON c.id=p.category_id ORDER BY p.created_at DESC') as $post) {
            $content .= '<tr><td>' . h($post['title']) . '</td><td>' . h($post['category']) . '</td><td>' . h($post['status']) . '</td><td><a href="' . h($adminUrl) . '?view=posts&edit=' . (int) $post['id'] . '">Düzenle</a> <a href="/yazi/' . rawurlencode($post['slug']) . '">Gör</a><form method="post">' . csrf() . '<input type="hidden" name="action" value="delete_post"><input type="hidden" name="id" value="' . (int) $post['id'] . '"><button>Sil</button></form></td></tr>';
        }
        $content .= '</table>';
    } elseif ($view === 'forms') {
        $content .= '<h2>İletişim formları</h2><form method="post">' . csrf() . '<input type="hidden" name="action" value="form">' . field('Form adı', 'name') . '<label>Alan etiketleri (her satırda bir alan)<textarea name="fields" required></textarea></label><label>Yazıya ekle<select name="post_id"><option value="">Bağımsız form</option>';
        foreach (tableRows($db, "SELECT id,title FROM posts WHERE status='published' ORDER BY title") as $post) {
            $content .= '<option value="' . (int) $post['id'] . '">' . h($post['title']) . '</option>';
        }
        $content .= '</select></label><button>Form oluştur</button></form><ul>';
        foreach (tableRows($db, 'SELECT f.id,f.name,p.title FROM forms f LEFT JOIN posts p ON p.id=f.post_id ORDER BY f.id DESC') as $form) {
            $content .= '<li>' . h($form['name']) . ' — ' . h($form['title'] ?? 'Bağımsız') . ' (ID: ' . (int) $form['id'] . ')</li>';
        }
        $content .= '</ul>';
    } elseif ($view === 'polls') {
        $content .= '<h2>Anketler</h2><form method="post">' . csrf() . '<input type="hidden" name="action" value="poll">' . field('Anket adı', 'name') . field('Soru', 'question') . '<label>Seçenekler (her satırda bir seçenek)<textarea name="options" required></textarea></label><label>Yazıya ekle<select name="post_id"><option value="">Bağımsız anket</option>';
        foreach (tableRows($db, "SELECT id,title FROM posts WHERE status='published' ORDER BY title") as $post) {
            $content .= '<option value="' . (int) $post['id'] . '">' . h($post['title']) . '</option>';
        }
        $content .= '</select></label><button>Anket oluştur</button></form><ul>';
        foreach (tableRows($db, 'SELECT p.id,p.name,p.question,a.title FROM polls p LEFT JOIN posts a ON a.id=p.post_id ORDER BY p.id DESC') as $poll) {
            $content .= '<li>' . h($poll['name']) . ': ' . h($poll['question']) . ' — ' . h($poll['title'] ?? 'Bağımsız') . '</li>';
        }
        $content .= '</ul>';
    } elseif ($view === 'inbox') {
        $content .= '<h2>Gelen Kutusu</h2><table><tr><th>Tarih</th><th>Form / yazı</th><th>Mesaj</th><th></th></tr>';
        foreach (tableRows($db, 'SELECT m.id,m.values_json,m.created_at,f.name,p.title FROM messages m JOIN forms f ON f.id=m.form_id LEFT JOIN posts p ON p.id=m.post_id ORDER BY m.created_at DESC') as $message) {
            $values = json_decode($message['values_json'], true) ?: [];
            $content .= '<tr><td>' . h($message['created_at']) . '</td><td>' . h($message['name']) . '<br>' . h($message['title'] ?? '') . '</td><td>';
            foreach ($values as $key => $value) {
                $content .= '<strong>' . h($key) . ':</strong> ' . nl2br(h($value)) . '<br>';
            }
            $content .= '</td><td><form method="post">' . csrf() . '<input type="hidden" name="action" value="delete_message"><input type="hidden" name="id" value="' . (int) $message['id'] . '"><button>Sil</button></form></td></tr>';
        }
        $content .= '</table>';
    } elseif ($view === 'settings') {
        $content .= '<h2>Site ayarları</h2><form method="post" enctype="multipart/form-data">' . csrf() . '<input type="hidden" name="action" value="settings">';
        foreach (['site_title' => 'Site başlığı', 'site_description' => 'Site açıklaması', 'keywords' => 'Anahtar kelimeler', 'seo_description' => 'SEO açıklaması', 'admin_path' => 'Yönetim paneli yolu', 'smtp_host' => 'SMTP sunucusu', 'smtp_port' => 'SMTP portu', 'smtp_username' => 'SMTP kullanıcı adı', 'smtp_password' => 'SMTP parolası', 'smtp_from' => 'Gönderen e-posta'] as $key => $label) {
            $content .= field($label, $key, $settings[$key] ?? '', $key === 'smtp_password' ? 'password' : 'text');
        }
        $content .= '<label>Site gizliliği<select name="privacy"><option value="public"' . (($settings['privacy'] ?? 'public') === 'public' ? ' selected' : '') . '>Herkese açık</option><option value="members"' . (($settings['privacy'] ?? '') === 'members' ? ' selected' : '') . '>Sadece üyeler</option></select></label><label>Logo yükle<input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml"></label><button>Ayarları kaydet</button></form>';
    } elseif ($view === 'extensions') {
        $content .= '<h2>Tema ve eklenti paketleri</h2><p>WordPress tarzı tema (.zip; style.css tanınır) ve eklenti (.zip; Plugin Name başlığı tanınır) paketleri alınır. PHP kodları özellikle çalıştırılmaz.</p><form method="post" enctype="multipart/form-data">' . csrf() . '<input type="hidden" name="action" value="extension_upload"><label>Paket türü<select name="type"><option value="theme">Tema</option><option value="plugin">Eklenti</option></select></label><label>ZIP paketi<input type="file" name="package" accept=".zip" required></label><button>Paketi ekle</button></form><table><tr><th>Ad</th><th>Tür</th><th>Durum</th><th></th></tr>';
        foreach (tableRows($db, 'SELECT id,name,type,active FROM extensions ORDER BY type,name') as $extension) {
            $content .= '<tr><td>' . h($extension['name']) . '</td><td>' . h($extension['type']) . '</td><td>' . ($extension['active'] ? 'Etkin' : 'Pasif') . '</td><td><form method="post">' . csrf() . '<input type="hidden" name="id" value="' . (int) $extension['id'] . '"><input type="hidden" name="action" value="' . ($extension['active'] ? 'extension_disable' : 'extension_toggle') . '"><button>' . ($extension['active'] ? 'Pasifleştir' : 'Etkinleştir') . '</button></form><form method="post">' . csrf() . '<input type="hidden" name="id" value="' . (int) $extension['id'] . '"><input type="hidden" name="action" value="extension_delete"><button>Kaldır</button></form></td></tr>';
        }
        $content .= '</table>';
    } elseif ($view === 'search') {
        $content .= '<h2>Arama sonuçları</h2>';
        if ($search !== '') {
            $like = '%' . $search . '%';
            foreach ([
                ['Yazılar', 'SELECT title label, CONCAT(?, slug) url FROM posts WHERE title LIKE ? OR content LIKE ?'],
                ['Kategoriler', 'SELECT name label, ? url FROM categories WHERE name LIKE ?'],
                ['Formlar', 'SELECT name label, ? url FROM forms WHERE name LIKE ?'],
                ['Anketler', 'SELECT name label, ? url FROM polls WHERE name LIKE ? OR question LIKE ?'],
            ] as [$heading, $sql]) {
                $content .= '<h3>' . h($heading) . '</h3><ul>';
                try {
                    $query = $db->prepare($sql);
                    if ($heading === 'Yazılar') {
                        $query->execute(['/yazi/', $like, $like]);
                    } elseif ($heading === 'Anketler') {
                        $query->execute(['#', $like, $like]);
                    } else {
                        $query->execute(['#', $like]);
                    }
                    foreach ($query as $result) {
                        $content .= '<li><a href="' . h($result['url']) . '">' . h($result['label']) . '</a></li>';
                    }
                } catch (Throwable) {
                    $content .= '<li>Sonuç bulunamadı.</li>';
                }
                $content .= '</ul>';
            }
        }
    } else {
        $counts = [];
        foreach (['posts' => 'Yazılar', 'categories' => 'Kategoriler', 'forms' => 'Formlar', 'polls' => 'Anketler', 'messages' => 'Gelen mesajlar'] as $table => $label) {
            $counts[] = '<div class="card"><strong>' . (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() . '</strong><br>' . h($label) . '</div>';
        }
        $content .= '<h2>Hoş geldiniz</h2><div class="grid">' . implode('', $counts) . '</div>';
    }
    $content .= '</section>';
    layout('Yönetim', $content, $settings, true);
    exit;
}

if (($settings['privacy'] ?? 'public') === 'members' && empty($_SESSION['user_id'])) {
    http_response_code(403);
    layout('Üyelere özel', '<h1>Bu site yalnızca üyeler içindir.</h1><p><a href="' . h($adminUrl) . '/giris">Üye girişi</a></p>', $settings);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['public_action'])) {
    verifyCsrf();
    if ($_POST['public_action'] === 'message') {
        $formId = (int) ($_POST['form_id'] ?? 0);
        $query = $db->prepare('SELECT id,fields_json,post_id FROM forms WHERE id=?');
        $query->execute([$formId]);
        $form = $query->fetch();
        if ($form) {
            $fields = json_decode($form['fields_json'], true) ?: [];
            $values = [];
            foreach ($fields as $label) {
                $value = trim((string) ($_POST['field_' . md5($label)] ?? ''));
                if ($value !== '' && strlen($value) <= 10000) {
                    $values[$label] = $value;
                }
            }
            if ($values) {
                $insert = $db->prepare('INSERT INTO messages (form_id,post_id,values_json) VALUES (?,?,?)');
                $insert->execute([$formId, $form['post_id'], json_encode($values, JSON_UNESCAPED_UNICODE)]);
                redirect($uriPath . '?sent=1');
            }
        }
    } elseif ($_POST['public_action'] === 'vote') {
        $pollId = (int) ($_POST['poll_id'] ?? 0);
        $option = (int) ($_POST['option'] ?? -1);
        $poll = $db->prepare('SELECT options_json FROM polls WHERE id=?');
        $poll->execute([$pollId]);
        $options = json_decode((string) $poll->fetchColumn(), true) ?: [];
        if (isset($options[$option])) {
            $hash = hash('sha256', session_id() . ($_SERVER['REMOTE_ADDR'] ?? ''));
            $insert = $db->prepare('INSERT IGNORE INTO poll_votes (poll_id,option_index,voter_hash) VALUES (?,?,?)');
            $insert->execute([$pollId, $option, $hash]);
        }
        redirect($uriPath);
    }
}

$content = '';
if ($uriPath === '/ara') {
    $query = trim((string) ($_GET['q'] ?? ''));
    $content = '<h1>Site içinde ara</h1><form method="get">' . field('Arama', 'q', $query) . '<button>Ara</button></form>';
    if ($query !== '') {
        $statement = $db->prepare("SELECT p.title,p.slug,c.name category FROM posts p JOIN categories c ON c.id=p.category_id WHERE p.status='published' AND (p.title LIKE ? OR p.content LIKE ?) ORDER BY p.created_at DESC");
        $statement->execute(['%' . $query . '%', '%' . $query . '%']);
        foreach ($statement as $row) {
            $content .= '<article class="card"><h2><a href="/yazi/' . rawurlencode($row['slug']) . '">' . h($row['title']) . '</a></h2><p class="muted">' . h($row['category']) . '</p></article>';
        }
    }
} elseif (preg_match('~^/kategori/([a-z0-9-]+)$~', $uriPath, $match)) {
    $category = $db->prepare('SELECT name FROM categories WHERE slug=?');
    $category->execute([$match[1]]);
    $categoryName = $category->fetchColumn();
    if ($categoryName === false) {
        http_response_code(404);
        $content = '<h1>Kategori bulunamadı</h1>';
    } else {
        $content = '<h1>' . h($categoryName) . '</h1>';
        $posts = $db->prepare("SELECT p.title,p.slug,p.content,p.created_at FROM posts p JOIN categories c ON c.id=p.category_id WHERE p.status='published' AND c.slug=? ORDER BY p.created_at DESC");
        $posts->execute([$match[1]]);
        foreach ($posts as $post) {
            $content .= '<article class="card"><p class="muted">' . h($post['created_at']) . '</p><h2><a href="/yazi/' . rawurlencode($post['slug']) . '">' . h($post['title']) . '</a></h2><p>' . h(mb_substr($post['content'], 0, 240, 'UTF-8')) . '</p></article>';
        }
    }
} elseif (preg_match('~^/yazi/([a-z0-9-]+)$~', $uriPath, $match)) {
    $postQuery = $db->prepare("SELECT p.*,c.name category FROM posts p JOIN categories c ON c.id=p.category_id WHERE p.slug=? AND p.status='published'");
    $postQuery->execute([$match[1]]);
    $post = $postQuery->fetch();
    if (!$post) {
        http_response_code(404);
        $content = '<h1>Yazı bulunamadı</h1>';
    } else {
        $content = '<article class="card"><p class="muted">' . h($post['category']) . '</p><h1>' . h($post['title']) . '</h1><div>' . nl2br(h($post['content'])) . '</div></article>';
        foreach (['forms' => 'İletişim formu', 'polls' => 'Anket'] as $table => $kind) {
            $statement = $db->prepare('SELECT * FROM ' . $table . ' WHERE post_id=?');
            $statement->execute([$post['id']]);
            foreach ($statement as $item) {
                $content .= '<section class="card"><h2>' . h($item['name']) . '</h2>';
                if ($table === 'forms') {
                    if (isset($_GET['sent'])) {
                        $content .= '<p class="success">Mesajınız gönderildi.</p>';
                    }
                    $content .= '<form method="post">' . csrf() . '<input type="hidden" name="public_action" value="message"><input type="hidden" name="form_id" value="' . (int) $item['id'] . '">';
                    foreach (json_decode($item['fields_json'], true) ?: [] as $label) {
                        $content .= '<label>' . h($label) . '<textarea name="field_' . h(md5($label)) . '" required></textarea></label>';
                    }
                    $content .= '<button>Mesajı gönder</button></form>';
                } else {
                    $options = json_decode($item['options_json'], true) ?: [];
                    $content .= '<p>' . h($item['question']) . '</p><form method="post">' . csrf() . '<input type="hidden" name="public_action" value="vote"><input type="hidden" name="poll_id" value="' . (int) $item['id'] . '">';
                    foreach ($options as $index => $option) {
                        $content .= '<label><input type="radio" name="option" value="' . (int) $index . '" required> ' . h($option) . '</label><br>';
                    }
                    $content .= '<button>Oy ver</button></form>';
                    $results = $db->prepare('SELECT option_index,COUNT(*) votes FROM poll_votes WHERE poll_id=? GROUP BY option_index');
                    $results->execute([$item['id']]);
                    $votes = [];
                    foreach ($results as $result) {
                        $votes[(int) $result['option_index']] = (int) $result['votes'];
                    }
                    foreach ($options as $index => $option) {
                        $content .= '<p>' . h($option) . ': ' . ($votes[$index] ?? 0) . ' oy</p>';
                    }
                }
                $content .= '</section>';
            }
        }
    }
} else {
    $posts = tableRows($db, "SELECT p.title,p.slug,p.content,p.created_at,c.name category FROM posts p JOIN categories c ON c.id=p.category_id WHERE p.status='published' ORDER BY p.created_at DESC");
    $content = '<h1>' . h($settings['site_description'] ?? 'Blog yazıları') . '</h1><form method="get" action="/ara">' . field('Site içinde ara', 'q') . '<button>Ara</button></form>';
    foreach ($posts as $post) {
        $content .= '<article class="card"><p class="muted">' . h($post['category']) . ' · ' . h($post['created_at']) . '</p><h2><a href="/yazi/' . rawurlencode($post['slug']) . '">' . h($post['title']) . '</a></h2><p>' . h(mb_substr($post['content'], 0, 240, 'UTF-8')) . '</p></article>';
    }
}
layout('Blog', $content, $settings);
