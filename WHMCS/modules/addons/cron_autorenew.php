<?php
/**
 * VPS Renew - Independent AutoRenew Cron
 *
 * Usage:
 *   php -q /path/to/modules/addons/vpsrenew/cron_autorenew.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

$baseDirs = array_filter(array_unique([
    __DIR__,
    isset($_SERVER['SCRIPT_FILENAME']) ? dirname((string) $_SERVER['SCRIPT_FILENAME']) : '',
]));

$candidates = [];
foreach ($baseDirs as $baseDir) {
    $baseDir = rtrim((string) $baseDir, '/');
    if ($baseDir === '') {
        continue;
    }
    $candidates[] = $baseDir . '/../../../init.php';       // modules/addons/vpsrenew
    $candidates[] = $baseDir . '/../../../../init.php';    // fallback
    $candidates[] = $baseDir . '/../my.nextcli.com/init.php'; // local repo layout
}

$envRoot = getenv('WHMCS_ROOT');
if ($envRoot) {
    $candidates[] = rtrim($envRoot, '/') . '/init.php';
}

$initFile = '';
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $initFile = $candidate;
        break;
    }
}

if ($initFile === '') {
    fwrite(STDERR, "Unable to locate WHMCS init.php\n");
    exit(1);
}

require_once $initFile;
require_once __DIR__ . '/lib_vpsrenew.php';

try {
    vpsrenew_ensure_tables();
    $processed = vpsrenew_run_autorenew_cycle(['source' => 'dedicated_cron']);
    echo 'vpsrenew_autorenew_ok processed=' . (int) $processed . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    logActivity('VPS 独立自动续费 cron 错误: ' . $e->getMessage());
    fwrite(STDERR, 'vpsrenew_autorenew_error ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

