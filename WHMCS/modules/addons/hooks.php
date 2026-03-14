<?php
/**
 * VPS Renew - Hooks
 */

use WHMCS\Database\Capsule;
use WHMCS\View\Menu\Item;

if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib_vpsrenew.php';
try {
	vpsrenew_ensure_tables();
} catch (Exception $e) {
	logActivity('VPS Renewal 表初始化失败: ' . $e->getMessage());
}

/**
 * 产品详情页变量
 */
add_hook('ClientAreaPageProductDetails', 1, function ($vars) {
	if (($vars['filename'] ?? '') !== 'clientarea' || ($vars['action'] ?? '') !== 'productdetails') {
		return [];
	}

	$serviceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
	$clientId = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;

	if ($serviceId <= 0 || $clientId <= 0) {
		return [];
	}

	try {
		$config = vpsrenew_get_module_config();
		if (!$config) {
			return [];
		}

		$renewalOptions = vpsrenew_get_service_renewal_options($clientId, $serviceId, $config);
		if (empty($renewalOptions)) {
			return [];
		}

		$ar = vpsrenew_get_autorenew_settings($clientId, $serviceId);

		return [
			'vpsRenewalOptions' => $renewalOptions,
			'vpsServiceId' => $serviceId,
			'vpsHasRenewalOptions' => !empty($renewalOptions),
			'vpsAutoRenewEnabled' => $ar ? ((int) $ar->enabled === 1) : false,
			'vpsAutoRenewMonths' => $ar ? (int) $ar->months : 1,
		];
	} catch (Exception $e) {
		logActivity('VPS Renewal Hook Error: ' . $e->getMessage());
		return [];
	}
});

/**
 * 产品详情页左侧菜单：快速续费 + 自动续费开关
 */
add_hook('ClientAreaPrimarySidebar', 1, function (Item $primarySidebar) {
	if (($_GET['action'] ?? '') !== 'productdetails' || !isset($_GET['id']) || !isset($_SESSION['uid'])) {
		return;
	}

	$serviceId = (int) $_GET['id'];
	$clientId = (int) $_SESSION['uid'];
	if ($serviceId <= 0 || $clientId <= 0) {
		return;
	}

	try {
		$config = vpsrenew_get_module_config();
		if (!$config) {
			return;
		}

		$service = Capsule::table('tblhosting')
			->where('id', $serviceId)
			->where('userid', $clientId)
			->first();
		if (!$service) {
			return;
		}

		$baseUrl = rtrim(\WHMCS\Utility\Environment\WebHelper::getBaseUrl(), '/');

		$actions = $primarySidebar->getChild('Service Details Actions');
		if (!$actions) {
			$actions = $primarySidebar->getChild('Actions');
		}
		if (!$actions) {
			return;
		}

		$renewUrl = $baseUrl . '/modules/addons/vpsrenew/renewal.php?sid=' . $serviceId;
		if ($actions->getChild('VPS Quick Renew') === null) {
			$actions->addChild('VPS Quick Renew', [
				'label' => '续费',
				'uri' => $renewUrl,
				'order' => 6,
				'icon' => 'fas fa-sync',
			]);
		}

		if ($actions->getChild('VPS Auto Renew Strategy') === null) {
			$actions->addChild('VPS Auto Renew Strategy', [
				'label' => '自动续费管理',
				'uri' => vpsrenew_build_manager_url($baseUrl),
				'order' => 7,
				'icon' => 'fas fa-sliders-h',
			]);
		}

		$autoCfg = vpsrenew_get_autorenew_settings($clientId, $serviceId);
		$enabled = $autoCfg ? ((int) $autoCfg->enabled === 1) : false;
		$months = $autoCfg ? (int) $autoCfg->months : 1;
		$renewMode = ($autoCfg && strtolower((string) ($autoCfg->renew_mode ?? '')) === 'month') ? 'month' : 'cycle';

		$toggleUrl = vpsrenew_build_manager_url($baseUrl) . '?toggle=1&sid=' . $serviceId . '&enabled=' . ($enabled ? 0 : 1) . '&months=' . $months . '&renew_mode=' . $renewMode . '&return=productdetails';

		if ($actions->getChild('VPS Auto Renew') === null) {
			$actions->addChild('VPS Auto Renew', [
				'label' => $enabled ? '关闭自动续费' : '开启自动续费',
				'uri' => $toggleUrl,
				'order' => 8,
				'icon' => $enabled ? 'fas fa-toggle-on' : 'fas fa-toggle-off',
			]);
		}

		$unpaid = vpsrenew_find_unpaid_service_invoice($serviceId, $clientId);
		if ($unpaid && $actions->getChild('VPS Unpaid Invoice') === null) {
			$actions->addChild('VPS Unpaid Invoice', [
				'label' => '查看未支付续费账单 #' . (int) $unpaid->invoiceid,
				'uri' => $baseUrl . '/viewinvoice.php?id=' . (int) $unpaid->invoiceid,
				'order' => 9,
				'icon' => 'fas fa-file-invoice-dollar',
			]);
		}
	} catch (Exception $e) {
		logActivity('VPS Renewal Sidebar Hook Error: ' . $e->getMessage());
	}
});

/**
 * 导航菜单增加“自动续费管理”
 */
add_hook('ClientAreaPrimaryNavbar', 1, function (Item $primaryNavbar) {
	if (!isset($_SESSION['uid']) || !$_SESSION['uid']) {
		return;
	}

	try {
		$config = vpsrenew_get_module_config();
		if (!$config) {
			return;
		}

		$baseUrl = rtrim(\WHMCS\Utility\Environment\WebHelper::getBaseUrl(), '/');
		$billing = $primaryNavbar->getChild('Billing');
		if ($billing && $billing->getChild('VPS Auto Renew Manager') === null) {
			$billing->addChild('VPS Auto Renew Manager', [
				'label' => '自动续费管理',
				'uri' => vpsrenew_build_manager_url($baseUrl),
				'order' => 120,
				'icon' => 'fas fa-sync-alt',
			]);
		}
	} catch (Exception $e) {
		logActivity('VPS Renewal Navbar Hook Error: ' . $e->getMessage());
	}
});

/**
 * 我的账单页侧边栏增加“自动续费管理”
 */
add_hook('ClientAreaSecondarySidebar', 5, function (Item $secondarySidebar) {
	if (!isset($_SESSION['uid']) || !$_SESSION['uid']) {
		return;
	}

	$action = $_GET['action'] ?? '';
	$filename = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
	$isInvoicePage = $action === 'invoices' || $action === 'viewinvoice' || $filename === 'viewinvoice.php';
	$isServicesPage = $action === 'services' || $action === 'products';
	if (!$isInvoicePage && !$isServicesPage) {
		return;
	}

	try {
		$config = vpsrenew_get_module_config();
		if (!$config) {
			return;
		}

		$baseUrl = rtrim(\WHMCS\Utility\Environment\WebHelper::getBaseUrl(), '/');
		$target = null;
		if ($isServicesPage) {
			$target = $secondarySidebar->getChild('My Services Actions');
			if (!$target) {
				$target = $secondarySidebar->getChild('Actions');
			}
		} else {
			$target = $secondarySidebar->getChild('Actions');
			if (!$target) {
				$target = $secondarySidebar->getChild('Billing Overview');
			}
		}
		if (!$target) {
			return;
		}

		$label = '自动续费管理';
		if ($target->getChild('VPS Auto Renew Sidebar Link') === null) {
			$target->addChild('VPS Auto Renew Sidebar Link', [
				'label' => $label,
				'uri' => vpsrenew_build_manager_url($baseUrl),
				'order' => 90,
				'icon' => 'fas fa-sync-alt',
			]);
		}
	} catch (Exception $e) {
		logActivity('VPS Renewal Secondary Sidebar Hook Error: ' . $e->getMessage());
	}
});

/**
 * 我的产品与服务页隐藏左侧“操作”侧栏
 */
// add_hook('ClientAreaSecondarySidebar', 1, function (Item $secondarySidebar) {
//     $action = $_GET['action'] ?? '';
//     if ($action !== 'services' && $action !== 'products') {
//         return;
//     }

//     if ($secondarySidebar->getChild('My Services Actions') !== null) {
//         $secondarySidebar->removeChild('My Services Actions');
//     }
//     if ($secondarySidebar->getChild('Actions') !== null) {
//         $secondarySidebar->removeChild('Actions');
//     }
// });

