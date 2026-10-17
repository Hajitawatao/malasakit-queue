<?php

// Sets KEY=VALUE in .env (replaces the line if it exists, otherwise appends).
// Usage: php tools/set-env.php KEY VALUE
[$self, $key, $value] = array_pad($argv, 3, '');

if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
    fwrite(STDERR, "Invalid key: {$key}\n");
    exit(1);
}

$file = __DIR__ . '/../.env';
if (! is_file($file)) {
    copy(__DIR__ . '/../.env.example', $file);
}

$value = trim($value);
if ($value !== '' && preg_match('/[\s#"\'=]/', $value)) {
    $value = '"' . str_replace('"', '\"', $value) . '"';
}

$lines = file($file, FILE_IGNORE_NEW_LINES);
$found = false;
foreach ($lines as $i => $line) {
    if (preg_match('/^\s*#?\s*' . preg_quote($key, '/') . '\s*=/', $line)) {
        $lines[$i] = "{$key}={$value}";
        $found = true;
        break;
    }
}
if (! $found) {
    $lines[] = "{$key}={$value}";
}

file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
echo "[OK] {$key} saved in .env\n";
