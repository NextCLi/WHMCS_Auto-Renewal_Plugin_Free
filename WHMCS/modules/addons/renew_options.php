<?php
/**
 * VPS 续费选项接口（客户端）
 */

$initFile = null;
$searchRoots = [__DIR__];
$realDir = realpath(__DIR__);
if ($realDir) {
    $searchRoots[] = $realDir;
}
if (!empty($_SERVER['SCRIPT_FILENAME'])) {
    $searchRoots[] = dirname($_SERVER['SCRIPT_FILENAME']);
}
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $searchRoots[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
}
$searchRoots = array_values(array_unique(array_filter($searchRoots)));

foreach ($searchRoots as $root) {
    $dir = $root;
    for ($i = 0; $i < 8; $i++) {
        $candidate = rtrim($dir, '/') . '/init.php';
        $config = rtrim($dir, '/') . '/configuration.php';
        if (is_file($candidate) && is_file($config)) {
            $initFile = $candidate;
            break 2;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
}

if ($initFile === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Unable to locate WHMCS init.php']);
    exit;
}

require_once $initFile;
require_once __DIR__ . '/lib_vpsrenew.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['uid']) || !$_SESSION['uid']) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$clientId = (int) $_SESSION['uid'];
$serviceId = isset($_GET['sid']) ? (int) $_GET['sid'] : 0;

if ($serviceId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid service id']);
    exit;
}

try {
    $config = vpsrenew_get_module_config();
    if (!$config) {
        throw new Exception('VPS续费模块未激活或未配置');
    }

    $options = vpsrenew_get_service_renewal_options($clientId, $serviceId, $config);
    if (empty($options)) {
        throw new Exception('无法获取续费选项');
    }

    echo json_encode([
        'ok' => true,
        'options' => $options,
        'renewUrl' => '/modules/addons/vpsrenew/renewal.php?sid=' . $serviceId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    logActivity('VPS 续费选项接口错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Internal Error']);
}