/**
 * 产品详情页底部注入“续费月份选择”弹窗
 */
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
	if (($vars['filename'] ?? '') !== 'clientarea' || ($vars['action'] ?? '') !== 'productdetails' || !isset($_GET['id']) || !isset($_SESSION['uid'])) {
		return '';
	}

	$serviceId = (int) $_GET['id'];
	$clientId = (int) $_SESSION['uid'];
	if ($serviceId <= 0 || $clientId <= 0) {
		return '';
	}

	try {
		$config = vpsrenew_get_module_config();
		if (!$config) {
			return '';
		}

		$service = Capsule::table('tblhosting')
			->where('id', $serviceId)
			->where('userid', $clientId)
			->first();
		if (!$service) {
			return '';
		}

		$baseUrl = rtrim(\WHMCS\Utility\Environment\WebHelper::getBaseUrl(), '/');
		$renewBaseUrl = $baseUrl . '/modules/addons/vpsrenew/renewal.php?sid=' . $serviceId;
		$renewOptionsUrl = $baseUrl . '/modules/addons/vpsrenew/renew_options.php';

		return '
<style>
#vpsRenewModalOverlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:none}
#vpsRenewModal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(520px,92vw);background:#fff;border-radius:10px;z-index:10000;display:none;box-shadow:0 12px 30px rgba(0,0,0,.2)}
#vpsRenewModal .vpsm-hd{padding:16px 20px;border-bottom:1px solid #eee;font-size:20px;font-weight:600;display:flex;justify-content:space-between;align-items:center}
#vpsRenewModal .vpsm-bd{padding:16px 20px}
#vpsRenewModal .vpsm-ft{padding:12px 20px;border-top:1px solid #eee;display:flex;justify-content:flex-end;gap:10px}
#vpsRenewModal .vpsm-close{cursor:pointer;font-size:24px;line-height:1;color:#999}
#vpsRenewModal select{width:100%;height:40px;border:1px solid #d0d7de;border-radius:6px;padding:0 10px}
</style>
<div id="vpsRenewModalOverlay"></div>
<div id="vpsRenewModal" role="dialog" aria-modal="true" aria-label="快速续费">
  <div class="vpsm-hd">
    <span>快速续费</span>
    <span class="vpsm-close" id="vpsRenewModalClose">&times;</span>
  </div>
  <div class="vpsm-bd">
    <label for="vpsRenewMonthsSelect">请选择续费月份</label>
    <select id="vpsRenewMonthsSelect"></select>
  </div>
  <div class="vpsm-ft">
    <button type="button" class="btn btn-default" id="vpsRenewCancelBtn">取消</button>
    <button type="button" class="btn btn-primary" id="vpsRenewSubmitBtn">确认续费</button>
  </div>
</div>
<script>
(function(){
  var renewBaseUrl = ' . json_encode($renewBaseUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';
  var renewOptionsUrl = ' . json_encode($renewOptionsUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';
  var sid = ' . (int) $serviceId . ';

  var overlay = document.getElementById("vpsRenewModalOverlay");
  var modal = document.getElementById("vpsRenewModal");
  var closeBtn = document.getElementById("vpsRenewModalClose");
  var cancelBtn = document.getElementById("vpsRenewCancelBtn");
  var submitBtn = document.getElementById("vpsRenewSubmitBtn");
  var select = document.getElementById("vpsRenewMonthsSelect");
  if (!overlay || !modal || !select) return;

  function fillOptions(options){
    select.innerHTML = "";
    (options || []).forEach(function(item){
      var op = document.createElement("option");
      op.value = item.months;
      op.textContent = item.label;
      op.setAttribute("data-price", item.priceFormatted || item.price || "");
      select.appendChild(op);
    });
  }

  function openModal(){
    select.innerHTML = "";
    select.disabled = true;
    submitBtn.disabled = true;
    overlay.style.display = "block";
    modal.style.display = "block";
    fetch(renewOptionsUrl + "?sid=" + encodeURIComponent(sid), {credentials:"same-origin"})
      .then(function(r){
        if (!r.ok) throw new Error("load failed");
        return r.json();
      })
      .then(function(data){
        var options = data && data.ok && Array.isArray(data.options) ? data.options : [];
        if (!options.length) throw new Error("empty options");
        fillOptions(options);
        select.disabled = false;
        submitBtn.disabled = false;
      })
      .catch(function(){});
  }
  function closeModal(){
    overlay.style.display = "none";
    modal.style.display = "none";
  }

  document.addEventListener("click", function(e){
    var link = e.target.closest("a[href*=\"/modules/addons/vpsrenew/renewal.php\"],a[href*=\"renewal.php?sid=\"]");
    if (!link) return;
    var href = link.getAttribute("href") || "";
    if (href.indexOf("sid=" + sid) === -1 || href.indexOf("months=") !== -1) return;
    e.preventDefault();
    openModal();
  }, true);

  overlay.addEventListener("click", closeModal);
  closeBtn.addEventListener("click", closeModal);
  cancelBtn.addEventListener("click", closeModal);
  submitBtn.addEventListener("click", function(){
    var m = parseInt(select.value, 10) || 1;
    window.location.href = renewBaseUrl + "&months=" + m;
  });
})();
</script>';
	} catch (Exception $e) {
		logActivity('VPS Renewal Modal Hook Error: ' . $e->getMessage());
		return '';
	}
});

/**
 * 自动续费执行（每日任务）
 */
add_hook('DailyCronJob', 1, function ($vars) {
	try {
		// 默认切换到独立 cron 方式，避免与 WHMCS 日常任务同小时执行。
		// 如需保留旧行为，可在 configuration.php 定义：
		// define('VPSRENEW_ENABLE_DAILY_AUTORENEW', true);
		if (!defined('VPSRENEW_ENABLE_DAILY_AUTORENEW') || VPSRENEW_ENABLE_DAILY_AUTORENEW !== true) {
			return;
		}
		vpsrenew_run_autorenew_cycle(['source' => 'daily_hook']);
	} catch (Exception $e) {
		logActivity('VPS 自动续费 DailyCronJob 错误: ' . $e->getMessage());
	}
});

/**
 * 同一发票多个服务器服务项自动拆分
 */
add_hook('InvoiceCreation', 5, function ($vars) {
	$invoiceId = isset($vars['invoiceid']) ? (int) $vars['invoiceid'] : 0;
	if ($invoiceId <= 0) {
		return;
	}

	try {
		$config = vpsrenew_get_module_config();
		if (!$config) {
			return;
		}

		$enabled = ($config['split_multi_service_invoice'] === 'on' || $config['split_multi_service_invoice'] === 'yes');
		if (!$enabled) {
			return;
		}

		$newIds = vpsrenew_split_multi_service_invoice($invoiceId);
		if (!empty($newIds)) {
			logActivity('VPS 账单拆分完成：原账单 #' . $invoiceId . ' 拆分为 #' . implode(', #', $newIds));
		}
	} catch (Exception $e) {
		logActivity('VPS 账单拆分失败（invoice #' . $invoiceId . '）: ' . $e->getMessage());
	}
});

/**
 * 续费账单支付后顺延服务到期时间
 */
add_hook('InvoicePaid', 1, function ($vars) {
	$invoiceId = isset($vars['invoiceid']) ? (int) $vars['invoiceid'] : 0;
	if ($invoiceId <= 0) {
		return;
	}

	try {
		$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
		if (!$invoice) {
			return;
		}

		$notes = (string) ($invoice->notes ?? '');
		if (strpos($notes, 'VPSRENEW:sid=') === false) {
			return;
		}

		if (!preg_match('/VPSRENEW:sid=(\d+);months=(\d+);auto=([01])/', $notes, $m)) {
			return;
		}

		$serviceId = (int) $m[1];
		$months = (int) $m[2];
		$auto = (int) $m[3];
		if ($serviceId <= 0 || $months <= 0) {
			return;
		}

		// 自动续费场景可能已在独立 cron 内完成顺延，这里避免重复顺延。
		if ($auto === 1 && vpsrenew_invoice_has_due_extended_marker($invoiceId)) {
			return;
		}

		$newDate = vpsrenew_extend_service_next_due_date($serviceId, $months);
		if ($newDate) {
			vpsrenew_mark_invoice_due_extended($invoiceId, $serviceId, $months, 'invoice_paid_hook');
			logActivity('VPS 续费账单支付后已顺延服务 #' . $serviceId . ' 到期时间至 ' . $newDate . '（账单 #' . $invoiceId . '）');
		}
	} catch (Exception $e) {
		logActivity('VPS 续费 InvoicePaid Hook 错误: ' . $e->getMessage());
	}
});

/**
 * 账单列表/账单详情增强 + 顶部IP搜索（客户端）
 */
