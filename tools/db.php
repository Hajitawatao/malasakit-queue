<?php

/**
 * Database helper for setup.bat / start.bat.
 * Reads DB_* from .env (so DB_PASSWORD is respected) and talks to MySQL via PDO.
 *
 *   php tools/db.php check    -> can we connect to the server?
 *   php tools/db.php create   -> connect and create the database if missing
 */

$envFile = __DIR__ . '/../.env';
if (! is_file($envFile)) {
    fwrite(STDERR, "[ERROR] .env not found. Run setup.bat first.\n");
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';
$name = $env['DB_DATABASE'] ?? 'malasakit_queue';

if (! extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "[ERROR] PHP's pdo_mysql extension is not loaded.\n");
    exit(1);
}

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
} catch (PDOException $e) {
    $msg = $e->getMessage();
    fwrite(STDERR, "[ERROR] Cannot connect to MySQL at {$host}:{$port} as '{$user}'.\n");
    fwrite(STDERR, "        MySQL says: {$msg}\n");
    if (str_contains($msg, '2002') || stripos($msg, 'refused') !== false) {
        fwrite(STDERR, "        -> MySQL is not running. Click Start next to MySQL in the XAMPP Control Panel.\n");
        fwrite(STDERR, "           If XAMPP's MySQL will not start, another MySQL may already be using port 3306.\n");
    } elseif (str_contains($msg, '1045')) {
        fwrite(STDERR, "        -> Wrong username/password. Open .env and set DB_USERNAME / DB_PASSWORD.\n");
    } elseif (str_contains($msg, '1130')) {
        fwrite(STDERR, "        -> This user is not allowed to connect from here. Try DB_HOST=localhost in .env.\n");
    }
    exit(1);
}

$version = $pdo->query('SELECT VERSION()')->fetchColumn();

if (($argv[1] ?? 'check') === 'create') {
    if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        fwrite(STDERR, "[ERROR] DB_DATABASE may only contain letters, numbers and underscores.\n");
        exit(1);
    }
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "[OK] Database {$name} (MySQL {$version})\n";
} else {
    echo "[OK] MySQL {$version}\n";
}
