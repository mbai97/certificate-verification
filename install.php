<?php

declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);

$configFile = __DIR__ . '/config/config.php';
$config = null;
$configured = is_file($configFile);
$error = '';
$message = '';
$complete = false;
$dbReady = true;

if (!isset($_SESSION['install_csrf_token'])) {
    $_SESSION['install_csrf_token'] = bin2hex(random_bytes(32));
}

if ($configured) {
    $config = require $configFile;
    if (!is_array($config) || !isset($config['app_url'], $config['db']['host'], $config['db']['port'], $config['db']['name'], $config['db']['user'])) {
        $dbReady = false;
        $error = 'The existing database configuration is incomplete. Check config/config.php.';
    } else {
        try {
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $config['db']['host'],
                    (int)$config['db']['port'],
                    $config['db']['name']
                ),
                $config['db']['user'],
                $config['db']['pass'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $tableExists = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admins'"
            )->fetchColumn();
            if ((int)$tableExists > 0 && (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
                http_response_code(403);
                exit('The first administrator is already set up. Remove install.php from the web server.');
            }
        } catch (Throwable $exception) {
            $dbReady = false;
            $error = 'Cannot connect to the configured database. Check config/config.php and the database service.';
            error_log('Installer database check failed: ' . $exception->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['install_csrf_token'], $_POST['csrf_token'])) {
        http_response_code(400);
        $error = 'The setup form expired. Reload the page and try again.';
    }

    $adminName = is_string($_POST['admin_name'] ?? null) ? trim($_POST['admin_name']) : '';
    $adminEmail = is_string($_POST['admin_email'] ?? null) ? strtolower(trim($_POST['admin_email'])) : '';
    $adminPassword = is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : '';

    if (!$error && ($adminName === '' || strlen($adminName) > 120)) {
        $error = 'Enter an administrator name of at most 120 characters.';
    } elseif (!$error && ($adminEmail === '' || strlen($adminEmail) > 190 || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL))) {
        $error = 'Enter a valid administrator email address.';
    } elseif (!$error && strlen($adminPassword) < 10) {
        $error = 'Administrator password must be at least 10 characters.';
    }

    if (!$configured) {
        $appUrl = is_string($_POST['app_url'] ?? null) ? rtrim(trim($_POST['app_url']), '/') : '';
        $issuer = is_string($_POST['issuer_name'] ?? null) ? trim($_POST['issuer_name']) : '';
        $dbHost = is_string($_POST['db_host'] ?? null) ? trim($_POST['db_host']) : '';
        $dbPort = filter_var($_POST['db_port'] ?? '3306', FILTER_VALIDATE_INT);
        $dbName = is_string($_POST['db_name'] ?? null) ? trim($_POST['db_name']) : '';
        $dbUser = is_string($_POST['db_user'] ?? null) ? trim($_POST['db_user']) : '';
        $dbPass = is_string($_POST['db_pass'] ?? null) ? $_POST['db_pass'] : '';

        if (!$error && ($appUrl === '' || !filter_var($appUrl, FILTER_VALIDATE_URL))) {
            $error = 'Enter a valid application URL.';
        } elseif (!$error && ($issuer === '' || strlen($issuer) > 190)) {
            $error = 'Enter an issuer name of at most 190 characters.';
        } elseif (!$error && ($dbPort === false || $dbPort < 1 || $dbPort > 65535)) {
            $error = 'Enter a valid database port.';
        } elseif (!$error && (!preg_match('/^[A-Za-z0-9_]+$/', $dbName) || $dbUser === '')) {
            $error = 'Enter a database name using only letters, numbers, and underscores, and a database username.';
        }
    } else {
        $dbHost = $config['db']['host'] ?? '';
        $dbPort = (int)($config['db']['port'] ?? 0);
        $dbName = $config['db']['name'] ?? '';
        $dbUser = $config['db']['user'] ?? '';
        $dbPass = $config['db']['pass'] ?? '';
    }

    if (!$error && !$dbReady) {
        $error = 'Fix the database configuration before setting up the first administrator.';
    }

    if (!$error) {
        $lockName = 'cert-first-admin-' . substr(hash('sha256', (string)$dbName), 0, 32);
        $lockAcquired = false;
        try {
            if (!$configured) {
                $pdo = new PDO(
                    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort),
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $quotedDbName = '`' . str_replace('`', '``', $dbName) . '`';
                $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $quotedDbName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdo->exec('USE ' . $quotedDbName);
            } else {
                $pdo = new PDO(
                    sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                        $dbHost,
                        $dbPort,
                        $dbName
                    ),
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            }

            $lockAcquired = (int)$pdo->query(
                'SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 10)'
            )->fetchColumn() === 1;
            if (!$lockAcquired) {
                throw new RuntimeException('Could not acquire the first-administrator setup lock.');
            }

            $schema = file_get_contents(__DIR__ . '/database/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Could not read database/schema.sql.');
            }
            foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [])) as $statement) {
                $pdo->exec($statement);
            }

            if ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
                http_response_code(403);
                throw new RuntimeException('The first administrator has already been created.');
            }

            if (!$configured) {
                $config = [
                    'app_url' => $appUrl,
                    'app_name' => 'Beckyn Certificate Verification',
                    'issuer_name' => $issuer,
                    'certificate_prefix' => 'BKDS',
                    'timezone' => 'Africa/Nairobi',
                    'db' => [
                        'host' => $dbHost,
                        'port' => $dbPort,
                        'name' => $dbName,
                        'user' => $dbUser,
                        'pass' => $dbPass,
                    ],
                ];

                $configPhp = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
                if (!is_dir(__DIR__ . '/config') && !mkdir(__DIR__ . '/config', 0755, true) && !is_dir(__DIR__ . '/config')) {
                    throw new RuntimeException('Could not create the config directory.');
                }
                if (file_put_contents($configFile, $configPhp, LOCK_EX) === false) {
                    throw new RuntimeException('Could not write config/config.php. Check folder permissions.');
                }
            }

            foreach (['certificates', 'qrcodes', 'logs'] as $folder) {
                $path = __DIR__ . '/storage/' . $folder;
                if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
                    throw new RuntimeException('Could not create storage/' . $folder . '. Check folder permissions.');
                }
            }

            $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('Could not securely hash the administrator password.');
            }
            $stmt = $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$adminName, $adminEmail, $hash]);

            $message = 'Setup is complete. Sign in with the administrator email and password you just created.';
            $complete = true;
            unset($_SESSION['install_csrf_token']);
        } catch (Throwable $exception) {
            $error = 'Setup failed. Check the PHP error log for details.';
            error_log('Installer failed: ' . $exception->getMessage());
        } finally {
            if ($lockAcquired) {
                try {
                    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
                } catch (Throwable $exception) {
                    error_log('Installer lock release failed: ' . $exception->getMessage());
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Beckyn Certificate System Installer</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card shadow-sm border-0">
<div class="card-body p-4 p-lg-5">
<h1 class="fw-bold">Beckyn Certificate System</h1>
<p class="text-muted"><?= $configured ? 'Create the first administrator' : 'First-time installation' ?></p>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($complete): ?>
<a class="btn btn-primary w-100 mt-3" href="admin/login.php">Go to admin login</a>
<?php else: ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['install_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
<?php if (!$configured): ?>
<h5 class="mt-3">Application</h5>
<div class="mb-3">
<label class="form-label">Application URL</label>
<input class="form-control" name="app_url" value="<?= htmlspecialchars(is_string($_POST['app_url'] ?? null) ? $_POST['app_url'] : 'http://localhost/certificate-verification', ENT_QUOTES, 'UTF-8') ?>" required>
</div>
<div class="mb-3">
<label class="form-label">Issuer Name</label>
<input class="form-control" name="issuer_name" value="<?= htmlspecialchars(is_string($_POST['issuer_name'] ?? null) ? $_POST['issuer_name'] : 'Beckyn Driving & Mechanical School', ENT_QUOTES, 'UTF-8') ?>" required>
</div>

<h5 class="mt-4">Database</h5>
<div class="row g-3">
<div class="col-md-8"><label class="form-label">Host</label><input class="form-control" name="db_host" value="<?= htmlspecialchars(is_string($_POST['db_host'] ?? null) ? $_POST['db_host'] : '127.0.0.1', ENT_QUOTES, 'UTF-8') ?>" required></div>
<div class="col-md-4"><label class="form-label">Port</label><input class="form-control" name="db_port" value="<?= htmlspecialchars(is_string($_POST['db_port'] ?? null) ? $_POST['db_port'] : '3306', ENT_QUOTES, 'UTF-8') ?>" required></div>
<div class="col-md-6"><label class="form-label">Database Name</label><input class="form-control" name="db_name" value="<?= htmlspecialchars(is_string($_POST['db_name'] ?? null) ? $_POST['db_name'] : 'beckyn_certificates', ENT_QUOTES, 'UTF-8') ?>" required></div>
<div class="col-md-6"><label class="form-label">Database User</label><input class="form-control" name="db_user" value="<?= htmlspecialchars(is_string($_POST['db_user'] ?? null) ? $_POST['db_user'] : 'root', ENT_QUOTES, 'UTF-8') ?>" required></div>
<div class="col-12"><label class="form-label">Database Password</label><input type="password" class="form-control" name="db_pass"></div>
</div>
<?php else: ?>
<div class="alert alert-info">The database is already configured. The installer will create the tables if needed and allow creation only while no administrator exists.</div>
<?php endif; ?>

<h5 class="mt-4">First Administrator (Superuser)</h5>
<div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="admin_name" value="<?= htmlspecialchars(is_string($_POST['admin_name'] ?? null) ? $_POST['admin_name'] : '', ENT_QUOTES, 'UTF-8') ?>" maxlength="120" autocomplete="name" required></div>
<div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="admin_email" value="<?= htmlspecialchars(is_string($_POST['admin_email'] ?? null) ? $_POST['admin_email'] : '', ENT_QUOTES, 'UTF-8') ?>" maxlength="190" autocomplete="username" required></div>
<div class="mb-3"><label class="form-label">Password</label><input type="password" class="form-control" name="admin_password" minlength="10" autocomplete="new-password" required><div class="form-text">Use at least 10 characters.</div></div>

<button class="btn btn-primary btn-lg w-100 mt-3"><?= $configured ? 'Create First Administrator' : 'Install System' ?></button>
</form>
<p class="small text-muted mt-3 mb-0">After setup, remove install.php from the web server.</p>
<?php endif; ?>
</div></div>
</div></div>
</div>
</body>
</html>
