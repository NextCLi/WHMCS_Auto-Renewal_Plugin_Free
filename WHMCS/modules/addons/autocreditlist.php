<?php
/**
 * 自动续费管理页面
 */

$initFile = null;
$searchRoots = [];

$searchRoots[] = __DIR__;
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
    exit('Unable to locate WHMCS init.php');
}

require_once $initFile;

use WHMCS\Database\Capsule;
use WHMCS\ClientArea;

require_once __DIR__ . '/lib_vpsrenew.php';

if (!isset($_SESSION['uid']) || !$_SESSION['uid']) {
    header('Location: /login.php');
    exit;
}

$clientId = (int) $_SESSION['uid'];
$config = vpsrenew_get_module_config();
if (!$config) {
    http_response_code(403);
    exit('VPS续费模块未激活');
}
vpsrenew_ensure_tables();

$redirectSelf = vpsrenew_get_manager_path();
$maxMonths = max(1, min((int) ($config['max_months'] ?? 36), 36));
$strategyOptions = vpsrenew_get_strategy_options();

try {
    if (isset($_GET['toggle']) && (int) $_GET['toggle'] === 1) {
        $serviceId = isset($_GET['sid']) ? (int) $_GET['sid'] : 0;
        $enabled = isset($_GET['enabled']) ? (int) $_GET['enabled'] : 0;
        $months = isset($_GET['months']) ? (int) $_GET['months'] : 0;
        $renewMode = isset($_GET['renew_mode']) ? strtolower(trim((string) $_GET['renew_mode'])) : '';
        $isAjax = isset($_GET['ajax']) && (int) $_GET['ajax'] === 1;

        $service = Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->where('userid', $clientId)
            ->first();

        if ($service) {
            $current = vpsrenew_get_autorenew_settings($clientId, $serviceId);
            $currentMode = $current ? strtolower((string) $current->renew_mode) : 'cycle';
            if (!in_array($currentMode, ['month', 'cycle', 'fixed'], true)) {
                $currentMode = 'cycle';
            }
            $currentMonths = $current ? (int) $current->months : 1;
            $maxMonths = max(1, min((int) ($config['max_months'] ?? 36), 36));
            if ($months <= 0) {
                $months = $currentMonths > 0 ? $currentMonths : 1;
            }
            $months = max(1, min($maxMonths, $months));
            if (!in_array($renewMode, ['cycle', 'month', 'fixed'], true)) {
                $renewMode = $currentMode;
            }
            if ($renewMode === 'month') {
                $months = 1;
            }
            if ($renewMode === 'fixed') {
                $months = in_array($months, [1, 3, 6, 12], true) ? $months : 1;
            }
            $billingSync = null;
            if ($renewMode !== 'cycle') {
                $billingSync = vpsrenew_sync_service_billing_cycle($serviceId, $months, $config);
            }
            vpsrenew_set_autorenew_settings($clientId, $serviceId, $enabled === 1, $months, $renewMode);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'serviceid' => (int) $serviceId,
                    'enabled' => $enabled === 1 ? 1 : 0,
                    'months' => $months,
                    'renew_mode' => $renewMode,
                    'billingcycle' => $billingSync['billingcycle'] ?? null,
                    'billingcycle_label' => $billingSync['billingcycle_label'] ?? null,
                    'amount' => $billingSync['amount'] ?? null,
                    'amount_formatted' => $billingSync['amount_formatted'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        } else {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(404);
                echo json_encode([
                    'ok' => false,
                    'message' => 'Service not found',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }

        $return = $_GET['return'] ?? '';
        if ($return === 'productdetails' && $serviceId > 0) {
            header('Location: /clientarea.php?action=productdetails&id=' . $serviceId);
            exit;
        }
        if ($return === 'services') {
            header('Location: /clientarea.php?action=services');
            exit;
        }

        header('Location: ' . $redirectSelf);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $serviceId = isset($_POST['sid']) ? (int) $_POST['sid'] : 0;
        $isAjax = (isset($_POST['ajax']) && (int) $_POST['ajax'] === 1)
            || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        $enabled = isset($_POST['enabled'])
            ? ((int) $_POST['enabled'] === 1)
            : (isset($_POST['current_enabled']) && (int) $_POST['current_enabled'] === 1);
        $strategyKey = isset($_POST['strategy']) ? trim((string) $_POST['strategy']) : '';
        $payload = $strategyKey !== '' ? vpsrenew_strategy_key_to_payload($strategyKey) : null;
        $months = $payload ? (int) $payload['months'] : (isset($_POST['months']) ? (int) $_POST['months'] : 1);
        $renewMode = $payload ? (string) $payload['renew_mode'] : strtolower(trim((string) ($_POST['renew_mode'] ?? 'cycle')));
        if (!in_array($renewMode, ['month', 'cycle', 'fixed'], true)) {
            $renewMode = 'cycle';
        }
        if ($renewMode === 'month') {
            $months = 1;
        } elseif ($renewMode === 'fixed') {
            $months = in_array($months, [1, 3, 6, 12], true) ? $months : 1;
        } else {
            $months = max(1, min($maxMonths, $months));
        }

        $service = Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->where('userid', $clientId)
            ->first();

        if ($service) {
            $billingSync = null;
            if ($renewMode !== 'cycle') {
                $billingSync = vpsrenew_sync_service_billing_cycle($serviceId, $months, $config);
            }
            vpsrenew_set_autorenew_settings($clientId, $serviceId, $enabled, $months, $renewMode);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'serviceid' => (int) $serviceId,
                    'enabled' => $enabled ? 1 : 0,
                    'months' => $months,
                    'renew_mode' => $renewMode,
                    'strategy' => vpsrenew_resolve_strategy_key($renewMode, $months),
                    'billingcycle' => $billingSync['billingcycle'] ?? null,
                    'billingcycle_label' => $billingSync['billingcycle_label'] ?? null,
                    'amount' => $billingSync['amount'] ?? null,
                    'amount_formatted' => $billingSync['amount_formatted'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        } elseif ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'message' => 'Service not found',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Location: ' . $redirectSelf);
        exit;
    }

    $client = Capsule::table('tblclients')->where('id', $clientId)->first();
    $currencyId = $client ? (int) $client->currency : 0;
    $currency = vpsrenew_currency_parts($currencyId);

    $services = Capsule::table('tblhosting as h')
        ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->leftJoin(VPSRENEW_AUTORENEW_TABLE . ' as ar', function ($join) use ($clientId) {
            $join->on('ar.serviceid', '=', 'h.id')
                ->where('ar.userid', '=', $clientId);
        })
        ->where('h.userid', $clientId)
        ->whereIn('h.domainstatus', ['Active', 'Suspended', 'Expired', 'Terminated'])
        ->orderBy('h.id', 'desc')
        ->select([
            'h.id',
            'h.domain',
            'h.domainstatus',
            'h.nextduedate',
            'h.amount',
            'h.billingcycle',
            'h.packageid',
            'p.name as product_name',
            Capsule::raw('COALESCE(ar.enabled, 0) as ar_enabled'),
            Capsule::raw('COALESCE(ar.months, 1) as ar_months'),
            Capsule::raw("COALESCE(ar.renew_mode, 'cycle') as ar_renew_mode"),
        ])
        ->get();

    $rowsHtml = '';
    $totalCount = count($services);
    $enabledCount = 0;
    $todayYmd = date('Y-m-d');
    $expiring7Count = 0;
    $expiredRecoverableCount = 0;
    foreach ($services as $svc) {
        $serviceName = trim((string) ($svc->domain ?: ''));
        if ($serviceName === '') {
            $serviceName = '服务 #' . (int) $svc->id;
        }
        $productName = trim((string) ($svc->product_name ?: '产品'));
        $enabled = (int) $svc->ar_enabled === 1;
        $renewMode = strtolower((string) $svc->ar_renew_mode);
        if (!in_array($renewMode, ['month', 'cycle', 'fixed'], true)) {
            $renewMode = 'cycle';
        }
        $monthsValue = $renewMode === 'month' ? 1 : max(1, min((int) $svc->ar_months, $maxMonths));
        $billingCycleCn = vpsrenew_get_cycle_label($svc->billingcycle);
        $amountText = vpsrenew_format_amount_with_cycle((float) $svc->amount, $currency, $billingCycleCn);
        $dueMeta = vpsrenew_get_due_meta((string) $svc->nextduedate, $todayYmd);
        $strategyKey = vpsrenew_resolve_strategy_key($renewMode, $monthsValue);
        if ($enabled) {
            $enabledCount++;
        }
        if ($dueMeta['days'] !== null && $dueMeta['days'] >= 0 && $dueMeta['days'] <= 7) {
            $expiring7Count++;
        }
        if ($dueMeta['days'] !== null && $dueMeta['days'] < 0 && $dueMeta['days'] >= -2) {
            $expiredRecoverableCount++;
        }
        $toggleText = $enabled ? '已开启' : '未开启';
        $statusClass = $enabled ? 'on' : 'off';

        $rowsHtml .= '<tr data-nextdue="' . htmlspecialchars((string) $svc->nextduedate, ENT_QUOTES, 'UTF-8') . '" data-due-days="' . htmlspecialchars((string) ($dueMeta['days'] ?? ''), ENT_QUOTES, 'UTF-8') . '" data-due-filter="' . htmlspecialchars((string) $dueMeta['filter'], ENT_QUOTES, 'UTF-8') . '">';
        $rowsHtml .= '<td class="col-service"><a class="svc-link" href="/clientarea.php?action=productdetails&id=' . (int) $svc->id . '">' . htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') . '</a><div class="subtxt">服务ID #' . (int) $svc->id . '</div></td>';
        $rowsHtml .= '<td class="col-product"><div class="prod-name">' . htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') . '</div></td>';
        $statusKey = strtolower((string) $svc->domainstatus);
        $statusColor = ($statusKey === 'active') ? 'active' : (($statusKey === 'suspended') ? 'suspended' : 'expired');
        $rowsHtml .= '<td><span class="status-pill status-' . $statusColor . '">' . htmlspecialchars((string) $svc->domainstatus, ENT_QUOTES, 'UTF-8') . '</span></td>';
        $rowsHtml .= '<td class="date-cell">' . htmlspecialchars((string) $dueMeta['date_display'], ENT_QUOTES, 'UTF-8') . ($dueMeta['text'] ? '<div class="due-note due-' . $dueMeta['class'] . '">' . htmlspecialchars($dueMeta['text'], ENT_QUOTES, 'UTF-8') . '</div>' : '') . '</td>';
        $rowsHtml .= '<td class="cycle-cell">' . htmlspecialchars($billingCycleCn, ENT_QUOTES, 'UTF-8') . '</td>';
        $rowsHtml .= '<td class="amount-cell">' . htmlspecialchars($amountText, ENT_QUOTES, 'UTF-8') . '</td>';
        $rowsHtml .= '<td class="autorenew-cell"><span class="tag ' . $statusClass . '">' . $toggleText . '</span></td>';
        $rowsHtml .= '<td class="action-cell">';
        $rowsHtml .= '<form method="post" action="" class="inline-form action-form">';
        $rowsHtml .= '<input type="hidden" name="sid" value="' . (int) $svc->id . '">';
        $rowsHtml .= '<input type="hidden" name="current_enabled" value="' . ($enabled ? '1' : '0') . '">';
        $rowsHtml .= '<select name="strategy" class="strategy-select form-control-sm"' . ($enabled ? '' : ' disabled') . '>';
        foreach ($strategyOptions as $optionKey => $optionLabel) {
            $rowsHtml .= '<option value="' . htmlspecialchars((string) $optionKey, ENT_QUOTES, 'UTF-8') . '"' . ($strategyKey === (string) $optionKey ? ' selected' : '') . '>' . htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $rowsHtml .= '</select>';
        $rowsHtml .= '<button type="submit" class="btn btn-sm btn-auto-toggle ' . ($enabled ? 'btn-auto-on' : 'btn-auto-off') . '" name="enabled" value="' . ($enabled ? '0' : '1') . '"><span class="sw-track"><span class="sw-dot"></span></span><span class="sw-text">' . ($enabled ? '自动续费开' : '自动续费关') . '</span></button>';
        $renewUrl = '/modules/addons/vpsrenew/renewal.php?sid=' . (int) $svc->id;
        $options = [];
        $monthlyPrice = vpsrenew_resolve_service_monthly_price_breakdown($svc, $currencyId, $maxMonths);
        if (($monthlyPrice['total_monthly_price'] ?? 0) > 0) {
            for ($m = 1; $m <= $maxMonths; $m++) {
                $calc = vpsrenew_calculate_price($monthlyPrice, $m, $config);
                $label = $m . ' 个月 - ' . $currency['prefix'] . number_format($calc['total'], 2) . $currency['suffix'];
                if ($calc['discount_amount'] > 0) {
                    $label .= '（省 ' . $currency['prefix'] . number_format($calc['discount_amount'], 2) . $currency['suffix'] . '）';
                }
                $options[] = [
                    'months' => $m,
                    'label' => $label,
                    'price' => $currency['prefix'] . number_format($calc['total'], 2) . $currency['suffix'],
                ];
            }
        }
        $optionsJson = htmlspecialchars(json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
        $rowsHtml .= '<a class="btn btn-renew btn-sm js-renew-manual" data-renew-url="' . htmlspecialchars($renewUrl, ENT_QUOTES, 'UTF-8') . '" data-options="' . $optionsJson . '" href="' . htmlspecialchars($renewUrl, ENT_QUOTES, 'UTF-8') . '">手动续费</a>';
        $rowsHtml .= '</form>';
        $rowsHtml .= '</td>';
        $rowsHtml .= '</tr>';
    }
    $disabledCount = max(0, $totalCount - $enabledCount);

    $balanceUrl = '/clientarea.php?action=addfunds';

    ob_start();
    echo '<style>';
    echo '.vpsrenew-manager-page{font-family:"PingFang SC","Microsoft YaHei",Arial,sans-serif;color:#0f172a}';
    echo '.wrap{max-width:1680px;margin:0 auto}';
    echo '.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px}';
    echo 'h1{margin:0 0 12px;font-size:38px;line-height:1.15}';
    echo '.subtitle{margin:0 0 16px;color:#64748b;font-size:14px}';
    echo '.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 16px}';
    echo '.stat{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px}';
    echo '.stat .k{font-size:12px;color:#64748b}';
    echo '.stat .v{margin-top:4px;font-size:20px;font-weight:700;color:#0f172a}';
    echo '.tips{background:#f8fafc;border:1px solid #dbe3ee;border-radius:10px;padding:12px 14px;margin:0 0 16px}';
    echo '.tips li{margin:6px 0}';
    echo '.table-wrap{overflow:auto;border-radius:10px;border:1px solid #e5e7eb}';
    echo '.table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px;background:#fff;min-width:1280px;table-layout:auto}';
    echo '.table th,.table td{border-bottom:1px solid #eef1f6;padding:12px 10px;vertical-align:middle;text-align:left}';
    echo '.table th{position:sticky;top:0;background:#f8fafc;color:#334155;font-weight:700;z-index:2}';
    echo '.table tbody tr:hover{background:#f8fbff}';
    echo '.col-service{max-width:220px}';
    echo '.col-product{min-width:220px;max-width:280px}';
    echo '.svc-link{font-weight:600;line-height:1.35;display:inline-block}';
    echo '.prod-name{font-weight:600;line-height:1.35}';
    echo '.date-cell,.amount-cell,.cycle-cell{white-space:nowrap}';
    echo '.cycle-cell{min-width:74px}';
    echo '.amount-cell{min-width:150px}';
    echo '.action-cell{min-width:430px;white-space:nowrap}';
    echo '.autorenew-cell{min-width:96px;white-space:nowrap}';
    echo '.status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600}';
    echo '.status-pill.status-active{background:#ecfdf3;color:#15803d}';
    echo '.status-pill.status-suspended{background:#fff7ed;color:#c2410c}';
    echo '.status-pill.status-expired{background:#f1f5f9;color:#475569}';
    echo '.tag{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:3px 12px;font-size:12px;line-height:1.2;white-space:nowrap;min-width:72px}';
    echo '.tag.on{background:#e7f9ef;color:#0f8a4b}';
    echo '.tag.off{background:#f3f4f6;color:#6b7280}';
    echo '.subtxt{margin-top:4px;color:#64748b;font-size:12px}';
    echo '.due-note{margin-top:4px;font-size:12px}';
    echo '.due-note.due-warn{color:#c2410c}';
    echo '.due-note.due-danger{color:#b91c1c}';
    echo '.due-note.due-muted{color:#6b7280}';
    echo '.btn{display:inline-flex;align-items:center;justify-content:center;height:34px;line-height:1;padding:0 12px;border-radius:8px;border:1px solid transparent;text-decoration:none;cursor:pointer;font-size:13px}';
    echo '.btn-sm{height:32px;padding:0 10px}';
    echo '.btn-primary{background:#2563eb;color:#fff}';
    echo '.btn-warn{background:#f59e0b;color:#fff}';
    echo '.btn-default{background:#fff;border-color:#d1d5db;color:#111827}';
    echo '.btn-renew{background:#2563eb;border-color:#2563eb;color:#fff}';
    echo '.btn-renew:hover{color:#fff;opacity:.92}';
    echo '.btn-auto-toggle{height:32px;padding:0 8px 0 6px;border-radius:999px;background:#f3f4f6;color:#111827;border:1px solid #d1d5db;display:inline-flex;align-items:center;gap:8px;transition:all .16s ease}';
    echo '.btn-auto-toggle .sw-track{width:34px;height:20px;border-radius:999px;background:#d1d5db;position:relative;transition:all .16s ease}';
    echo '.btn-auto-toggle .sw-dot{position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .16s ease}';
    echo '.btn-auto-toggle .sw-text{font-size:12px;line-height:1;white-space:nowrap}';
    echo '.btn-auto-toggle.btn-auto-on{background:#ecfdf5;border-color:#86efac;color:#166534}';
    echo '.btn-auto-toggle.btn-auto-on .sw-track{background:#22c55e}';
    echo '.btn-auto-toggle.btn-auto-on .sw-dot{left:16px}';
    echo '.btn-auto-toggle.btn-auto-off{background:#f9fafb;border-color:#d1d5db;color:#374151}';
    echo '.btn-auto-toggle.is-loading{opacity:.72;pointer-events:none}';
    echo '.form-control-sm{height:32px;border:1px solid #d1d5db;border-radius:8px;padding:0 8px;background:#fff;color:#111827;font-size:13px}';
    echo '.strategy-select{width:230px;min-width:230px;max-width:230px}';
    echo '.strategy-select:disabled{background:#f3f4f6;color:#9ca3af;cursor:not-allowed}';
    echo '.inline-form{display:flex;gap:8px;align-items:center;flex-wrap:nowrap;white-space:nowrap}';
    echo '.action-form{max-width:none}';
    echo '.toolbar{display:flex;justify-content:space-between;gap:12px;flex-wrap:nowrap;align-items:center;margin-bottom:12px}';
    echo '.toolbar-left,.toolbar-right{display:flex;gap:10px;flex-wrap:nowrap;align-items:center}';
    echo '.toolbar-left{flex:1;min-width:0}';
    echo '.filter-input,.filter-select{height:34px;border:1px solid #d1d5db;border-radius:8px;padding:0 10px;background:#fff}';
    echo '.filter-input{flex:1 1 360px;min-width:260px}';
    echo '.filter-select{flex:0 0 180px;min-width:180px}';
    echo '.stat-btn{border:1px solid #e5e7eb;background:#fff;cursor:pointer;text-align:left;width:100%}';
    echo '.stat-btn.active{border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.08)}';
    echo '#vpsRenewModalOverlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:none}';
    echo '#vpsRenewModal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(520px,92vw);background:#fff;border-radius:10px;z-index:10000;display:none;box-shadow:0 12px 30px rgba(0,0,0,.2)}';
    echo '#vpsRenewModal .vpsm-hd{padding:16px 20px;border-bottom:1px solid #eee;font-size:20px;font-weight:600;display:flex;justify-content:space-between;align-items:center}';
    echo '#vpsRenewModal .vpsm-bd{padding:16px 20px}';
    echo '#vpsRenewModal .vpsm-ft{padding:12px 20px;border-top:1px solid #eee;display:flex;justify-content:flex-end;gap:10px}';
    echo '#vpsRenewModal .vpsm-close{cursor:pointer;font-size:24px;line-height:1;color:#999}';
    echo '#vpsRenewModal select{width:100%;height:40px;border:1px solid #d0d7de;border-radius:6px;padding:0 10px}';
    echo '#vpsRenewModal .vpsm-price{margin-top:10px;color:#333}';
    echo '.vps-toast{position:fixed;right:16px;bottom:16px;z-index:10001;padding:10px 12px;border-radius:8px;background:#0f172a;color:#fff;font-size:13px;opacity:0;transform:translateY(8px);pointer-events:none;transition:all .2s ease}';
    echo '.vps-toast.show{opacity:1;transform:translateY(0)}';
    echo '.vps-toast.err{background:#b91c1c}';
    echo '@media (max-width:1280px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}';
    echo '@media (max-width:980px){body{padding:12px}.stats{grid-template-columns:1fr}.table{font-size:13px;min-width:1120px}.inline-form{flex-direction:row;align-items:center}.action-form{max-width:none}.toolbar,.toolbar-left,.toolbar-right{flex-wrap:wrap}.strategy-select,.filter-input,.filter-select{width:100%;min-width:0;max-width:none;flex:1 1 100%}h1{font-size:28px}}';
    echo '</style>';
    echo '<div class="vpsrenew-manager-page"><div class="wrap">';
    echo '<div class="stats">';
    echo '<button type="button" class="stat stat-btn" data-filter-target="all"><div class="k">服务总数</div><div class="v">' . (int) $totalCount . '</div></button>';
    echo '<button type="button" class="stat stat-btn" data-filter-target="7"><div class="k">7天内到期</div><div class="v">' . (int) $expiring7Count . '</div></button>';
    echo '<button type="button" class="stat stat-btn" data-filter-target="recoverable"><div class="k">已过期2日内</div><div class="v">' . (int) $expiredRecoverableCount . '</div></button>';
    echo '<button type="button" class="stat stat-btn" data-filter-target="enabled"><div class="k">已开启自动续费</div><div class="v">' . (int) $enabledCount . '</div></button>';
    echo '</div>';
    echo '<div class="card tips"><strong>提示：</strong><ul>';
    echo '<li>如果您的服务已经为其生成了续订账单，【自动续费】不会支付已生成账单，<u>您仍需手动支付</u>；</li>';
    echo '<li>请务必注意【自动续费】开启后扣除的金额不支持退款，请谨慎决策，也请勿以此为理由申请退款；</li>';
    echo '<li>当前仅服务器/VPS支持自动续费，[域名]和[附加服务]暂不支持自动续费。</li>';
    echo '<li>您可以在此设置自动续费的产品，到期时会自动从余额扣除续费金额 <a href="' . $balanceUrl . '">查看账户余额</a></li>';
    echo '</ul></div>';

    echo '<div class="card">';
    echo '<div class="toolbar">';
    echo '<div class="toolbar-left">';
    echo '<input id="svcSearch" class="filter-input" type="text" placeholder="搜索 IP / 产品型号">';
    echo '<select id="dueFilter" class="filter-select">';
    echo '<option value="all">全部到期状态</option><option value="today">今日到期</option><option value="7">7天内到期</option><option value="30">30天内到期</option><option value="expired">已过期</option><option value="recoverable">已过期2日内(可续费找回)</option>';
    echo '</select>';
    echo '<select id="renewFilter" class="filter-select">';
    echo '<option value="all">全部自动续费状态</option><option value="enabled">仅看已开启</option><option value="disabled">仅看未开启</option>';
    echo '</select>';
    echo '</div>';
    echo '<div class="toolbar-right">';
    echo '<a class="btn btn-default" href="/clientarea.php?action=services">返回我的产品与服务</a>';
    echo '</div>';
    echo '</div>';
    echo '<div class="table-wrap">';
    echo '<table class="table" id="autoRenewTable">';
    echo '<thead><tr><th>产品/服务</th><th>型号规格</th><th>状态</th><th>到期时间</th><th>当前账单周期</th><th>金额</th><th>自动续费</th><th>操作</th></tr></thead>';
    echo '<tbody>' . $rowsHtml . '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    echo '<div id="vpsRenewModalOverlay"></div>';
    echo '<div id="vpsRenewModal" role="dialog" aria-modal="true" aria-label="手动续费">';
    echo '<div class="vpsm-hd"><span>手动续费</span><span class="vpsm-close" id="vpsRenewModalClose">&times;</span></div>';
    echo '<div class="vpsm-bd"><label for="vpsRenewMonthsSelect">请选择续费月份</label><select id="vpsRenewMonthsSelect"></select><div class="vpsm-price">价格：<strong id="vpsRenewPriceText"></strong></div></div>';
    echo '<div class="vpsm-ft"><button type="button" class="btn btn-default" id="vpsRenewCancelBtn">取消</button><button type="button" class="btn btn-primary" id="vpsRenewSubmitBtn">确认续费</button></div>';
    echo '</div>';
    echo '<div id="vpsToast" class="vps-toast"></div>';
    echo '<script>(function(){';
    echo 'var overlay=document.getElementById("vpsRenewModalOverlay"),modal=document.getElementById("vpsRenewModal"),sel=document.getElementById("vpsRenewMonthsSelect"),price=document.getElementById("vpsRenewPriceText");';
    echo 'var closeBtn=document.getElementById("vpsRenewModalClose"),cancelBtn=document.getElementById("vpsRenewCancelBtn"),submitBtn=document.getElementById("vpsRenewSubmitBtn");';
    echo 'var toast=document.getElementById("vpsToast");';
    echo 'var currentUrl="";';
    echo 'var strategyTextMap={cycle:"按当前账单周期","1":"月","3":"季","6":"半年","12":"年"};';
    echo 'function close(){overlay.style.display="none";modal.style.display="none";currentUrl="";}';
    echo 'function updatePrice(){var op=sel.options[sel.selectedIndex];if(op){price.textContent=op.getAttribute("data-price")||"";}}';
    echo 'var toastTimer=0;function showToast(msg,isErr){if(!toast){return;}toast.textContent=msg||"";toast.className="vps-toast"+(isErr?" err":"")+" show";clearTimeout(toastTimer);toastTimer=setTimeout(function(){toast.className="vps-toast"+(isErr?" err":"");},1500);}';
    echo 'function submitStrategy(form,extra,onOk,onErr){var fd=new FormData(form);fd.set("ajax","1");if(extra){Object.keys(extra).forEach(function(key){fd.set(key,extra[key]);});}fetch(window.location.pathname,{method:"POST",body:fd,headers:{"X-Requested-With":"XMLHttpRequest"}}).then(function(r){if(!r.ok){throw new Error("save failed");}return r.json();}).then(function(j){if(!j||!j.ok){throw new Error("save failed");}if(onOk) onOk(j);}).catch(function(){if(onErr) onErr();});}';
    echo 'function syncBillingCells(form,resp){if(!form||!resp){return;}var tr=form.closest("tr");if(!tr){return;}var cycleCell=tr.querySelector(".cycle-cell");var amountCell=tr.querySelector(".amount-cell");if(cycleCell&&resp.billingcycle_label){cycleCell.textContent=resp.billingcycle_label;}if(amountCell&&resp.amount_formatted){amountCell.textContent=resp.amount_formatted;}}';
    echo 'document.querySelectorAll(".strategy-select").forEach(function(s){s.setAttribute("data-prev",s.value);});';
    echo 'document.addEventListener("change",function(e){var select=e.target.closest(".strategy-select");if(!select){return;}var form=select.closest("form");if(!form||select.disabled){return;}var oldValue=select.getAttribute("data-prev")||select.value;submitStrategy(form,null,function(resp){select.setAttribute("data-prev",resp.strategy||select.value);syncBillingCells(form,resp);showToast("续费周期已更新为 " + (strategyTextMap[resp.strategy||select.value]||"月"),false);},function(){select.value=oldValue;showToast("保存失败，请重试",true);});});';
    echo 'document.addEventListener("click",function(e){var btn=e.target.closest("button[name=enabled]");if(!btn){return;}e.preventDefault();var form=btn.closest("form");if(!form){return;}var willEnable=(btn.value==="1");submitStrategy(form,{enabled:willEnable?"1":"0"},function(resp){form.querySelector("input[name=current_enabled]").value=willEnable?"1":"0";btn.value=willEnable?"0":"1";btn.className="btn btn-sm btn-auto-toggle "+(willEnable?"btn-auto-on":"btn-auto-off");var textEl=btn.querySelector(".sw-text");if(textEl){textEl.textContent=willEnable?"自动续费开":"自动续费关";}var strategy=form.querySelector(".strategy-select");if(strategy){strategy.disabled=!willEnable;}var tag=form.closest("tr").querySelector(".tag");if(tag){tag.className="tag "+(willEnable?"on":"off");tag.textContent=willEnable?"已开启":"未开启";}syncBillingCells(form,resp);filterRows();showToast(willEnable?"已开启自动续费":"未开启自动续费",false);},function(){showToast("切换失败，请重试",true);});});';
    echo 'var svcSearch=document.getElementById("svcSearch"),dueFilter=document.getElementById("dueFilter"),renewFilter=document.getElementById("renewFilter"),table=document.getElementById("autoRenewTable");';
    echo 'function syncStatButtons(){document.querySelectorAll(".stat-btn").forEach(function(btn){var target=btn.getAttribute("data-filter-target")||"all";var active=(target==="enabled"?(renewFilter&&renewFilter.value==="enabled"):(dueFilter&&dueFilter.value===target)||(target==="all"&&dueFilter&&dueFilter.value==="all"&&renewFilter&&renewFilter.value==="all"));btn.classList.toggle("active",!!active);});}';
    echo 'function filterRows(){if(!table)return;var q=(svcSearch&&svcSearch.value?svcSearch.value:"").toLowerCase().trim();var dueValue=dueFilter&&dueFilter.value?dueFilter.value:"all";var renewValue=renewFilter&&renewFilter.value?renewFilter.value:"all";table.querySelectorAll("tbody tr").forEach(function(tr){var txt=(tr.textContent||"").toLowerCase();var okQ=!q||txt.indexOf(q)!==-1;var dueAttr=tr.getAttribute("data-due-filter")||"all";var days=parseInt(tr.getAttribute("data-due-days")||"9999",10);var enabledText=(tr.querySelector(".tag")&&tr.querySelector(".tag").textContent)||"";var isEnabled=enabledText.indexOf("已开启")!==-1;var okDue=true;if(dueValue==="today"){okDue=(days===0);}else if(dueValue==="7"){okDue=(days>=0&&days<=7);}else if(dueValue==="30"){okDue=(days>=0&&days<=30);}else if(dueValue==="expired"){okDue=(days<0);}else if(dueValue==="recoverable"){okDue=(days<0&&days>=-2);}var okRenew=true;if(renewValue==="enabled"){okRenew=isEnabled;}else if(renewValue==="disabled"){okRenew=!isEnabled;}tr.style.display=(okQ&&okDue&&okRenew)?"":"none";});syncStatButtons();}';
    echo 'if(svcSearch)svcSearch.addEventListener("input",filterRows);if(dueFilter)dueFilter.addEventListener("change",filterRows);if(renewFilter)renewFilter.addEventListener("change",filterRows);';
    echo 'document.querySelectorAll(".stat-btn").forEach(function(btn){btn.addEventListener("click",function(){var target=btn.getAttribute("data-filter-target")||"all";if(target==="enabled"){if(renewFilter)renewFilter.value="enabled";if(dueFilter)dueFilter.value="all";}else{if(dueFilter)dueFilter.value=target;if(renewFilter)renewFilter.value="all";}filterRows();});});';
    echo 'document.addEventListener("click",function(e){var a=e.target.closest(".js-renew-manual");if(!a)return;e.preventDefault();var opts=[];try{opts=JSON.parse(a.getAttribute("data-options")||"[]");}catch(_){opts=[];}currentUrl=a.getAttribute("data-renew-url")||a.getAttribute("href")||"";if(!opts.length){window.location.href=currentUrl;return;}sel.innerHTML="";opts.forEach(function(item){var op=document.createElement("option");op.value=item.months;op.textContent=item.label;op.setAttribute("data-price",item.price||"");sel.appendChild(op);});updatePrice();overlay.style.display="block";modal.style.display="block";});';
    echo 'sel.addEventListener("change",updatePrice);overlay.addEventListener("click",close);closeBtn.addEventListener("click",close);cancelBtn.addEventListener("click",close);';
    echo 'submitBtn.addEventListener("click",function(){var m=parseInt(sel.value||"1",10)||1;if(!currentUrl){return;}window.location.href=currentUrl+"&months="+m;});';
    echo 'filterRows();';
    echo '})();</script>';
    echo '</div></div>';
    $pageHtml = ob_get_clean();

    if (defined('VPSRENEW_EMBED_CLIENTAREA') && VPSRENEW_EMBED_CLIENTAREA === true) {
        $ca = new ClientArea();
        $ca->setPageTitle('自动续费管理');
        $ca->addToBreadCrumb('clientarea.php', '门户首页');
        $ca->addToBreadCrumb('clientarea.php', '用户中心');
        $ca->addToBreadCrumb(vpsrenew_get_manager_path(), '自动续费管理');
        $ca->initPage();
        $ca->requireLogin();
        $ca->assign('vpsAutoRenewManagerContent', $pageHtml);
        $ca->setTemplate('autorenew-manager');
        $ca->output();
        exit;
    }

    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>自动续费管理</title></head><body style="margin:0;padding:24px;background:#f3f6fb">';
    echo $pageHtml;
    echo '</body></html>';
} catch (Exception $e) {
    logActivity('VPS 自动续费管理页错误: ' . $e->getMessage());
    http_response_code(500);
    echo 'Internal Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
