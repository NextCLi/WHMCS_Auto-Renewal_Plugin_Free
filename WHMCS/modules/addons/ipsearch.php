<?php
/**
 * VPS IP 搜索接口（客户端）
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

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib_vpsrenew.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['uid']) || !$_SESSION['uid']) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$clientId = (int) $_SESSION['uid'];
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'items' => []]);
    exit;
}

$qLike = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
$qLower = mb_strtolower($q, 'UTF-8');
$qNorm = preg_replace('/[\s\-_:\.]+/u', '', $qLower);
$tokens = preg_split('/\s+/u', $qLower, -1, PREG_SPLIT_NO_EMPTY);

if (!function_exists('vpsrenew_ipsearch_score')) {
    function vpsrenew_ipsearch_score($query, $queryNorm, array $tokens, array $fields)
    {
        $score = 0;
        foreach ($fields as $field) {
            $v = mb_strtolower((string) $field, 'UTF-8');
            if ($v === '') {
                continue;
            }
            $vNorm = preg_replace('/[\s\-_:\.]+/u', '', $v);

            if (mb_strpos($v, $query) !== false) {
                $score += 60;
            }
            if ($queryNorm !== '' && mb_strpos($vNorm, $queryNorm) !== false) {
                $score += 40;
            }

            $tokenHit = 0;
            foreach ($tokens as $token) {
                if ($token !== '' && mb_strpos($v, $token) !== false) {
                    $tokenHit++;
                }
            }
            if ($tokenHit > 0) {
                $score += min(30, $tokenHit * 10);
            }
        }

        return $score;
    }
}

try {
    // 先用 SQL 缩小候选范围，再在 PHP 层做大小写无关/去符号模糊匹配
    $rows = Capsule::table('tblhosting as h')
        ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->where('h.userid', $clientId)
        ->whereIn('h.domainstatus', ['Active', 'Suspended'])
        ->where(function ($query) use ($qLike) {
            $query->where('h.dedicatedip', 'like', $qLike)
                ->orWhere('h.assignedips', 'like', $qLike)
                ->orWhere('h.domain', 'like', $qLike)
                ->orWhere('p.name', 'like', $qLike);
        })
        ->orderBy('h.id', 'desc')
        ->limit(120)
        ->select(['h.id', 'h.domain', 'h.dedicatedip', 'h.assignedips', 'p.name as product_name'])
        ->get();

    if ($rows->isEmpty()) {
        $rows = Capsule::table('tblhosting as h')
            ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.userid', $clientId)
            ->whereIn('h.domainstatus', ['Active', 'Suspended'])
            ->orderBy('h.id', 'desc')
            ->limit(240)
            ->select(['h.id', 'h.domain', 'h.dedicatedip', 'h.assignedips', 'p.name as product_name'])
            ->get();
    }

    $items = [];
    foreach ($rows as $row) {
        $ips = array_merge(
            vpsrenew_split_ips($row->dedicatedip),
            vpsrenew_split_ips($row->assignedips)
        );

        $score = vpsrenew_ipsearch_score(
            $qLower,
            $qNorm,
            $tokens,
            [
                (string) $row->domain,
                (string) $row->product_name,
                implode(' ', $ips),
            ]
        );
        if ($score <= 0) {
            continue;
        }

        $items[] = [
            'serviceid' => (int) $row->id,
            'hostname' => (string) ($row->domain ?: ('服务 #' . $row->id)),
            'title' => (string) ($row->domain ?: ('服务 #' . $row->id)),
            'product_name' => (string) ($row->product_name ?: ''),
            'ips' => $ips,
            'url' => '/clientarea.php?action=productdetails&id=' . (int) $row->id,
            '_score' => $score,
        ];
    }

    usort($items, function ($a, $b) {
        $sa = (int) ($a['_score'] ?? 0);
        $sb = (int) ($b['_score'] ?? 0);
        if ($sa === $sb) {
            return (int) ($b['serviceid'] ?? 0) <=> (int) ($a['serviceid'] ?? 0);
        }
        return $sb <=> $sa;
    });
    if (count($items) > 20) {
        $items = array_slice($items, 0, 20);
    }
    foreach ($items as &$item) {
        unset($item['_score']);
    }
    unset($item);

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    logActivity('VPS IP 搜索接口错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Internal Error']);
}
