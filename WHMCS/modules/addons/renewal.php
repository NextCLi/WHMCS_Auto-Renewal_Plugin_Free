<?php
/**
 * VPS 自定义月份续费处理
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

require_once __DIR__ . '/lib_vpsrenew.php';

$serviceId = isset($_GET['sid']) ? (int) $_GET['sid'] : 0;
$months = isset($_GET['months']) ? (int) $_GET['months'] : 0;
$fromAuto = isset($_GET['auto']) && (int) $_GET['auto'] === 1;

if (!$serviceId) {
    header('Location: /clientarea.php?action=services');
    exit;
}

if (!isset($_SESSION['uid']) || !$_SESSION['uid']) {
    header('Location: /login.php');
    exit;
}

$clientId = (int) $_SESSION['uid'];

try {
    $service = Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->where('userid', $clientId)
        ->first();

    if (!$service) {
        header('Location: /clientarea.php?action=services');
        exit;
    }

    $config = vpsrenew_get_module_config();
    if (!$config) {
        throw new Exception('VPS续费模块未激活或未配置');
    }

    $client = Capsule::table('tblclients')
        ->where('id', $clientId)
        ->first();

    if (!$client) {
        throw new Exception('客户不存在');
    }

    $monthlyPrice = vpsrenew_resolve_service_monthly_price_breakdown($service, (int) $client->currency, (int) ($config['max_months'] ?? 36));
    if (($monthlyPrice['total_monthly_price'] ?? 0) <= 0) {
        throw new Exception('无法获取当前服务续费金额');
    }

    $maxMonths = (int) $config['max_months'];

    if ($months < 1 || $months > $maxMonths) {
        $currency = vpsrenew_currency_parts($client->currency);

        $optionsHtml = '';
        for ($m = 1; $m <= $maxMonths; $m++) {
            $price = vpsrenew_calculate_price($monthlyPrice, $m, $config);
            $priceText = $currency['prefix'] . number_format($price['total'], 2) . $currency['suffix'];
            $label = $m . ' 个月 - ' . $priceText;
            if ($price['discount_amount'] > 0) {
                $label .= '（省 ' . $currency['prefix'] . number_format($price['discount_amount'], 2) . $currency['suffix'] . '）';
            }
            $optionsHtml .= '<option value="' . $m . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        $title = htmlspecialchars(($service->domain ?: ('服务 #' . $serviceId)), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>快速续费</title>';
        echo '<style>body{font-family:Arial,sans-serif;background:#f7f8fa;margin:0;padding:24px}.box{max-width:640px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px}.title{font-size:22px;margin:0 0 12px}.desc{color:#666;margin:0 0 16px}.row{margin-bottom:14px}.sel{width:100%;height:42px;padding:0 10px;border:1px solid #d1d5db;border-radius:6px}.btn{height:42px;border:0;border-radius:6px;padding:0 16px;cursor:pointer}.btn-primary{background:#1062fe;color:#fff}.btn-link{margin-left:8px;color:#666;text-decoration:none}</style>';
        echo '</head><body><div class="box">';
        echo '<h1 class="title">快速续费</h1>';
        echo '<p class="desc">服务：' . $title . '</p>';
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="sid" value="' . (int) $serviceId . '">';
        if ($fromAuto) {
            echo '<input type="hidden" name="auto" value="1">';
        }
        echo '<div class="row"><select class="sel" name="months" required>' . $optionsHtml . '</select></div>';
        echo '<button class="btn btn-primary" type="submit">立即续费</button>';
        echo '<a class="btn-link" href="/clientarea.php?action=productdetails&id=' . (int) $serviceId . '">返回产品详情</a>';
        echo '</form></div></body></html>';
        exit;
    }

    $existingUnpaid = vpsrenew_find_unpaid_service_invoice($serviceId, $clientId);
    if ($existingUnpaid) {
        header('Location: /viewinvoice.php?id=' . (int) $existingUnpaid->invoiceid);
        exit;
    }

    $price = vpsrenew_calculate_price($monthlyPrice, $months, $config);
    $productName = (string) Capsule::table('tblproducts')
        ->where('id', (int) $service->packageid)
        ->value('name');
    $serviceName = vpsrenew_build_service_title($service->domain, $productName, $serviceId);

    $invoiceId = vpsrenew_create_invoice(
        $clientId,
        $serviceId,
        $serviceName,
        $months,
        $price,
        $service->paymentmethod ?: 'mailin',
        true,
        false
    );

    logActivity('客户 #' . $clientId . ' 通过 VPS 续费模块为服务 #' . $serviceId . ' 创建了 ' . $months . ' 个月续费发票 #' . $invoiceId . '，金额：' . number_format($price['total'], 2));

    header('Location: /viewinvoice.php?id=' . $invoiceId);
    exit;
} catch (Exception $e) {
    logActivity('VPS 续费错误: ' . $e->getMessage());
    $errorMsg = urlencode($e->getMessage());
    header('Location: /clientarea.php?action=productdetails&id=' . $serviceId . '&errormessage=' . $errorMsg);
    exit;
}