add_hook('ClientAreaFooterOutput', 20, function ($vars) {
	if (!isset($_SESSION['uid']) || !$_SESSION['uid']) {
		return '';
	}

	$clientId = (int) $_SESSION['uid'];
	$filename = $vars['filename'] ?? '';
	$action = $vars['action'] ?? '';
	$baseUrl = rtrim(\WHMCS\Utility\Environment\WebHelper::getBaseUrl(), '/');
	$payload = [
		'invoiceMap' => [],
		'invoiceDetail' => null,
		'serviceMap' => [],
		'ipSearchUrl' => $baseUrl . '/modules/addons/vpsrenew/ipsearch.php',
		'renewOptionsUrl' => $baseUrl . '/modules/addons/vpsrenew/renew_options.php',
		'baseUrl' => $baseUrl,
		'managerUrl' => vpsrenew_build_manager_url($baseUrl),
	];

	try {
		if ($filename === 'clientarea' && $action === 'invoices' && !empty($vars['invoices']) && is_array($vars['invoices'])) {
			$invoiceIds = [];
			foreach ($vars['invoices'] as $invoice) {
				if (isset($invoice['id'])) {
					$invoiceIds[] = (int) $invoice['id'];
				}
			}

			if (!empty($invoiceIds)) {
				$serviceMap = vpsrenew_get_invoice_service_map($clientId, $invoiceIds);
				$invoiceRows = Capsule::table('tblinvoices')
					->where('userid', $clientId)
					->whereIn('id', $invoiceIds)
					->select(['id', 'status', 'duedate'])
					->get();

				foreach ($invoiceRows as $row) {
					$id = (int) $row->id;
					$services = [];
					if (!empty($serviceMap[$id]['services']) && is_array($serviceMap[$id]['services'])) {
						$services = array_values($serviceMap[$id]['services']);
					} elseif (!empty($serviceMap[$id]['serviceid'])) {
						$services = [$serviceMap[$id]];
					}
					$services = array_map(function ($service) use ($baseUrl) {
						if (!is_array($service)) {
							return $service;
						}
						$service['domain'] = (string) ($service['domain'] ?? '');
						$service['product_name'] = (string) ($service['product_name'] ?? '');
						$service['packageid'] = (int) ($service['packageid'] ?? 0);
						$service['orderUrl'] = $service['packageid'] > 0
							? ($baseUrl . '/cart.php?a=add&pid=' . (int) $service['packageid'])
							: '';
						return $service;
					}, $services);
					$payload['invoiceMap'][$id] = [
						'id' => $id,
						'status' => (string) $row->status,
						'duedate' => (string) $row->duedate,
						'service' => $services[0] ?? null,
						'services' => $services,
					];
				}
			}
		}

		if (($filename === 'viewinvoice' || ($filename === 'clientarea' && $action === 'viewinvoice')) && !empty($vars['invoiceid'])) {
			$invoiceId = (int) $vars['invoiceid'];
			$serviceMap = vpsrenew_get_invoice_service_map($clientId, [$invoiceId]);

			$invoice = Capsule::table('tblinvoices')
				->where('id', $invoiceId)
				->where('userid', $clientId)
				->select(['id', 'status', 'duedate'])
				->first();

			if ($invoice) {
				$services = [];
				if (!empty($serviceMap[$invoiceId]['services']) && is_array($serviceMap[$invoiceId]['services'])) {
					$services = array_values($serviceMap[$invoiceId]['services']);
				} elseif (!empty($serviceMap[$invoiceId]['serviceid'])) {
					$services = [$serviceMap[$invoiceId]];
				}
				$payload['invoiceDetail'] = [
					'id' => (int) $invoice->id,
					'status' => (string) $invoice->status,
					'duedate' => (string) $invoice->duedate,
					'service' => $services[0] ?? null,
					'services' => $services,
				];
			}
		}

			$templateFile = $vars['templatefile'] ?? '';
			if (
				($filename === 'clientarea' && ($action === 'services' || $action === 'products' || $action === 'productdetails'))
				|| $templateFile === 'clientareaproducts'
				|| $templateFile === 'clientareaproductdetails'
		) {
			$serviceIds = [];
			if ($filename === 'clientarea' && $action === 'productdetails' && !empty($_GET['id'])) {
				$serviceIds[] = (int) $_GET['id'];
			}
				if (!empty($vars['services']) && is_array($vars['services'])) {
					foreach ($vars['services'] as $service) {
						if (isset($service['id'])) {
							$serviceIds[] = (int) $service['id'];
						}
					}
				}
					if (empty($serviceIds) && ($templateFile === 'clientareaproducts' || ($filename === 'clientarea' && ($action === 'services' || $action === 'products')))) {
						$serviceIds = Capsule::table('tblhosting')
							->where('userid', $clientId)
							->orderBy('id', 'desc')
							->pluck('id')
							->map(function ($id) {
								return (int) $id;
							})
							->all();
				}
				$serviceIds = array_values(array_unique(array_filter($serviceIds)));

			if (!empty($serviceIds)) {
				$config = vpsrenew_get_module_config();
				$maxMonths = $config ? (int) $config['max_months'] : 36;
				$maxMonths = max(1, min(36, $maxMonths));
				$currencyId = (int) Capsule::table('tblclients')->where('id', $clientId)->value('currency');
				$currency = vpsrenew_currency_parts($currencyId);

				$rows = Capsule::table(VPSRENEW_AUTORENEW_TABLE)
					->where('userid', $clientId)
					->whereIn('serviceid', $serviceIds)
					->select(['serviceid', 'enabled', 'months', 'renew_mode'])
					->get()
					->keyBy('serviceid');

				$hostingRows = Capsule::table('tblhosting')
					->where('userid', $clientId)
					->whereIn('id', $serviceIds)
					->select(['id', 'packageid', 'nextduedate', 'billingcycle', 'amount'])
					->get()
					->keyBy('id');

				foreach ($serviceIds as $sid) {
					$row = $rows[$sid] ?? null;
					$enabled = $row ? ((int) $row->enabled === 1) : false;
					$months = $row ? (int) $row->months : 1;
					$months = max(1, min($maxMonths, $months));
					$renewMode = $row ? strtolower((string) ($row->renew_mode ?? 'cycle')) : 'cycle';
					if (!in_array($renewMode, ['month', 'cycle', 'fixed'], true)) {
						$renewMode = 'cycle';
					}
					$options = [];

					$hosting = $hostingRows[$sid] ?? null;
						if ($hosting) {
							$monthlyPrice = vpsrenew_resolve_service_monthly_price_breakdown($hosting, $currencyId, $maxMonths);
						if (($monthlyPrice['total_monthly_price'] ?? 0) > 0 && $config) {
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
					}

					$payload['serviceMap'][$sid] = [
						'serviceid' => (int) $sid,
						'enabled' => $enabled,
						'months' => $months,
						'renewMode' => $renewMode,
						'nextduedate' => $hosting ? (string) ($hosting->nextduedate ?? '') : '',
						'options' => $options,
						'renewUrl' => $baseUrl . '/modules/addons/vpsrenew/renewal.php?sid=' . (int) $sid,
						'toggleUrl' => vpsrenew_build_manager_url($baseUrl) . '?toggle=1&sid=' . (int) $sid . '&enabled=' . ($enabled ? 0 : 1) . '&months=' . $months . '&renew_mode=' . $renewMode . '&return=' . (($filename === 'clientarea' && $action === 'productdetails') ? 'productdetails' : 'services'),
						'detailsUrl' => $baseUrl . '/clientarea.php?action=productdetails&id=' . (int) $sid,
					];
				}
			}
		}
	} catch (Exception $e) {
		logActivity('VPS 账单增强数据构建错误: ' . $e->getMessage());
	}

	$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if (!$json) {
		return '';
	}

	return '
<style>
.vps-renew-meta{display:flex;flex-direction:column;align-items:flex-start;gap:4px;font-size:12px;color:#64748b}
.vps-renew-meta .host-link{font-size:14px;font-weight:600;line-height:1.4;color:#2563eb}
.vps-renew-meta .host-link:hover{color:#1d4ed8}
.vps-renew-meta .product-link{font-size:12px;line-height:1.45;color:#6b7280}
.vps-renew-meta .product-link:hover{color:#4b5563}
.vps-renew-meta .product-link,
.vps-renew-meta .host-link{text-decoration:none}
.vps-invoice-no{white-space:nowrap}
.vps-due-inline{display:inline-flex;align-items:center;margin-left:6px;vertical-align:middle}
.vps-remain-tag{display:inline-flex;align-items:center;height:22px;padding:0 8px;border-radius:999px;font-size:12px;line-height:1;white-space:nowrap}
.vps-remain-tag.tag-remain{background:#fff1f2;color:#be123c}
.vps-remain-tag.tag-error{background:#fef2f2;color:#b91c1c}
.vps-renew-pay-btn{display:inline-flex;align-items:center;justify-content:center;height:28px;padding:0 12px;border-radius:6px;background:#2563eb;color:#fff;text-decoration:none;font-size:12px;line-height:1;white-space:nowrap}
.vps-renew-pay-btn:hover{color:#fff;opacity:.92}
.vps-status-inline{display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
.vps-status-inline .vps-status-label{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.vps-renew-detail-box{margin:14px 0;padding:12px;border:1px solid #dbeafe;background:#eff6ff;border-radius:8px;font-size:14px}
.vps-renew-detail-box .line{margin:4px 0}
.vps-service-actions{margin-right:8px;display:inline-flex;gap:6px;flex-wrap:nowrap;white-space:nowrap;vertical-align:middle}
.vps-service-actions .btn-mini{display:inline-flex;align-items:center;justify-content:center;height:28px;padding:0 10px;border-radius:6px;font-size:12px;text-decoration:none;border:0;cursor:pointer;line-height:1;vertical-align:middle}
.vps-service-actions .btn-renew{background:#2563eb;color:#fff}
.vps-service-actions .btn-mini + .btn-mini{margin-left:4px}
.vps-service-actions .btn-auto-toggle{height:28px;padding:0 8px 0 6px;border-radius:999px;background:#f3f4f6;color:#111827;border:1px solid #d1d5db;display:inline-flex;align-items:center;gap:8px;transition:all .16s ease}
.vps-service-actions .btn-auto-toggle .sw-track{width:34px;height:20px;border-radius:999px;background:#d1d5db;position:relative;transition:all .16s ease}
.vps-service-actions .btn-auto-toggle .sw-dot{position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .16s ease}
.vps-service-actions .btn-auto-toggle .sw-text{font-size:12px;line-height:1;white-space:nowrap}
.vps-service-actions .btn-auto-toggle.btn-auto-on{background:#ecfdf5;border-color:#86efac;color:#166534}
.vps-service-actions .btn-auto-toggle.btn-auto-on .sw-track{background:#22c55e}
.vps-service-actions .btn-auto-toggle.btn-auto-on .sw-dot{left:16px}
.vps-service-actions .btn-auto-toggle.btn-auto-off{background:#f9fafb;border-color:#d1d5db;color:#374151}
.vps-service-actions .btn-auto-toggle.is-loading{opacity:.72;pointer-events:none}
.vps-product-detail-actions{margin-top:16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.vps-product-detail-actions .btn-mini{display:inline-flex;align-items:center;justify-content:center;height:34px;padding:0 14px;border-radius:8px;font-size:13px;text-decoration:none;border:0;cursor:pointer;line-height:1}
.vps-product-detail-actions .btn-renew{background:#2563eb;color:#fff}
.vps-product-detail-actions .btn-renew:hover{color:#fff;opacity:.92}
.vps-product-detail-actions .btn-manager{background:#eef2ff;color:#1d4ed8;border:1px solid #bfdbfe}
.vps-product-detail-actions .btn-manager:hover{color:#1d4ed8;background:#e0e7ff}
.vps-product-detail-actions .btn-auto-toggle{height:34px;padding:0 12px 0 8px;border-radius:999px;background:#f3f4f6;color:#111827;border:1px solid #d1d5db;display:inline-flex;align-items:center;gap:8px;transition:all .16s ease}
.vps-product-detail-actions .btn-auto-toggle .sw-track{width:38px;height:22px;border-radius:999px;background:#d1d5db;position:relative;transition:all .16s ease}
.vps-product-detail-actions .btn-auto-toggle .sw-dot{position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:left .16s ease}
.vps-product-detail-actions .btn-auto-toggle .sw-text{font-size:13px;line-height:1;white-space:nowrap}
.vps-product-detail-actions .btn-auto-toggle.btn-auto-on{background:#ecfdf5;border-color:#86efac;color:#166534}
.vps-product-detail-actions .btn-auto-toggle.btn-auto-on .sw-track{background:#22c55e}
.vps-product-detail-actions .btn-auto-toggle.btn-auto-on .sw-dot{left:18px}
.vps-product-detail-actions .btn-auto-toggle.btn-auto-off{background:#f9fafb;border-color:#d1d5db;color:#374151}
.vps-product-detail-actions .btn-auto-toggle.is-loading{opacity:.72;pointer-events:none}
.table#tableServicesList td.cell-action{text-align:left}
.table#tableServicesList td.cell-action .dropdown{display:inline-block;vertical-align:middle}
.table#tableServicesList td.cell-action .btn-icon{margin-right:4px}
.table#tableServicesList tbody tr td:last-child{text-align:left}
.table#tableServicesList tbody tr td:last-child .dropdown{display:inline-block;vertical-align:middle}
.vps-renew-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:11000;display:none}
.vps-renew-modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(520px,92vw);background:#fff;border-radius:10px;z-index:11001;display:none;box-shadow:0 12px 30px rgba(0,0,0,.2)}
.vps-renew-modal .hd{padding:16px 20px;border-bottom:1px solid #eee;font-size:20px;font-weight:600;display:flex;justify-content:space-between;align-items:center}
.vps-renew-modal .bd{padding:16px 20px}
.vps-renew-modal .ft{padding:12px 20px;border-top:1px solid #eee;display:flex;justify-content:flex-end;gap:10px}
.vps-renew-modal .close{cursor:pointer;font-size:24px;line-height:1;color:#999}
.vps-renew-modal select{width:100%;height:40px;border:1px solid #d0d7de;border-radius:6px;padding:0 10px}
.vps-renew-modal .price{margin-top:10px;color:#333}
.vps-top-ip-search{position:fixed;right:5px!important;left:auto!important;top:175px;transform:translateY(-50%);z-index:9998;display:flex;justify-content:flex-end;align-items:center;gap:8px;width:auto;max-width:calc(100vw - 32px)}
.vps-top-ip-search .tg{width:38px;height:38px;padding:0;border:1px solid #d1d5db;border-radius:6px!important;background:#fff;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.05);font-size:16px;color:#111827;display:inline-flex;align-items:center;justify-content:center;transition:all .15s ease}
.vps-top-ip-search .tg:hover{border-color:#93c5fd;box-shadow:0 4px 14px rgba(37,99,235,.15);color:#2563eb}
.vps-top-ip-search .tg svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.vps-top-ip-search .tg .icon-close{display:none}
.vps-top-ip-search:not(.is-collapsed) .tg .icon-search{display:none}
.vps-top-ip-search:not(.is-collapsed) .tg .icon-close{display:block}
.vps-top-ip-search .tg,
.vps-top-ip-search .ipt,
.vps-top-ip-search .res{border-radius:10px}
.vps-top-ip-search .panel{position:relative;display:flex;flex-direction:column;justify-content:flex-start;width:320px;max-width:calc(100vw - 32px);margin:0}
.vps-top-ip-search.vps-top-ip-search-page-right{position:fixed!important;right:5px!important;left:auto!important;top:175px;transform:none;z-index:10000;max-width:min(366px,calc(100vw - 32px))}
.vps-top-ip-search.vps-top-ip-search-page-right .panel{width:320px;max-width:min(320px,calc(100vw - 32px))}
.vps-top-ip-search.vps-top-ip-search-docked{position:fixed;right:5px!important;left:auto!important;top:40%;transform:translateY(-50%);z-index:10000;max-width:min(366px,calc(100vw - 32px))}
.vps-top-ip-search.vps-top-ip-search-docked .panel{width:320px;max-width:min(320px,calc(100vw - 90px))}
.vps-top-ip-search.is-collapsed .panel{display:none}
.vps-top-ip-search .ipt{display:block;box-sizing:border-box;width:100%;height:38px;line-height:38px;margin:0;border:1px solid #d1d5db;border-radius:10px;padding:0 12px;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.05);transition:border-color .15s ease,box-shadow .15s ease}
.vps-top-ip-search .ipt:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.16),0 2px 10px rgba(0,0,0,.05)}
.vps-top-ip-search .res{display:none;position:absolute;left:0;right:0;top:calc(100% + 6px);margin-top:0;background:#fff;border:1px solid #e5e7eb;border-radius:8px;max-height:340px;overflow:auto;box-shadow:0 10px 22px rgba(0,0,0,.1);z-index:20}
.vps-top-ip-search .item{display:block;padding:10px 12px;color:#111827;text-decoration:none;border-bottom:1px solid #f1f5f9}
.vps-top-ip-search .item:last-child{border-bottom:0}
.vps-top-ip-search .item .t{font-size:13px;font-weight:600}
.vps-top-ip-search .item .s{font-size:12px;color:#64748b;margin-top:3px}
.vps-home-ip-res{display:none;position:absolute;left:0;right:0;top:calc(100% + 6px);background:#fff;border:1px solid #e5e7eb;border-radius:8px;max-height:320px;overflow:auto;box-shadow:0 10px 22px rgba(0,0,0,.1);z-index:25}
.vps-home-ip-res .item{display:block;padding:10px 12px;color:#111827;text-decoration:none;border-bottom:1px solid #f1f5f9}
.vps-home-ip-res .item:last-child{border-bottom:0}
.vps-home-ip-res .item .t{font-size:13px;font-weight:600}
.vps-home-ip-res .item .s{font-size:12px;color:#64748b;margin-top:3px}
.vps-float-toast{position:fixed;right:16px;bottom:16px;z-index:12000;padding:10px 12px;border-radius:8px;background:#0f172a;color:#fff;font-size:13px;opacity:0;transform:translateY(8px);pointer-events:none;transition:all .2s ease}
.vps-float-toast.show{opacity:1;transform:translateY(0)}
.vps-float-toast.err{background:#b91c1c}
.vps-ip-search-dock-trigger{display:flex;justify-content:center;align-items:center;flex:1;width:100%;min-height:50px;padding:0;border:0;border-top:1px solid #ebedeb;background:transparent;color:#111827;cursor:pointer}
.vps-ip-search-dock-trigger svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
body.vps-ip-search-open .vps-ip-search-dock-trigger{color:#2563eb}
@media (max-width: 900px){.vps-top-ip-search{top:64px;right:10px;left:10px;transform:none}.vps-top-ip-search .panel{width:100%}}
</style>
<script>
(function(){
  var payload = ' . $json . ';
  if (!payload) return;

  var toastNode = null;
  var toastTimer = null;
  function showToast(msg, isErr){
    if (!toastNode) {
      toastNode = document.createElement("div");
      toastNode.className = "vps-float-toast";
      document.body.appendChild(toastNode);
    }
    toastNode.textContent = msg || "";
    toastNode.className = "vps-float-toast" + (isErr ? " err" : "") + " show";
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function(){
      toastNode.className = "vps-float-toast" + (isErr ? " err" : "");
    }, 1500);
  }

  function parseInvoiceIdFromRow(row){
    if (!row) return 0;
    var dataUrl = row.getAttribute("data-url") || "";
    var m = dataUrl.match(/[?&]id=(\\d+)/);
    if (m) return parseInt(m[1], 10) || 0;
    var a = row.querySelector("a[href*=\\"viewinvoice.php?id=\\"]");
    if (a) {
      var mm = (a.getAttribute("href") || "").match(/[?&]id=(\\d+)/);
      if (mm) return parseInt(mm[1], 10) || 0;
    }
    var txt = (row.cells && row.cells[0] ? row.cells[0].textContent : "").trim();
    var num = txt.replace(/[^0-9]/g, "");
    return parseInt(num || "0", 10) || 0;
  }

  function daysUntil(dateStr){
    if (!dateStr || dateStr === "0000-00-00") return null;
    var target = new Date(dateStr + "T00:00:00");
    if (isNaN(target.getTime())) return null;
    var now = new Date();
    now.setHours(0,0,0,0);
    var diff = Math.ceil((target.getTime() - now.getTime()) / 86400000);
    return diff;
  }

  function injectInvoiceListEnhancements(){
    var table = document.getElementById("tableInvoicesList");
    if (!table || !payload.invoiceMap) return;
    var rows = table.querySelectorAll("tbody tr");
    rows.forEach(function(row){
      var invoiceId = parseInvoiceIdFromRow(row);
      if (!invoiceId) return;
      var meta = payload.invoiceMap[invoiceId];
      if (!meta) return;

      var firstCell = row.cells && row.cells[0] ? row.cells[0] : null;
      var secondCell = row.cells && row.cells[1] ? row.cells[1] : null;
      if (!firstCell) return;

      // 账单号链接改为新标签打开
      var invoiceLinks = row.querySelectorAll("a[href*=\"viewinvoice.php?id=\"]");
      invoiceLinks.forEach(function(a){
        a.setAttribute("target", "_blank");
        a.setAttribute("rel", "noopener noreferrer");
      });

      // 点击账单行（非按钮/输入控件）时新标签打开
      if (invoiceId && row.getAttribute("data-vps-open-bound") !== "1") {
        row.setAttribute("data-vps-open-bound", "1");
        row.addEventListener("click", function(e){
          if (e.target.closest("a,button,input,select,textarea,label")) return;
          var openUrl = payload.baseUrl + "/viewinvoice.php?id=" + invoiceId;
          window.open(openUrl, "_blank", "noopener");
          e.preventDefault();
          e.stopPropagation();
          if (typeof e.stopImmediatePropagation === "function") {
            e.stopImmediatePropagation();
          }
        }, true);
      }

      if (firstCell && !firstCell.querySelector(".vps-invoice-no")) {
        var rawInvoiceText = (firstCell.textContent || "").trim();
        firstCell.textContent = "";
        var invoiceNo = document.createElement("span");
        invoiceNo.className = "vps-invoice-no";
        invoiceNo.textContent = rawInvoiceText;
        firstCell.appendChild(invoiceNo);
      }

      if (secondCell && !secondCell.querySelector(".vps-renew-meta")) {
        var metaWrap = document.createElement("div");
        metaWrap.className = "vps-renew-meta";

        if (meta.service && meta.service.serviceid) {
          var hostLink = document.createElement("a");
          hostLink.href = payload.baseUrl + "/clientarea.php?action=productdetails&id=" + meta.service.serviceid;
          hostLink.className = "host-link";
          hostLink.textContent = meta.service.domain || ("服务 #" + meta.service.serviceid);
          hostLink.addEventListener("click", function(e){ e.stopPropagation(); });
          metaWrap.appendChild(hostLink);

          var productLink = document.createElement("a");
          productLink.href = meta.service.orderUrl || "#";
          productLink.className = "product-link";
          productLink.textContent = meta.service.product_name || meta.service.title || ("产品 #" + meta.service.serviceid);
          productLink.target = "_blank";
          productLink.rel = "noopener noreferrer";
          productLink.addEventListener("click", function(e){
            e.stopPropagation();
            if (!meta.service.orderUrl) {
              e.preventDefault();
            }
          });
          metaWrap.appendChild(productLink);
        }

        secondCell.appendChild(metaWrap);
      }

      var dueCell = row.cells && row.cells.length > 3 ? row.cells[3] : null;
      if (dueCell && !dueCell.querySelector(".vps-due-inline")) {
        var d = daysUntil(meta.service ? meta.service.nextduedate : meta.duedate);
        if (d !== null && d <= 15 && meta.status === "Unpaid") {
          var dueMeta = document.createElement("span");
          dueMeta.className = "vps-due-inline";
          var remain = document.createElement("span");
          remain.className = "vps-remain-tag " + (d > 0 ? "tag-remain" : "tag-error");
          remain.textContent = d > 0 ? ("剩余 " + d + " 天") : "已过期";
          dueMeta.appendChild(remain);
          dueCell.appendChild(dueMeta);
        }
      }

      if (meta.status === "Unpaid" && !firstCell.querySelector(".vps-renew-pay-btn")) {
        var statusCell = (row.cells && row.cells.length > 0) ? row.cells[row.cells.length - 1] : null;
        var payBtn = document.createElement("a");
        payBtn.className = "vps-renew-pay-btn";
        payBtn.href = payload.baseUrl + "/viewinvoice.php?id=" + invoiceId;
        payBtn.target = "_blank";
        payBtn.rel = "noopener noreferrer";
        payBtn.textContent = "立即支付";
        payBtn.addEventListener("click", function(e){ e.stopPropagation(); });

        if (statusCell) {
          var inline = statusCell.querySelector(".vps-status-inline");
          if (!inline) {
            inline = document.createElement("span");
            inline.className = "vps-status-inline";
            var label = document.createElement("span");
            label.className = "vps-status-label";
            while (statusCell.firstChild) {
              label.appendChild(statusCell.firstChild);
            }
            inline.appendChild(label);
            statusCell.appendChild(inline);
          }
          if (!inline.querySelector(".vps-renew-pay-btn")) {
            inline.appendChild(payBtn);
          }
        } else {
          firstCell.appendChild(payBtn);
        }
      }
    });
  }

  function injectInvoiceDetailEnhancements(){
    var detail = payload.invoiceDetail;
    if (!detail) return;
    var services = Array.isArray(detail.services) && detail.services.length
      ? detail.services
      : (detail.service && detail.service.serviceid ? [detail.service] : []);
    if (!services.length) return;

    var box = document.createElement("div");
    box.className = "vps-renew-detail-box";

    var html = "";
    html += "<div class=\\"line\\"><strong>关联服务：</strong></div>";
    services.forEach(function(service, idx){
      if (!service || !service.serviceid) return;
      var remainDays = daysUntil(service.nextduedate || detail.duedate);
      var remainText = "";
      if (remainDays !== null) {
        remainText = remainDays >= 0 ? ("服务剩余到期时间：" + remainDays + " 天") : ("服务已逾期：" + Math.abs(remainDays) + " 天");
      }
      html += "<div class=\\"line\\"><a href=\\"" + payload.baseUrl + "/clientarea.php?action=productdetails&id=" + service.serviceid + "\\">" + (services.length > 1 ? ("#" + (idx + 1) + " ") : "") + (service.title || ("服务 #" + service.serviceid)) + "</a></div>";
      if (service.ips && service.ips.length) {
        html += "<div class=\\"line\\"><strong>IP：</strong>" + service.ips.join(", ") + "</div>";
      }
      if (remainText) {
        html += "<div class=\\"line\\">" + remainText + "</div>";
      }
    });
    box.innerHTML = html;

    var holder = document.querySelector(".invoice .section, .invoice-container .panel.panel-default, .invoice-container .row.invoice-header");
    if (holder && holder.parentNode) {
      holder.parentNode.insertBefore(box, holder.nextSibling);
    }

    var invoiceLineCells = document.querySelectorAll(
      ".invoice .table td, .invoice-container .table td, .invoice table td"
    );
    invoiceLineCells.forEach(function(el){
      if (!el) return;
      var txt = el.textContent || "";
      if (txt.indexOf("VPSRENEW:sid=") === -1 && txt.indexOf("[VPSRENEW:sid=") === -1) return;
      // 仅清理发票条目中的内部标记，避免破坏页面其它结构
      var clean = txt.replace(/\\s*\\[?VPSRENEW:sid=\\d+;months=\\d+;auto=[01]\\]?/g, "");
      if (clean !== txt) {
        el.textContent = clean;
      }
    });

    var marker = "VPSRENEW_SPLIT_PARENT:children=";
    var markerRegex = /VPSRENEW_SPLIT_PARENT:children=([0-9,\s]+)/g;
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
    var textNodes = [];
    var node;
    while ((node = walker.nextNode())) {
      if (node.nodeValue && node.nodeValue.indexOf(marker) !== -1) {
        textNodes.push(node);
      }
    }
    textNodes.forEach(function(textNode){
      var text = textNode.nodeValue || "";
      if (text.indexOf(marker) === -1) return;
      var parent = textNode.parentNode;
      if (!parent) return;

      var frag = document.createDocumentFragment();
      var last = 0;
      var matched = false;
      text.replace(markerRegex, function(full, idsRaw, offset){
        matched = true;
        if (offset > last) {
          frag.appendChild(document.createTextNode(text.slice(last, offset)));
        }
        var ids = (idsRaw || "").split(",").map(function(v){
          return parseInt((v || "").trim(), 10) || 0;
        }).filter(function(v){ return v > 0; });
        if (!ids.length) {
          frag.appendChild(document.createTextNode(full));
        } else {
          ids.forEach(function(id, idx){
            if (idx > 0) {
              frag.appendChild(document.createTextNode("  "));
            }
            var link = document.createElement("a");
            link.href = payload.baseUrl + "/viewinvoice.php?id=" + id;
            link.textContent = "账单" + id;
            frag.appendChild(link);
          });
        }
        last = offset + full.length;
        return full;
      });
      if (!matched) return;
      if (last < text.length) {
        frag.appendChild(document.createTextNode(text.slice(last)));
      }
      parent.replaceChild(frag, textNode);
    });
  }

  function injectInvoiceSidebarManagerLink(){
    if (!payload.managerUrl) return;
    if (!document.body.classList.contains("page-clientareainvoices") && !document.body.classList.contains("page-viewinvoice")) {
      return;
    }
    if (document.querySelector(".vps-auto-renew-sidebar-link")) return;

    var sidebar = document.querySelector(".sidebar-secondary");
    if (!sidebar) return;

    var existingList = sidebar.querySelector(".panel-sidebar .list-group");
    if (existingList) {
      var link = document.createElement("a");
      link.href = payload.managerUrl;
      link.className = "list-group-item vps-auto-renew-sidebar-link";
      link.innerHTML = "<i class=\"ls ls-refresh\"></i>&nbsp; 自动续费管理";
      existingList.appendChild(link);
      return;
    }

    var panel = document.createElement("div");
    panel.className = "panel panel-sidebar panel-default";
    panel.innerHTML = ""
      + "<div class=\"panel-heading\"><h3 class=\"panel-title\">自动续费</h3></div>"
      + "<div class=\"list-group\">"
      + "<a href=\"" + payload.managerUrl + "\" class=\"list-group-item vps-auto-renew-sidebar-link\"><i class=\"ls ls-refresh\"></i>&nbsp; 自动续费管理</a>"
      + "</div>";
    sidebar.appendChild(panel);
  }

  function injectServicesSidebarStrategyLink(){
    if (!payload.managerUrl) return;
    if (!document.body.classList.contains("page-clientareaproducts")) {
      return;
    }
    var existingSameLink = Array.prototype.slice.call(document.querySelectorAll(".sidebar-secondary a[href]")).some(function(a){
      var href = a.getAttribute("href") || "";
      return href.indexOf("/addons/auto-renewal/") !== -1;
    });
    if (existingSameLink || document.querySelector(".vps-auto-renew-strategy-link")) return;

    var sidebar = document.querySelector(".sidebar-secondary");
    if (!sidebar) return;

    var existingList = sidebar.querySelector(".panel-sidebar .list-group");
    if (existingList) {
      var link = document.createElement("a");
      link.href = payload.managerUrl;
      link.className = "list-group-item vps-auto-renew-strategy-link";
      link.innerHTML = "<i class=\"ls ls-refresh\"></i>&nbsp; 自动续费管理";
      existingList.appendChild(link);
      return;
    }

    var panel = document.createElement("div");
    panel.className = "panel panel-sidebar panel-default";
    panel.innerHTML = ""
      + "<div class=\"panel-heading\"><h3 class=\"panel-title\">操作</h3></div>"
      + "<div class=\"list-group\">"
      + "<a href=\"" + payload.managerUrl + "\" class=\"list-group-item vps-auto-renew-strategy-link\"><i class=\"ls ls-refresh\"></i>&nbsp; 自动续费管理</a>"
      + "</div>";
    sidebar.appendChild(panel);
  }

  function parseServiceIdFromRow(row){
    if (!row) return 0;
    var dataUrl = (row.getAttribute("data-url") || "").replace(/&amp;/g, "&");
    var m = dataUrl.match(/[?&]id=(\\d+)/);
    if (m) return parseInt(m[1], 10) || 0;
    var a = row.querySelector("a[href*=\\"action=productdetails\\"]");
    if (!a) return 0;
    var href = (a.getAttribute("href") || "").replace(/&amp;/g, "&");
    var mm = href.match(/[?&]id=(\\d+)/);
    return mm ? (parseInt(mm[1], 10) || 0) : 0;
  }

  function buildFallbackServiceMeta(serviceId){
    serviceId = parseInt(serviceId || 0, 10) || 0;
    if (!serviceId) return null;
    return {
      serviceid: serviceId,
      enabled: false,
      months: 1,
      renewMode: "cycle",
      nextduedate: "",
      options: [],
      renewUrl: payload.baseUrl + "/modules/addons/vpsrenew/renewal.php?sid=" + serviceId,
      toggleUrl: payload.managerUrl + "?toggle=1&sid=" + serviceId + "&enabled=1&months=1&renew_mode=cycle&return=services",
      detailsUrl: payload.baseUrl + "/clientarea.php?action=productdetails&id=" + serviceId
    };
  }

  var renewModal = null;
  var renewModalOverlay = null;
  var renewModalSelect = null;
  var renewModalMeta = null;
  var renewModalSubmit = null;

  function ensureRenewModal(){
    if (renewModal) return;
    renewModalOverlay = document.createElement("div");
    renewModalOverlay.className = "vps-renew-modal-overlay";
    renewModal = document.createElement("div");
    renewModal.className = "vps-renew-modal";
    renewModal.innerHTML = ""
      + "<div class=\\"hd\\"><span>快速续费</span><span class=\\"close\\" id=\\"vpsRenewModalClose2\\">&times;</span></div>"
      + "<div class=\\"bd\\"><label for=\\"vpsRenewModalSelect2\\">请选择续费月份</label><select id=\\"vpsRenewModalSelect2\\"></select></div>"
      + "<div class=\\"ft\\"><button type=\\"button\\" class=\\"btn btn-default\\" id=\\"vpsRenewModalCancel2\\">取消</button><button type=\\"button\\" class=\\"btn btn-primary\\" id=\\"vpsRenewModalSubmit2\\">确认续费</button></div>";
    document.body.appendChild(renewModalOverlay);
    document.body.appendChild(renewModal);

    renewModalSelect = document.getElementById("vpsRenewModalSelect2");
    var closeBtn = document.getElementById("vpsRenewModalClose2");
    var cancelBtn = document.getElementById("vpsRenewModalCancel2");
    renewModalSubmit = document.getElementById("vpsRenewModalSubmit2");

    function close(){
      renewModalOverlay.style.display = "none";
      renewModal.style.display = "none";
      renewModalMeta = null;
    }

    renewModalOverlay.addEventListener("click", close);
    closeBtn.addEventListener("click", close);
    cancelBtn.addEventListener("click", close);
    renewModalSubmit.addEventListener("click", function(){
      if (!renewModalMeta) return;
      var m = parseInt(renewModalSelect.value || "1", 10) || 1;
      window.location.href = renewModalMeta.renewUrl + "&months=" + m;
    });
  }

  function fillRenewModalOptions(options, defaultMonths){
    if (!renewModalSelect) return false;
    renewModalSelect.innerHTML = "";
    (options || []).forEach(function(item){
      var op = document.createElement("option");
      op.value = item.months;
      op.textContent = item.label || (item.months + " 个月");
      op.setAttribute("data-price", item.priceFormatted || item.price || "");
      renewModalSelect.appendChild(op);
    });
    if (!renewModalSelect.options.length) return false;
    for (var i = 0; i < renewModalSelect.options.length; i++) {
      if (parseInt(renewModalSelect.options[i].value, 10) === defaultMonths) {
        renewModalSelect.selectedIndex = i;
        break;
      }
    }
    return true;
  }

  function openRenewModal(meta){
    if (!meta || !meta.renewUrl) return;
    ensureRenewModal();
    if (!renewModalSelect) return;
    renewModalMeta = meta;
    var defaultMonths = parseInt(meta.months || 1, 10) || 1;
    renewModalSelect.innerHTML = "";
    renewModalSelect.disabled = true;
    if (renewModalSubmit) renewModalSubmit.disabled = true;
    renewModalOverlay.style.display = "block";
    renewModal.style.display = "block";

    fetch(payload.renewOptionsUrl + "?sid=" + encodeURIComponent(meta.serviceid), {credentials:"same-origin"})
      .then(function(r){
        if (!r.ok) throw new Error("load failed");
        return r.json();
      })
      .then(function(data){
        var options = data && data.ok && Array.isArray(data.options) ? data.options : [];
        if (!fillRenewModalOptions(options, defaultMonths)) {
          throw new Error("empty options");
        }
        meta.options = options;
        if (renewModalSelect) renewModalSelect.disabled = false;
        if (renewModalSubmit) renewModalSubmit.disabled = false;
      })
      .catch(function(){
        var fallbackOptions = meta.options || [];
        if (fillRenewModalOptions(fallbackOptions, defaultMonths)) {
          if (renewModalSelect) renewModalSelect.disabled = false;
          if (renewModalSubmit) renewModalSubmit.disabled = false;
          return;
        }
      });
  }

  function ensureAutoRenewActionButton(wrap, row, meta){
    if (!wrap || !row || !meta) return;
    var isActive = !!meta._isRowActive;
    if (!isActive && !meta.enabled) {
      var existed = wrap.querySelector(".btn-auto-toggle");
      if (existed) existed.remove();
      return;
    }
    var btn = wrap.querySelector(".btn-auto-toggle");
    if (!btn) {
      btn = document.createElement("button");
      btn.type = "button";
      btn.className = "btn-mini btn-auto-toggle";
      btn.innerHTML = "<span class=\"sw-track\"><span class=\"sw-dot\"></span></span><span class=\"sw-text\"></span>";
      btn.addEventListener("click", function(e){
        e.preventDefault();
        e.stopPropagation();
        toggleAutoRenewInRow(row, meta, btn);
      });
      wrap.appendChild(btn);
    }
    btn.classList.remove("btn-auto-on", "btn-auto-off");
    var textEl = btn.querySelector(".sw-text");
    if (meta.enabled) {
      btn.classList.add("btn-auto-on");
      if (textEl) textEl.textContent = "自动续费开";
      btn.setAttribute("aria-label", "关闭自动续费");
    } else {
      btn.classList.add("btn-auto-off");
      if (textEl) textEl.textContent = "自动续费关";
      btn.setAttribute("aria-label", "开启自动续费");
    }
    btn.classList.remove("is-loading");
  }

  function ensureProductDetailActionButton(wrap, meta){
    if (!wrap || !meta) return;
    var btn = wrap.querySelector(".btn-auto-toggle");
    if (!btn) {
      btn = document.createElement("button");
      btn.type = "button";
      btn.className = "btn-mini btn-auto-toggle";
      btn.innerHTML = "<span class=\"sw-track\"><span class=\"sw-dot\"></span></span><span class=\"sw-text\"></span>";
      btn.addEventListener("click", function(e){
        e.preventDefault();
        e.stopPropagation();
        toggleAutoRenewInDetail(meta, btn);
      });
      wrap.appendChild(btn);
    }
    btn.classList.remove("btn-auto-on", "btn-auto-off");
    var textEl = btn.querySelector(".sw-text");
    if (meta.enabled) {
      btn.classList.add("btn-auto-on");
      if (textEl) textEl.textContent = "自动续费开";
      btn.setAttribute("aria-label", "关闭自动续费");
    } else {
      btn.classList.add("btn-auto-off");
      if (textEl) textEl.textContent = "自动续费关";
      btn.setAttribute("aria-label", "开启自动续费");
    }
    btn.classList.remove("is-loading");
  }

  function toggleAutoRenewInRow(row, meta, triggerEl){
    if (!row || !meta || !meta.toggleUrl || !triggerEl) return;
    if (triggerEl.getAttribute("data-loading") === "1") return;
    triggerEl.setAttribute("data-loading", "1");
    var oldText = triggerEl.textContent;
    var oldSwText = "";
    var swTextEl = triggerEl.querySelector ? triggerEl.querySelector(".sw-text") : null;
    if (swTextEl) {
      oldSwText = swTextEl.textContent || "";
      swTextEl.textContent = "处理中...";
      triggerEl.classList.add("is-loading");
    } else {
      triggerEl.textContent = "处理中...";
    }

    fetch(meta.toggleUrl + "&ajax=1", {credentials:"same-origin"})
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data || !data.ok) {
          throw new Error("toggle failed");
        }
        var nextEnabled = null;
        if (typeof data.enabled !== "undefined") {
          nextEnabled = parseInt(data.enabled, 10) === 1;
        }
        if (nextEnabled === null) {
          var m = (meta.toggleUrl || "").match(/[?&]enabled=(\d+)/);
          nextEnabled = m ? (parseInt(m[1], 10) === 1) : !meta.enabled;
        }
        meta.enabled = nextEnabled;

        var nextEnabledParam = meta.enabled ? "0" : "1";
        if ((meta.toggleUrl || "").indexOf("enabled=") !== -1) {
          meta.toggleUrl = meta.toggleUrl.replace(/([?&]enabled=)(0|1)/, "$1" + nextEnabledParam);
        } else {
          meta.toggleUrl += (meta.toggleUrl.indexOf("?") === -1 ? "?" : "&") + "enabled=" + nextEnabledParam;
        }

        var actionWrap = triggerEl.closest(".vps-service-actions");
        if (actionWrap) {
          ensureAutoRenewActionButton(actionWrap, row, meta);
        } else if (triggerEl.tagName === "A") {
          triggerEl.textContent = meta.enabled ? "关闭自动续费" : "开启自动续费";
        }
        showToast(meta.enabled ? "已开启自动续费" : "未开启自动续费", false);
        triggerEl.removeAttribute("data-loading");
      })
      .catch(function(){
        if (swTextEl) {
          swTextEl.textContent = oldSwText || "";
          triggerEl.classList.remove("is-loading");
        } else {
          triggerEl.textContent = oldText;
        }
        triggerEl.removeAttribute("data-loading");
        showToast("切换失败，请重试", true);
      });
  }

  function toggleAutoRenewInDetail(meta, triggerEl){
    if (!meta || !meta.toggleUrl || !triggerEl) return;
    if (triggerEl.getAttribute("data-loading") === "1") return;
    triggerEl.setAttribute("data-loading", "1");

    var oldSwText = "";
    var swTextEl = triggerEl.querySelector ? triggerEl.querySelector(".sw-text") : null;
    if (swTextEl) {
      oldSwText = swTextEl.textContent || "";
      swTextEl.textContent = "处理中...";
    }
    triggerEl.classList.add("is-loading");

    fetch(meta.toggleUrl + "&ajax=1", {credentials:"same-origin"})
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data || !data.ok) {
          throw new Error("toggle failed");
        }
        var nextEnabled = null;
        if (typeof data.enabled !== "undefined") {
          nextEnabled = parseInt(data.enabled, 10) === 1;
        }
        if (nextEnabled === null) {
          var m = (meta.toggleUrl || "").match(/[?&]enabled=(\d+)/);
          nextEnabled = m ? (parseInt(m[1], 10) === 1) : !meta.enabled;
        }
        meta.enabled = nextEnabled;

        var nextEnabledParam = meta.enabled ? "0" : "1";
        if ((meta.toggleUrl || "").indexOf("enabled=") !== -1) {
          meta.toggleUrl = meta.toggleUrl.replace(/([?&]enabled=)(0|1)/, "$1" + nextEnabledParam);
        } else {
          meta.toggleUrl += (meta.toggleUrl.indexOf("?") === -1 ? "?" : "&") + "enabled=" + nextEnabledParam;
        }

        ensureProductDetailActionButton(triggerEl.parentNode, meta);
        triggerEl.removeAttribute("data-loading");
        showToast(meta.enabled ? "已开启自动续费" : "未开启自动续费", false);
      })
      .catch(function(){
        if (swTextEl) {
          swTextEl.textContent = oldSwText || "";
        }
        triggerEl.classList.remove("is-loading");
        triggerEl.removeAttribute("data-loading");
        showToast("切换失败，请重试", true);
      });
  }

  function injectProductDetailsEnhancements(){
    var isProductDetailsPage = document.body.classList.contains("page-clientareaproductdetails")
      || /[?&]action=productdetails\b/.test(window.location.search || "");
    if (!isProductDetailsPage) return;
    var serviceId = parseInt(((window.location.search || "").match(/[?&]id=(\d+)/) || [])[1] || "0", 10) || 0;
    if (!serviceId || !payload.serviceMap || !payload.serviceMap[serviceId]) return;

    var meta = payload.serviceMap[serviceId];
    var mount = document.querySelector(".product-info");
    if (!mount) {
      mount = document.querySelector(".billingOverview .panel-body, .billingOverview, .product-details .col-md-6:last-child");
    }
    if (!mount) return;

    var wrap = mount.querySelector(".vps-product-detail-actions");
    if (!wrap) {
      wrap = document.createElement("div");
      wrap.className = "vps-product-detail-actions";
      mount.appendChild(wrap);
    }

    ensureProductDetailActionButton(wrap, meta);

    var renewBtn = wrap.querySelector(".btn-renew");
    if (!renewBtn) {
      renewBtn = document.createElement("a");
      renewBtn.href = meta.renewUrl || "#";
      renewBtn.className = "btn-mini btn-renew";
      renewBtn.textContent = "手动续费";
      renewBtn.addEventListener("click", function(e){
        e.preventDefault();
        e.stopPropagation();
        openRenewModal(meta);
      });
      wrap.appendChild(renewBtn);
    } else {
      renewBtn.href = meta.renewUrl || "#";
    }

    var managerBtn = wrap.querySelector(".btn-manager");
    if (!managerBtn) {
      managerBtn = document.createElement("a");
      managerBtn.className = "btn-mini btn-manager";
      managerBtn.textContent = "自动续费管理";
      managerBtn.href = payload.managerUrl || "#";
      wrap.appendChild(managerBtn);
    } else {
      managerBtn.href = payload.managerUrl || "#";
    }
  }

  function injectServicesListEnhancements(){
    if (!payload.serviceMap) {
      payload.serviceMap = {};
    }
    var table = document.getElementById("tableServicesList");
    if (!table) return;
    var rows = table.querySelectorAll("tbody tr");
    rows.forEach(function(row){
      var sid = parseServiceIdFromRow(row);
      if (!sid) return;
      var meta = payload.serviceMap[sid] ? payload.serviceMap[sid] : buildFallbackServiceMeta(sid);
      if (!meta) return;
      if (!payload.serviceMap[sid]) {
        payload.serviceMap[sid] = meta;
      }
      var statusEl = row.querySelector("span.status");
      var statusText = ((statusEl && statusEl.textContent) || "").trim().toLowerCase();
      meta._isRowActive = (
        statusText.indexOf("有效") !== -1
        || statusText.indexOf("active") !== -1
      );

      // 下次付款日期后显示“剩余X天/已过期”标签（仅 <=15 天显示）
      var dueCell = row.cells && row.cells.length > 2 ? row.cells[2] : null;
      if (dueCell && !dueCell.querySelector(".vps-due-inline")) {
        var d = daysUntil(meta.nextduedate);
        if (d !== null && d <= 15 && meta._isRowActive) {
          var dueMeta = document.createElement("span");
          dueMeta.className = "vps-due-inline";
          var remain = document.createElement("span");
          remain.className = "vps-remain-tag " + (d > 0 ? "tag-remain" : "tag-error");
          remain.textContent = d > 0 ? ("剩余 " + d + " 天") : "已过期";
          dueMeta.appendChild(remain);
          dueCell.appendChild(dueMeta);
        }
      }

      var actionCell = row.querySelector("td.cell-action");
      var mountCell = actionCell;
      if (!mountCell && row.cells && row.cells.length) {
        mountCell = row.cells[row.cells.length - 1];
      }

      if (mountCell && !mountCell.querySelector(".vps-service-actions")) {
        var wrap = document.createElement("div");
        wrap.className = "vps-service-actions";
        var renewBtn = document.createElement("a");
        renewBtn.className = "btn-mini btn-renew";
        renewBtn.textContent = "手动续费";
        renewBtn.href = meta.renewUrl || "#";
        renewBtn.addEventListener("click", function(e){
          e.preventDefault();
          e.stopPropagation();
          openRenewModal(meta);
        });
        ensureAutoRenewActionButton(wrap, row, meta);
        wrap.appendChild(renewBtn);
        var moreDropdown = mountCell.querySelector(".dropdown");
        if (moreDropdown) {
          mountCell.insertBefore(wrap, moreDropdown);
        } else {
          mountCell.appendChild(wrap);
        }
      } else if (mountCell) {
        ensureAutoRenewActionButton(mountCell.querySelector(".vps-service-actions"), row, meta);
      }

      // 下拉菜单里不再重复注入“续费/自动续费”，保留行内按钮即可
    });

    // 点击“产品/服务”列时使用新标签打开服务详情，不影响其它操作列
    rows.forEach(function(row){
      if (!row || row.getAttribute("data-vps-newtab-bound") === "1") return;
      row.setAttribute("data-vps-newtab-bound", "1");
      row.addEventListener("click", function(e){
        var firstCell = row.cells && row.cells.length ? row.cells[0] : null;
        if (!firstCell || !firstCell.contains(e.target)) return;
        if (e.target.closest("a,button,input,select,textarea,.btn-table-collapse,.dropdown,.vps-service-actions")) return;
        var url = row.getAttribute("data-url") || "";
        if (!url) return;
        e.preventDefault();
        e.stopPropagation();
        window.open(url, "_blank");
      }, true);
    });

    // 兜底：拦截“更多”下拉中可能存在的开启自动续费链接，避免跳转到自动续费管理页
    table.querySelectorAll("a[href*=\"/modules/addons/vpsrenew/autocreditlist.php?toggle=1\"][href*=\"return=services\"],a[href*=\"/addons/auto-renewal/?toggle=1\"][href*=\"return=services\"]")
      .forEach(function(link){
        if (link.getAttribute("data-vps-toggle-bound") === "1") return;
        link.setAttribute("data-vps-toggle-bound", "1");
        link.addEventListener("click", function(e){
          e.preventDefault();
          e.stopPropagation();
          var sid = parseServiceIdFromRow(link.closest("tr"));
          var rowMeta = sid ? payload.serviceMap[sid] : null;
          if (!rowMeta) {
            rowMeta = {
              enabled: false,
              toggleUrl: link.getAttribute("href") || ""
            };
          }
          toggleAutoRenewInRow(link.closest("tr"), rowMeta, link);
        });
      });
  }

  var servicesListObserverBound = false;
  function bindServicesListObserver(){
    if (servicesListObserverBound || !window.MutationObserver) return;
    var table = document.getElementById("tableServicesList");
    if (!table) return;
    var tbody = table.querySelector("tbody");
    if (!tbody) return;
    servicesListObserverBound = true;
    var timer = null;
    var observer = new MutationObserver(function(){
      if (timer) clearTimeout(timer);
      timer = setTimeout(function(){
        injectServicesListEnhancements();
      }, 60);
    });
    observer.observe(tbody, { childList: true, subtree: true });
  }

  function injectIpSearch(){
    if (!payload.ipSearchUrl || document.getElementById("vpsTopIpSearch")) return;
    var isInvoicesPage = document.body.classList.contains("page-clientareainvoices")
      || /[?&]action=invoices\b/.test(window.location.search || "");
    function findFloatingDock(link){
      if (!link) return null;
      var node = link.parentElement;
      while (node && node !== document.body) {
        var style = window.getComputedStyle(node);
        var rect = node.getBoundingClientRect();
        var isFixed = style.position === "fixed";
        var nearRight = rect.right >= (window.innerWidth - 8);
        var tallEnough = rect.height >= 120;
        if (isFixed && nearRight && tallEnough) {
          return node;
        }
        node = node.parentElement;
      }
      return null;
    }

    var dockHost = null;
    var ticketLink = document.querySelector("a[href*=\\"submitticket.php\\"]");
    var tgLink = document.querySelector("a[href*=\\"t.me/NextCLiBOT\\"]");
    if (ticketLink) {
      dockHost = findFloatingDock(ticketLink);
    }
    if (!dockHost && tgLink) {
      dockHost = findFloatingDock(tgLink);
    }

    var wrap = document.createElement("div");
    wrap.id = "vpsTopIpSearch";
    wrap.className = "vps-top-ip-search is-collapsed";
    if (isInvoicesPage) {
      wrap.className += " vps-top-ip-search-page-right";
    }
    if (dockHost) {
      wrap.className += " vps-top-ip-search-docked";
    }
    wrap.innerHTML = "<button type=\\"button\\" class=\\"tg\\" aria-label=\\"打开搜索\\"><svg class=\\"icon-search\\" viewBox=\\"0 0 24 24\\" aria-hidden=\\"true\\"><circle cx=\\"11\\" cy=\\"11\\" r=\\"7\\"></circle><path d=\\"M20 20l-3.5-3.5\\"></path></svg><svg class=\\"icon-close\\" viewBox=\\"0 0 24 24\\" aria-hidden=\\"true\\"><path d=\\"M6 6l12 12\\"></path><path d=\\"M18 6L6 18\\"></path></svg></button><div class=\\"panel\\"><input class=\\"ipt\\" type=\\"text\\" placeholder=\\"搜索服务器IP/型号\\"><div class=\\"res\\"></div></div>";
    if (isInvoicesPage) {
      document.body.appendChild(wrap);
    } else if (dockHost) {
      var dockTrigger = document.createElement("button");
      dockTrigger.type = "button";
      dockTrigger.className = "vps-ip-search-dock-trigger";
      dockTrigger.setAttribute("aria-label", "打开搜索");
      dockTrigger.innerHTML = "<svg viewBox=\\"0 0 24 24\\" aria-hidden=\\"true\\"><circle cx=\\"11\\" cy=\\"11\\" r=\\"7\\"></circle><path d=\\"M20 20l-3.5-3.5\\"></path></svg>";
      if (ticketLink && ticketLink.parentNode === dockHost) {
        dockHost.insertBefore(dockTrigger, ticketLink);
      } else {
        dockHost.appendChild(dockTrigger);
      }
      document.body.appendChild(wrap);
      wrap.querySelector(".tg").style.display = "none";
    } else {
      document.body.appendChild(wrap);
    }

    var toggleBtn = wrap.querySelector(".tg");
    var panel = wrap.querySelector(".panel");
    var input = wrap.querySelector(".ipt");
    var res = wrap.querySelector(".res");
    var timer = null;

    function openSearch(){
      wrap.classList.remove("is-collapsed");
      document.body.classList.add("vps-ip-search-open");
      if (input) input.focus();
    }
    function closeSearch(){
      wrap.classList.add("is-collapsed");
      document.body.classList.remove("vps-ip-search-open");
      if (res) res.style.display = "none";
    }

    function toggleSearch(e){
      e.stopPropagation();
      if (wrap.classList.contains("is-collapsed")) {
        openSearch();
      } else {
        closeSearch();
      }
    }

    toggleBtn.addEventListener("click", toggleSearch);
    var dockTrigger = document.querySelector(".vps-ip-search-dock-trigger");
    if (dockTrigger && !isInvoicesPage) {
      dockTrigger.addEventListener("click", toggleSearch);
    }
    panel.addEventListener("click", function(e){
      e.stopPropagation();
    });
    input.addEventListener("focus", function(){
      if (wrap.classList.contains("is-collapsed")) {
        openSearch();
      }
    });

    function render(items){
      if (!items || !items.length) {
        res.innerHTML = "<div class=\\"item\\"><div class=\\"s\\">未找到匹配结果</div></div>";
        res.style.display = "block";
        return;
      }
      var html = "";
      items.forEach(function(item){
        var title = item.hostname || item.title || ("服务 #" + item.serviceid);
        var subtitle = item.product_name || "";
        html += "<a class=\\"item\\" href=\\"" + item.url + "\\" target=\\"_blank\\" rel=\\"noopener noreferrer\\"><div class=\\"t\\">" + title + "</div><div class=\\"s\\">" + subtitle + "</div></a>";
      });
      res.innerHTML = html;
      res.style.display = "block";
    }

    input.addEventListener("input", function(){
      var q = (input.value || "").trim();
      if (timer) clearTimeout(timer);
      if (q.length < 2) {
        res.style.display = "none";
        return;
      }
      timer = setTimeout(function(){
        fetch(payload.ipSearchUrl + "?q=" + encodeURIComponent(q), {credentials:"same-origin"})
          .then(function(r){ return r.json(); })
          .then(function(data){ render(data && data.items ? data.items : []); })
          .catch(function(){ res.style.display = "none"; });
      }, 250);
    });

    document.addEventListener("click", function(e){
      if (!wrap.contains(e.target)) {
        closeSearch();
      }
    });
  }

  function injectHomepageDomainSearchIpMode(){
    var form = document.getElementById("frmDomainHomepage");
    if (!form || !payload.ipSearchUrl) return;
    var input = form.querySelector("input[name=\\"domain\\"]");
    if (!input) return;

    var field = form.querySelector(".search-field") || input.parentNode;
    if (!field) return;
    if (!field.style.position) {
      field.style.position = "relative";
    }

    var res = document.createElement("div");
    res.className = "vps-home-ip-res";
    field.appendChild(res);

    var timer = null;

    function isIpLike(q){
      if (!q) return false;
      if (/^(?:\\d{1,3}\\.){1,3}\\d{0,3}$/.test(q)) return true;
      if (q.indexOf(":") !== -1) return true; // IPv6-like
      return false;
    }

    function render(items){
      if (!items || !items.length) {
        res.innerHTML = "<div class=\\"item\\"><div class=\\"s\\">未找到匹配的服务器IP</div></div>";
        res.style.display = "block";
        return;
      }
      var html = "";
      items.forEach(function(item){
        var title = item.hostname || item.title || ("服务 #" + item.serviceid);
        var subtitle = item.product_name || "";
        html += "<a class=\\"item\\" href=\\"" + item.url + "\\" target=\\"_blank\\" rel=\\"noopener noreferrer\\"><div class=\\"t\\">" + title + "</div><div class=\\"s\\">" + subtitle + "</div></a>";
      });
      res.innerHTML = html;
      res.style.display = "block";
    }

    function searchIp(q, cb){
      fetch(payload.ipSearchUrl + "?q=" + encodeURIComponent(q), {credentials:"same-origin"})
        .then(function(r){ return r.json(); })
        .then(function(data){ cb(data && data.items ? data.items : []); })
        .catch(function(){ cb([]); });
    }

    input.addEventListener("input", function(){
      var q = (input.value || "").trim();
      if (timer) clearTimeout(timer);
      if (q.length < 2 || !isIpLike(q)) {
        res.style.display = "none";
        return;
      }
      timer = setTimeout(function(){
        searchIp(q, function(items){ render(items); });
      }, 220);
    });

    form.addEventListener("submit", function(e){
      var q = (input.value || "").trim();
      if (!isIpLike(q)) {
        return; // 域名保持原流程
      }
      e.preventDefault();
      searchIp(q, function(items){
        if (items.length === 1 && items[0].url) {
          window.location.href = items[0].url;
          return;
        }
        render(items);
      });
    }, true);

    document.addEventListener("click", function(e){
      if (!field.contains(e.target)) {
        res.style.display = "none";
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function(){
  injectInvoiceListEnhancements();
  injectInvoiceDetailEnhancements();
  injectInvoiceSidebarManagerLink();
  injectServicesListEnhancements();
  injectProductDetailsEnhancements();
  injectServicesSidebarStrategyLink();
      bindServicesListObserver();
      setTimeout(injectServicesListEnhancements, 180);
      setTimeout(injectServicesListEnhancements, 600);
      setTimeout(injectProductDetailsEnhancements, 180);
      injectIpSearch();
      injectHomepageDomainSearchIpMode();
    });
  } else {
    injectInvoiceListEnhancements();
    injectInvoiceDetailEnhancements();
    injectServicesListEnhancements();
    injectProductDetailsEnhancements();
    bindServicesListObserver();
    setTimeout(injectServicesListEnhancements, 180);
    setTimeout(injectServicesListEnhancements, 600);
    setTimeout(injectProductDetailsEnhancements, 180);
    injectIpSearch();
    injectHomepageDomainSearchIpMode();
  }
})();
</script>';
});

/**
 * 首页域名搜索框支持 IP 全站搜索（独立注入，避免首页未触发 Footer Hook 时失效）
 */
add_hook('ClientAreaHeadOutput', 20, function ($vars) {
	$templateFile = $vars['templatefile'] ?? '';
	$filename = $vars['filename'] ?? '';

	// 仅首页注入
	if ($templateFile !== 'homepage' && $filename !== 'index') {
		return '';
	}

	if (!isset($_SESSION['uid']) || !$_SESSION['uid']) {
		return '';
	}

	$baseUrl = rtrim(\WHMCS\Utility\Environment\WebHelper::getBaseUrl(), '/');
	$apiUrl = $baseUrl . '/modules/addons/vpsrenew/ipsearch.php';

	return '
<style>
.vps-home-ip-res{display:none;position:absolute;left:0;right:0;top:calc(100% + 6px);background:#fff;border:1px solid #e5e7eb;border-radius:8px;max-height:320px;overflow:auto;box-shadow:0 10px 22px rgba(0,0,0,.1);z-index:50}
.vps-home-ip-res .item{display:block;padding:10px 12px;color:#111827;text-decoration:none;border-bottom:1px solid #f1f5f9}
.vps-home-ip-res .item:last-child{border-bottom:0}
.vps-home-ip-res .item .t{font-size:13px;font-weight:600}
.vps-home-ip-res .item .s{font-size:12px;color:#64748b;margin-top:3px}
</style>
<script>
(function(){
  var apiUrl = ' . json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';
  function boot(){
    var form = document.getElementById("frmDomainHomepage") || document.querySelector("form[action*=\'domainchecker.php\']");
    if (!form) return;
    var input = form.querySelector("input[name=\'domain\']");
    if (!input) return;

    var field = form.querySelector(".search-field") || input.parentNode;
    if (!field) return;
    field.style.position = field.style.position || "relative";

    if (field.querySelector(".vps-home-ip-res")) return;
    var res = document.createElement("div");
    res.className = "vps-home-ip-res";
    field.appendChild(res);

    var timer = null;

    function isIpLike(q){
      if (!q) return false;
      if (/^(?:\\d{1,3}\\.){1,3}\\d{0,3}$/.test(q)) return true;
      if (q.indexOf(":") !== -1) return true;
      return false;
    }
    function render(items){
      if (!items || !items.length) {
        res.innerHTML = "<div class=\\"item\\"><div class=\\"s\\">未找到匹配的服务器IP</div></div>";
        res.style.display = "block";
        return;
      }
      var html = "";
      items.forEach(function(item){
        var ips = (item.ips || []).join(", ");
        html += "<a class=\\"item\\" href=\\"" + item.url + "\\"><div class=\\"t\\">" + (item.title || ("服务 #" + item.serviceid)) + "</div><div class=\\"s\\">" + ips + "</div></a>";
      });
      res.innerHTML = html;
      res.style.display = "block";
    }
    function searchIp(q, cb){
      fetch(apiUrl + "?q=" + encodeURIComponent(q), {credentials:"same-origin"})
        .then(function(r){ return r.json(); })
        .then(function(data){ cb(data && data.items ? data.items : []); })
        .catch(function(){ cb([]); });
    }

    input.addEventListener("input", function(){
      var q = (input.value || "").trim();
      if (timer) clearTimeout(timer);
      if (q.length < 2 || !isIpLike(q)) {
        res.style.display = "none";
        return;
      }
      timer = setTimeout(function(){
        searchIp(q, render);
      }, 220);
    });

    form.addEventListener("submit", function(e){
      var q = (input.value || "").trim();
      if (!isIpLike(q)) return;
      e.preventDefault();
      searchIp(q, function(items){
        if (items.length === 1 && items[0].url) {
          window.location.href = items[0].url;
          return;
        }
        render(items);
      });
    }, true);

    document.addEventListener("click", function(e){
      if (!field.contains(e.target)) {
        res.style.display = "none";
      }
    });
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
</script>';
});

/**
 * 结账页显示“开启自动续费”开关
 */
add_hook('ClientAreaFooterOutput', 30, function ($vars) {
	$filename = $vars['filename'] ?? '';
	$templateFile = $vars['templatefile'] ?? '';
	$a = $_GET['a'] ?? '';

	if ($filename !== 'cart' || ($a !== 'checkout' && $templateFile !== 'viewcart')) {
		return '';
	}

	if (!isset($_SESSION['uid']) || !$_SESSION['uid']) {
		return '';
	}

	$checked = !empty($_SESSION['vpsrenew_checkout_autorenew']) ? ' checked' : '';

	return '
<style>
.vps-checkout-autorenew{margin:12px 0;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc}
.vps-checkout-autorenew label{display:flex;align-items:center;gap:8px;margin:0;font-size:14px;color:#0f172a;cursor:pointer}
.vps-checkout-autorenew .desc{margin-top:6px;color:#64748b;font-size:12px}
</style>
<script>
(function(){
  function boot(){
    var form = document.getElementById("frmCheckout");
    if (!form) return;
    if (document.getElementById("vpsCheckoutAutoRenew")) return;

    var block = document.createElement("div");
    block.className = "vps-checkout-autorenew";
    block.id = "vpsCheckoutAutoRenew";
    block.innerHTML = ""
      + "<label><input type=\\"checkbox\\" id=\\"vpsCheckoutAutoRenewCheckbox\\"' . $checked . '> 下单后开启自动续费</label>"
      + "<div class=\\"desc\\">仅对本次订单中的服务器/VPS生效，已生成账单不自动处理。</div>";

    var firstSection = form.querySelector(".section");
    if (firstSection && firstSection.parentNode) {
      firstSection.parentNode.insertBefore(block, firstSection);
    } else {
      form.insertBefore(block, form.firstChild);
    }

    var hidden = document.createElement("input");
    hidden.type = "hidden";
    hidden.name = "vpsrenew_enable_autorenew";
    hidden.id = "vpsrenew_enable_autorenew";
    hidden.value = document.getElementById("vpsCheckoutAutoRenewCheckbox").checked ? "1" : "0";
    form.appendChild(hidden);

    document.getElementById("vpsCheckoutAutoRenewCheckbox").addEventListener("change", function(){
      hidden.value = this.checked ? "1" : "0";
    });
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
</script>';
});

/**
 * 结账提交时记录是否开启自动续费
 */
add_hook('ShoppingCartValidateCheckout', 999, function ($vars) {
	$_SESSION['vpsrenew_checkout_autorenew'] = (!empty($_POST['vpsrenew_enable_autorenew']) && $_POST['vpsrenew_enable_autorenew'] == '1') ? 1 : 0;
});

/**
 * 订单完成页：将本次订单中的服务自动续费开关写入数据库
 */
add_hook('ShoppingCartCheckoutCompletePage', 1, function ($vars) {
	try {
		if (empty($_SESSION['vpsrenew_checkout_autorenew']) || (int) $_SESSION['vpsrenew_checkout_autorenew'] !== 1) {
			return;
		}
		if (!isset($_SESSION['uid']) || !$_SESSION['uid']) {
			return;
		}

		$clientId = (int) $_SESSION['uid'];
		$orderId = 0;
		if (!empty($vars['OrderID'])) {
			$orderId = (int) $vars['OrderID'];
		} elseif (!empty($vars['orderid'])) {
			$orderId = (int) $vars['orderid'];
		} elseif (!empty($_GET['id'])) {
			$orderId = (int) $_GET['id'];
		}
		if ($orderId <= 0) {
			return;
		}

		$config = vpsrenew_get_module_config();
		$defaultMonths = 1;
		if ($config) {
			$defaultMonths = max(1, min((int) $config['max_months'], 1));
		}

		$services = Capsule::table('tblhosting')
			->where('userid', $clientId)
			->where('orderid', $orderId)
			->select(['id'])
			->get();

		foreach ($services as $svc) {
			vpsrenew_set_autorenew_settings($clientId, (int) $svc->id, true, $defaultMonths);
		}

		logActivity('VPS 结账自动续费已开启：客户 #' . $clientId . ' 订单 #' . $orderId . ' 服务数 ' . count($services));
	} catch (Exception $e) {
		logActivity('VPS 结账自动续费写入失败: ' . $e->getMessage());
	} finally {
		unset($_SESSION['vpsrenew_checkout_autorenew']);
	}
});
