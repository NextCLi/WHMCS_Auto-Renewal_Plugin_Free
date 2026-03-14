<?php

use WHMCS\Database\Capsule;

if (!defined('VPSRENEW_AUTORENEW_TABLE')) {
    define('VPSRENEW_AUTORENEW_TABLE', 'mod_vpsrenew_autorenew');
}

if (!function_exists('vpsrenew_get_module_config')) {
    function vpsrenew_get_module_config()
    {
        $moduleConfig = Capsule::table('tbladdonmodules')
            ->where('module', 'vpsrenew')
            ->pluck('value', 'setting');

        if (empty($moduleConfig)) {
            return null;
        }

        $maxMonths = isset($moduleConfig['max_months']) ? (int) $moduleConfig['max_months'] : 36;
        $maxMonths = max(1, min(36, $maxMonths));

        return [
            'enable_discount' => $moduleConfig['enable_discount'] ?? 'yes',
            'discount_3months' => isset($moduleConfig['discount_3months']) ? ((float) $moduleConfig['discount_3months'] / 100) : 1.00,
            'discount_6months' => isset($moduleConfig['discount_6months']) ? ((float) $moduleConfig['discount_6months'] / 100) : 0.80,
            'discount_12months' => isset($moduleConfig['discount_12months']) ? ((float) $moduleConfig['discount_12months'] / 100) : 0.80,
            'max_months' => $maxMonths,
            'split_multi_service_invoice' => $moduleConfig['split_multi_service_invoice'] ?? 'yes',
            'notify_failed_payment' => $moduleConfig['notify_failed_payment'] ?? 'yes',
            'failed_payment_email_template_id' => isset($moduleConfig['failed_payment_email_template_id']) ? (int) $moduleConfig['failed_payment_email_template_id'] : 70,
        ];
    }
}

if (!function_exists('vpsrenew_get_manager_path')) {
    function vpsrenew_get_manager_path()
    {
        return '/addons/auto-renewal/';
    }
}

if (!function_exists('vpsrenew_get_legacy_manager_path')) {
    function vpsrenew_get_legacy_manager_path()
    {
        return '/addons/vpsrenew/';
    }
}

if (!function_exists('vpsrenew_build_manager_url')) {
    function vpsrenew_build_manager_url($baseUrl = '')
    {
        $path = vpsrenew_get_manager_path();
        $baseUrl = trim((string) $baseUrl);
        if ($baseUrl === '') {
            return $path;
        }

        return rtrim($baseUrl, '/') . $path;
    }
}

if (!function_exists('vpsrenew_ensure_tables')) {
    function vpsrenew_ensure_tables()
    {
        if (!Capsule::schema()->hasTable(VPSRENEW_AUTORENEW_TABLE)) {
            Capsule::schema()->create(VPSRENEW_AUTORENEW_TABLE, function ($table) {
                $table->increments('id');
                $table->integer('userid')->unsigned();
                $table->integer('serviceid')->unsigned();
                $table->tinyInteger('enabled')->default(0);
                $table->integer('months')->default(1);
                $table->string('renew_mode', 16)->default('cycle');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['serviceid']);
                $table->index(['userid', 'enabled']);
            });
        }

        if (!Capsule::schema()->hasColumn(VPSRENEW_AUTORENEW_TABLE, 'renew_mode')) {
            Capsule::schema()->table(VPSRENEW_AUTORENEW_TABLE, function ($table) {
                $table->string('renew_mode', 16)->default('cycle')->after('months');
            });
            Capsule::table(VPSRENEW_AUTORENEW_TABLE)
                ->whereNull('renew_mode')
                ->orWhere('renew_mode', '')
                ->update(['renew_mode' => 'cycle']);
        }
    }
}

if (!function_exists('vpsrenew_resolve_monthly_price')) {
    function vpsrenew_resolve_monthly_price($pricing)
    {
        $monthlyPrice = (float) $pricing->monthly;
        if ($monthlyPrice > 0) {
            return $monthlyPrice;
        }

        if ((float) $pricing->quarterly > 0) {
            return (float) $pricing->quarterly / 3;
        }
        if ((float) $pricing->semiannually > 0) {
            return (float) $pricing->semiannually / 6;
        }
        if ((float) $pricing->annually > 0) {
            return (float) $pricing->annually / 12;
        }

        return 0.0;
    }
}

if (!function_exists('vpsrenew_resolve_monthly_price_from_record')) {
    function vpsrenew_resolve_monthly_price_from_record($pricing)
    {
        if (!$pricing) {
            return 0.0;
        }

        if (isset($pricing->monthly) && (float) $pricing->monthly > 0) {
            return (float) $pricing->monthly;
        }

        return vpsrenew_resolve_monthly_price($pricing);
    }
}

if (!function_exists('vpsrenew_resolve_service_cycle_months')) {
    function vpsrenew_resolve_service_cycle_months($billingCycle, $maxMonths = 36)
    {
        $cycle = strtolower(trim((string) $billingCycle));
        $map = [
            'monthly' => 1,
            'quarterly' => 3,
            'semi-annually' => 6,
            'semiannually' => 6,
            'annually' => 12,
            'biennially' => 24,
            'triennially' => 36,
        ];

        if (!isset($map[$cycle])) {
            return 0;
        }

        return max(1, min((int) $maxMonths, (int) $map[$cycle]));
    }
}

if (!function_exists('vpsrenew_resolve_service_monthly_price')) {
    function vpsrenew_resolve_service_monthly_price($service, $currencyId, $maxMonths = 36)
    {
        $breakdown = vpsrenew_resolve_service_monthly_price_breakdown($service, $currencyId, $maxMonths);
        return (float) ($breakdown['total_monthly_price'] ?? 0.0);
    }
}

if (!function_exists('vpsrenew_resolve_service_monthly_price_breakdown')) {
    function vpsrenew_resolve_service_monthly_price_breakdown($service, $currencyId, $maxMonths = 36)
    {
        $serviceId = (int) ($service->id ?? 0);
        if ($serviceId <= 0) {
            $serviceId = (int) ($service->serviceid ?? 0);
        }
        $packageId = (int) ($service->packageid ?? 0);
        $currencyId = (int) $currencyId;
        if ($serviceId <= 0 || $packageId <= 0 || $currencyId <= 0) {
            return [
                'product_monthly_price' => 0.0,
                'config_monthly_price' => 0.0,
                'total_monthly_price' => 0.0,
            ];
        }

        $productMonthlyAmount = 0.0;
        $configMonthlyAmount = 0.0;

        $productPricing = Capsule::table('tblpricing')
            ->where('type', 'product')
            ->where('currency', $currencyId)
            ->where('relid', $packageId)
            ->first();
        if ($productPricing) {
            $productMonthlyAmount += vpsrenew_resolve_monthly_price_from_record($productPricing);
        }

        if (Capsule::schema()->hasTable('tblhostingconfigoptions') && Capsule::schema()->hasTable('tblpricing')) {
            $configRows = Capsule::table('tblhostingconfigoptions')
                ->where('relid', $serviceId)
                ->get(['optionid', 'qty']);

            foreach ($configRows as $configRow) {
                $optionId = (int) ($configRow->optionid ?? 0);
                if ($optionId <= 0) {
                    continue;
                }

                $configPricing = Capsule::table('tblpricing')
                    ->where('type', 'configoptions')
                    ->where('currency', $currencyId)
                    ->where('relid', $optionId)
                    ->first();
                if (!$configPricing) {
                    continue;
                }

                $configOptionMonthlyAmount = vpsrenew_resolve_monthly_price_from_record($configPricing);
                if ($configOptionMonthlyAmount <= 0) {
                    continue;
                }

                $qty = max(1, (int) ($configRow->qty ?? 0));
                $configMonthlyAmount += round($configOptionMonthlyAmount * $qty, 8);
            }
        }

        $monthlyAmount = $productMonthlyAmount + $configMonthlyAmount;
        if ($monthlyAmount <= 0) {
            return [
                'product_monthly_price' => 0.0,
                'config_monthly_price' => 0.0,
                'total_monthly_price' => 0.0,
            ];
        }

        return [
            'product_monthly_price' => round($productMonthlyAmount, 8),
            'config_monthly_price' => round($configMonthlyAmount, 8),
            'total_monthly_price' => round($monthlyAmount, 8),
        ];
    }
}

if (!function_exists('vpsrenew_calculate_price')) {
    function vpsrenew_calculate_price($monthlyPrice, $months, array $config)
    {
        if (is_array($monthlyPrice)) {
            $productMonthlyPrice = (float) ($monthlyPrice['product_monthly_price'] ?? 0.0);
            $configMonthlyPrice = (float) ($monthlyPrice['config_monthly_price'] ?? 0.0);
        } else {
            $productMonthlyPrice = (float) $monthlyPrice;
            $configMonthlyPrice = 0.0;
        }

        $productBase = round($productMonthlyPrice * $months, 2);
        $configBase = round($configMonthlyPrice * $months, 2);
        $discountMultiplier = 1.0;

        $discountEnabled = ($config['enable_discount'] === 'on' || $config['enable_discount'] === 'yes');
        if ($discountEnabled) {
            if ($months >= 12) {
                $discountMultiplier = (float) $config['discount_12months'];
            } elseif ($months >= 6) {
                $discountMultiplier = (float) $config['discount_6months'];
            } elseif ($months >= 3) {
                $discountMultiplier = (float) $config['discount_3months'];
            }
        }

        $discountedProductTotal = round($productBase * $discountMultiplier, 2);
        $base = round($productBase + $configBase, 2);
        $total = round($discountedProductTotal + $configBase, 2);
        $discountAmount = round($productBase - $discountedProductTotal, 2);
        $discountPercent = (int) round((1 - $discountMultiplier) * 100);

        return [
            'base' => $base,
            'total' => $total,
            'product_base' => $productBase,
            'config_base' => $configBase,
            'discount_amount' => $discountAmount,
            'discount_percent' => max(0, $discountPercent),
            'discount_multiplier' => $discountMultiplier,
        ];
    }
}

if (!function_exists('vpsrenew_currency_parts')) {
    function vpsrenew_currency_parts($currencyId)
    {
        $currency = Capsule::table('tblcurrencies')->where('id', $currencyId)->first();
        return [
            'prefix' => $currency->prefix ?? '',
            'suffix' => $currency->suffix ?? '',
        ];
    }
}

if (!function_exists('vpsrenew_get_service_renewal_options')) {
    function vpsrenew_get_service_renewal_options($clientId, $serviceId, array $config = null)
    {
        $clientId = (int) $clientId;
        $serviceId = (int) $serviceId;
        if ($clientId <= 0 || $serviceId <= 0) {
            return [];
        }

        if ($config === null) {
            $config = vpsrenew_get_module_config();
        }
        if (!$config) {
            return [];
        }

        $service = Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->where('userid', $clientId)
            ->first();
        if (!$service) {
            return [];
        }

        $client = Capsule::table('tblclients')
            ->where('id', $clientId)
            ->first();
        if (!$client) {
            return [];
        }

        $monthlyPrice = vpsrenew_resolve_service_monthly_price_breakdown($service, (int) $client->currency, (int) ($config['max_months'] ?? 36));
        if (($monthlyPrice['total_monthly_price'] ?? 0) <= 0) {
            return [];
        }

        $currency = vpsrenew_currency_parts($client->currency);
        $maxMonths = max(1, (int) ($config['max_months'] ?? 1));
        $options = [];

        for ($months = 1; $months <= $maxMonths; $months++) {
            $price = vpsrenew_calculate_price($monthlyPrice, $months, $config);
            $priceFormatted = $currency['prefix'] . number_format($price['total'], 2) . $currency['suffix'];
            $label = $months . ' 个月 - ' . $priceFormatted;
            if ($price['discount_amount'] > 0) {
                $label .= '（省 ' . $currency['prefix'] . number_format($price['discount_amount'], 2) . $currency['suffix'] . '）';
            }

            $options[] = [
                'months' => $months,
                'label' => $label,
                'price' => $priceFormatted,
                'discountAmount' => $price['discount_amount'],
                'priceFormatted' => $priceFormatted,
                'priceValue' => $price['total'],
            ];
        }

        return $options;
    }
}

if (!function_exists('vpsrenew_format_display_date')) {
    function vpsrenew_format_display_date($dateYmd)
    {
        $dateYmd = trim((string) $dateYmd);
        if ($dateYmd === '' || $dateYmd === '0000-00-00') {
            return '';
        }

        // 优先使用 WHMCS 内置格式化（跟随后台日期设置）
        if (function_exists('fromMySQLDate')) {
            $formatted = (string) fromMySQLDate($dateYmd);
            if ($formatted !== '') {
                return $formatted;
            }
        }

        // 兜底：按 tblconfiguration.DateFormat 转换
        $rawFormat = (string) Capsule::table('tblconfiguration')
            ->where('setting', 'DateFormat')
            ->value('value');
        $map = [
            'DD/MM/YYYY' => 'd/m/Y',
            'DD.MM.YYYY' => 'd.m.Y',
            'DD-MM-YYYY' => 'd-m-Y',
            'MM/DD/YYYY' => 'm/d/Y',
            'YYYY/MM/DD' => 'Y/m/d',
            'YYYY-MM-DD' => 'Y-m-d',
        ];
        $phpFormat = isset($map[$rawFormat]) ? $map[$rawFormat] : 'Y-m-d';

        $ts = strtotime($dateYmd . ' 00:00:00');
        if ($ts === false) {
            return $dateYmd;
        }
        return date($phpFormat, $ts);
    }
}

if (!function_exists('vpsrenew_build_renew_period_label')) {
    function vpsrenew_build_renew_period_label($baseDateYmd, $months)
    {
        $baseDateYmd = trim((string) $baseDateYmd);
        $months = max(1, (int) $months);

        if ($baseDateYmd === '' || $baseDateYmd === '0000-00-00') {
            $baseDateYmd = date('Y-m-d');
        }

        $startTs = strtotime($baseDateYmd . ' 00:00:00');
        if ($startTs === false) {
            return '';
        }

        // 续费区间采用包含式展示：开始日 到 (开始日 + N个月 - 1天)
        $endTs = strtotime('-1 day', strtotime('+' . $months . ' month', $startTs));
        if ($endTs === false) {
            return '';
        }

        $start = vpsrenew_format_display_date(date('Y-m-d', $startTs));
        $end = vpsrenew_format_display_date(date('Y-m-d', $endTs));
        if ($start === '' || $end === '') {
            return '';
        }

        return ' (' . $start . ' - ' . $end . ')';
    }
}

if (!function_exists('vpsrenew_get_cycle_label_map')) {
    function vpsrenew_get_cycle_label_map()
    {
        return [
            'monthly' => '月',
            'quarterly' => '季',
            'semi-annually' => '半年',
            'semiannually' => '半年',
            'annually' => '年',
            'biennially' => '两年',
            'triennially' => '三年',
            'one time' => '一次性',
            'onetime' => '一次性',
            'free account' => '免费',
            'free' => '免费',
        ];
    }
}

if (!function_exists('vpsrenew_get_cycle_label')) {
    function vpsrenew_get_cycle_label($billingCycle)
    {
        $cycle = strtolower(trim((string) $billingCycle));
        $map = vpsrenew_get_cycle_label_map();
        return $map[$cycle] ?? (string) $billingCycle;
    }
}

if (!function_exists('vpsrenew_resolve_billing_cycle_by_months')) {
    function vpsrenew_resolve_billing_cycle_by_months($months)
    {
        $months = (int) $months;
        $map = [
            1 => 'Monthly',
            3 => 'Quarterly',
            6 => 'Semi-Annually',
            12 => 'Annually',
            24 => 'Biennially',
            36 => 'Triennially',
        ];

        return $map[$months] ?? null;
    }
}

if (!function_exists('vpsrenew_format_amount_with_cycle')) {
    function vpsrenew_format_amount_with_cycle($amount, array $currency, $cycleLabel)
    {
        $suffix = trim((string) ($currency['suffix'] ?? ''));
        $price = (string) ($currency['prefix'] ?? '') . number_format((float) $amount, 2) . ($suffix !== '' ? (' ' . $suffix) : '');
        $cycleLabel = trim((string) $cycleLabel);

        if ($cycleLabel === '' || $cycleLabel === '一次性' || $cycleLabel === '免费') {
            return $price;
        }

        return $price . ' / ' . $cycleLabel;
    }
}

if (!function_exists('vpsrenew_get_due_meta')) {
    function vpsrenew_get_due_meta($dateYmd, $todayYmd = '')
    {
        $dateYmd = trim((string) $dateYmd);
        $todayYmd = trim((string) $todayYmd);

        if ($dateYmd === '' || $dateYmd === '0000-00-00') {
            return [
                'date' => '',
                'date_display' => '',
                'days' => null,
                'text' => '',
                'class' => 'normal',
                'filter' => 'unknown',
            ];
        }

        if ($todayYmd === '' || $todayYmd === '0000-00-00') {
            $todayYmd = date('Y-m-d');
        }

        $todayTs = strtotime($todayYmd . ' 00:00:00');
        $dueTs = strtotime($dateYmd . ' 00:00:00');
        $days = null;
        $text = '';
        $class = 'normal';
        $filter = 'all';

        if ($todayTs !== false && $dueTs !== false) {
            $days = (int) ceil(($dueTs - $todayTs) / 86400);
            if ($days < 0) {
                $abs = abs($days);
                $class = 'muted';
                $filter = $abs <= 2 ? 'recoverable' : 'expired';
                $text = $abs > 2 ? ('已过期' . $abs . '天，无法找回') : ('已过期' . $abs . '天，可续费找回');
            } elseif ($days === 0) {
                $class = 'danger';
                $filter = 'today';
                $text = '今日到期';
            } else {
                $text = '剩余' . $days . '天';
                if ($days < 7) {
                    $class = 'danger';
                    $filter = '7';
                } elseif ($days < 15) {
                    $class = 'warn';
                    $filter = '15';
                } elseif ($days <= 30) {
                    $filter = '30';
                } else {
                    $filter = 'future';
                }
            }
        }

        return [
            'date' => $dateYmd,
            'date_display' => vpsrenew_format_display_date($dateYmd),
            'days' => $days,
            'text' => $text,
            'class' => $class,
            'filter' => $filter,
        ];
    }
}

if (!function_exists('vpsrenew_get_strategy_options')) {
    function vpsrenew_get_strategy_options()
    {
        return [
            'cycle' => '按当前账单周期续费',
            '1' => '按月续费',
            '3' => '按季续费',
            '6' => '按半年续费 (节省1个月)',
            '12' => '按年续费 (节省2个月)',
        ];
    }
}

if (!function_exists('vpsrenew_resolve_strategy_key')) {
    function vpsrenew_resolve_strategy_key($renewMode, $months)
    {
        $renewMode = strtolower(trim((string) $renewMode));
        $months = (int) $months;

        if ($renewMode === 'cycle') {
            return 'cycle';
        }

        if ($renewMode === 'month') {
            return '1';
        }

        return in_array($months, [1, 3, 6, 12], true) ? (string) $months : '1';
    }
}

if (!function_exists('vpsrenew_strategy_key_to_payload')) {
    function vpsrenew_strategy_key_to_payload($strategyKey)
    {
        $strategyKey = trim((string) $strategyKey);
        if ($strategyKey === 'cycle') {
            return [
                'renew_mode' => 'cycle',
                'months' => 1,
            ];
        }

        $months = in_array((int) $strategyKey, [1, 3, 6, 12], true) ? (int) $strategyKey : 1;
        return [
            'renew_mode' => $months === 1 ? 'month' : 'fixed',
            'months' => $months,
        ];
    }
}

if (!function_exists('vpsrenew_sync_service_billing_cycle')) {
    function vpsrenew_sync_service_billing_cycle($serviceId, $months, array $config = null)
    {
        $serviceId = (int) $serviceId;
        $months = (int) $months;
        if ($serviceId <= 0 || $months <= 0) {
            throw new InvalidArgumentException('Invalid service billing cycle sync payload');
        }

        $billingCycle = vpsrenew_resolve_billing_cycle_by_months($months);
        if ($billingCycle === null) {
            throw new InvalidArgumentException('Unsupported billing cycle months: ' . $months);
        }

        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$service) {
            throw new RuntimeException('Service not found');
        }

        if ($config === null) {
            $config = vpsrenew_get_module_config();
            if (!$config) {
                throw new RuntimeException('VPSRenew module config not found');
            }
        }

        $client = Capsule::table('tblclients')->where('id', (int) $service->userid)->first();
        if (!$client) {
            throw new RuntimeException('Client not found');
        }

        $monthlyPrice = vpsrenew_resolve_service_monthly_price_breakdown($service, (int) $client->currency, (int) ($config['max_months'] ?? 36));
        if (($monthlyPrice['total_monthly_price'] ?? 0) <= 0) {
            throw new RuntimeException('Service pricing not found');
        }

        $price = vpsrenew_calculate_price($monthlyPrice, $months, $config);
        Capsule::table('tblhosting')->where('id', $serviceId)->update([
            'billingcycle' => $billingCycle,
            'amount' => number_format($price['total'], 2, '.', ''),
        ]);

        $currency = vpsrenew_currency_parts((int) $client->currency);
        $cycleLabel = vpsrenew_get_cycle_label($billingCycle);

        return [
            'billingcycle' => $billingCycle,
            'billingcycle_label' => $cycleLabel,
            'amount' => (float) $price['total'],
            'amount_formatted' => vpsrenew_format_amount_with_cycle($price['total'], $currency, $cycleLabel),
        ];
    }
}

if (!function_exists('vpsrenew_find_unpaid_service_invoice')) {
    function vpsrenew_find_unpaid_service_invoice($serviceId, $clientId)
    {
        return Capsule::table('tblinvoiceitems')
            ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
            ->where('tblinvoices.userid', $clientId)
            ->where('tblinvoices.status', 'Unpaid')
            ->where(function ($query) use ($serviceId) {
                $query->where(function ($sub) use ($serviceId) {
                    $sub->where('tblinvoiceitems.type', 'Hosting')
                        ->where('tblinvoiceitems.relid', $serviceId);
                })
                ->orWhere('tblinvoiceitems.description', 'like', '%[VPSRENEW:sid=' . (int) $serviceId . ';%')
                ->orWhere('tblinvoices.notes', 'like', '%VPSRENEW:sid=' . (int) $serviceId . ';%');
            })
            ->orderByDesc('tblinvoices.id')
            ->select('tblinvoices.id as invoiceid')
            ->first();
    }
}

if (!function_exists('vpsrenew_build_service_title')) {
    function vpsrenew_build_service_title($domain, $productName, $serviceId)
    {
        $domain = trim((string) $domain);
        $productName = trim((string) $productName);
        $fallback = '服务 #' . (int) $serviceId;

        if ($domain !== '' && $productName !== '') {
            return $domain . ' - ' . $productName;
        }
        if ($domain !== '') {
            return $domain;
        }
        if ($productName !== '') {
            return $productName . ' - ' . $fallback;
        }

        return $fallback;
    }
}

if (!function_exists('vpsrenew_normalize_detail_label')) {
    function vpsrenew_normalize_detail_label($label)
    {
        $label = trim((string) $label);
        if ($label === '') {
            return '';
        }

        if (strpos($label, '|') !== false) {
            $parts = explode('|', $label, 2);
            $label = trim((string) $parts[0]);
        }

        return preg_replace('/\s+/', ' ', $label);
    }
}

if (!function_exists('vpsrenew_normalize_detail_value')) {
    function vpsrenew_normalize_detail_value($value)
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace("/\n{2,}/", "\n", $value);
        $value = preg_replace('/[ \t]+/', ' ', $value);
        $value = trim((string) $value);

        return $value;
    }
}

if (!function_exists('vpsrenew_is_safe_detail_label')) {
    function vpsrenew_is_safe_detail_label($label)
    {
        $label = strtolower(vpsrenew_normalize_detail_label($label));
        if ($label === '') {
            return false;
        }

        if (preg_match('/password|passwd|secret|token|key|root|username|user name|hostid|port|邮箱|密码|口令|密钥|令牌|账号|端口/', $label)) {
            return false;
        }

        return (bool) preg_match(
            '/cpu|processor|ram|memory|disk|hard ?disk|raid|os|operating ?system|bandwidth|ddos|\bip\b|ip count|ip num|network|partition|处理器|内存|硬盘|阵列|操作系统|系统|带宽|防御|内网|分区|ip数量|ip个数|数量/',
            $label
        );
    }
}

if (!function_exists('vpsrenew_should_append_service_details')) {
    function vpsrenew_should_append_service_details($productName)
    {
        $productName = strtolower(trim((string) $productName));
        return $productName !== '';
    }
}

if (!function_exists('vpsrenew_collect_service_detail_lines')) {
    function vpsrenew_collect_service_detail_lines($serviceId, $packageId = 0)
    {
        $serviceId = (int) $serviceId;
        $packageId = (int) $packageId;
        if ($serviceId <= 0) {
            return [];
        }

        $lines = [];
        $seen = [];

        $appendLine = function ($label, $value) use (&$lines, &$seen) {
            $label = vpsrenew_normalize_detail_label($label);
            $value = vpsrenew_normalize_detail_value($value);
            if ($label === '' || $value === '' || !vpsrenew_is_safe_detail_label($label)) {
                return;
            }

            $key = strtolower($label . "\n" . $value);
            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $lines[] = $label . ': ' . str_replace("\n", ' / ', $value);
        };

        if (Capsule::schema()->hasTable('tblhostingconfigoptions')
            && Capsule::schema()->hasTable('tblproductconfigoptions')
            && Capsule::schema()->hasTable('tblproductconfigoptionssub')
        ) {
            $configRows = Capsule::table('tblhostingconfigoptions as hco')
                ->leftJoin('tblproductconfigoptions as pco', 'pco.id', '=', 'hco.configid')
                ->leftJoin('tblproductconfigoptionssub as pcos', 'pcos.id', '=', 'hco.optionid')
                ->where('hco.relid', $serviceId)
                ->orderBy('hco.id', 'asc')
                ->select([
                    'pco.optionname',
                    'pco.optiontype',
                    'pco.hidden',
                    'pcos.optionname as selected_option',
                    'hco.qty',
                ])
                ->get();

            foreach ($configRows as $row) {
                if ((int) ($row->hidden ?? 0) === 1) {
                    continue;
                }

                $label = $row->optionname ?? '';
                $optionType = (int) ($row->optiontype ?? 0);
                $selectedOption = trim((string) ($row->selected_option ?? ''));
                $qty = trim((string) ($row->qty ?? ''));
                $value = '';

                if ($optionType === 3) {
                    $value = ((int) $qty > 0) ? 'Yes' : 'No';
                } elseif ($optionType === 4) {
                    if ($selectedOption !== '' && $qty !== '') {
                        $value = $qty . ' x ' . $selectedOption;
                    } elseif ($qty !== '') {
                        $value = $qty;
                    }
                } else {
                    $value = $selectedOption !== '' ? $selectedOption : $qty;
                }

                $appendLine($label, $value);
            }
        }

        if ($packageId > 0 && Capsule::schema()->hasTable('tblcustomfields') && Capsule::schema()->hasTable('tblcustomfieldsvalues')) {
            $customRows = Capsule::table('tblcustomfields as cf')
                ->join('tblcustomfieldsvalues as cfv', function ($join) use ($serviceId) {
                    $join->on('cfv.fieldid', '=', 'cf.id')
                        ->where('cfv.relid', '=', $serviceId);
                })
                ->where('cf.type', 'product')
                ->where('cf.relid', $packageId)
                ->orderBy('cf.sortorder', 'asc')
                ->orderBy('cf.id', 'asc')
                ->select([
                    'cf.fieldname',
                    'cfv.value',
                ])
                ->get();

            foreach ($customRows as $row) {
                $appendLine($row->fieldname ?? '', $row->value ?? '');
            }
        }

        if (Capsule::schema()->hasTable('tblhostingaddons')) {
            $addonRows = Capsule::table('tblhostingaddons')
                ->where('hostingid', $serviceId)
                ->whereIn('status', ['Active', 'Suspended'])
                ->orderBy('id', 'asc')
                ->select([
                    'name',
                    'billingcycle',
                    'qty',
                ])
                ->get();

            foreach ($addonRows as $row) {
                $name = trim((string) ($row->name ?? ''));
                if ($name === '') {
                    continue;
                }

                $qty = (int) ($row->qty ?? 0);
                $value = $name;
                if ($qty > 1) {
                    $value .= ' x ' . $qty;
                }

                $cycleLabel = trim((string) vpsrenew_get_cycle_label($row->billingcycle ?? ''));
                if ($cycleLabel !== '') {
                    $value .= ' / ' . $cycleLabel;
                }

                $appendLine('附加服务', $value);
            }
        }

        return array_slice($lines, 0, 12);
    }
}

if (!function_exists('vpsrenew_create_invoice')) {
    function vpsrenew_create_invoice($clientId, $serviceId, $serviceName, $months, array $price, $paymentMethod, $sendInvoice, $isAuto)
    {
        $service = Capsule::table('tblhosting')->where('id', (int) $serviceId)->first(['nextduedate', 'packageid']);
        $dueDate = $service ? (string) ($service->nextduedate ?? '') : '';
        $periodLabel = vpsrenew_build_renew_period_label($dueDate, (int) $months);
        $descriptionLines = [
            $serviceName . ' - 续费 ' . (int) $months . ' 个月' . $periodLabel,
        ];

        if ($service) {
            $packageId = (int) ($service->packageid ?? 0);
            $productName = '';
            if ($packageId > 0) {
                $productName = (string) Capsule::table('tblproducts')
                    ->where('id', $packageId)
                    ->value('name');
            }

            if (vpsrenew_should_append_service_details($productName)) {
                $detailLines = vpsrenew_collect_service_detail_lines($serviceId, $packageId);
                if (!empty($detailLines)) {
                    $descriptionLines = array_merge($descriptionLines, $detailLines);
                }
            }
        }

        $params = [
            'userid' => (int) $clientId,
            'status' => 'Unpaid',
            'sendinvoice' => $sendInvoice ? true : false,
            'paymentmethod' => $paymentMethod ?: 'mailin',
            'date' => date('Y-m-d'),
            'duedate' => date('Y-m-d'),
            'itemdescription1' => implode("\n", $descriptionLines),
            'itemamount1' => number_format($price['base'], 2, '.', ''),
            'itemtaxed1' => true,
        ];

        if ($price['discount_amount'] > 0) {
            $params['itemdescription2'] = '续费优惠 -' . (int) $price['discount_percent'] . '%';
            $params['itemamount2'] = number_format(-1 * $price['discount_amount'], 2, '.', '');
            $params['itemtaxed2'] = false;
        }

        $result = localAPI('CreateInvoice', $params);
        if (($result['result'] ?? '') !== 'success') {
            throw new Exception('创建发票失败：' . ($result['message'] ?? '未知错误'));
        }

        $invoiceId = (int) $result['invoiceid'];

        $existingNotes = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('notes');
        $noteLine = 'VPSRENEW:sid=' . (int) $serviceId . ';months=' . (int) $months . ';auto=' . ($isAuto ? '1' : '0');
        $merged = trim($existingNotes . "\n" . $noteLine);
        Capsule::table('tblinvoices')->where('id', $invoiceId)->update(['notes' => $merged]);

        return $invoiceId;
    }
}

if (!function_exists('vpsrenew_get_autorenew_settings')) {
    function vpsrenew_get_autorenew_settings($clientId, $serviceId)
    {
        return Capsule::table(VPSRENEW_AUTORENEW_TABLE)
            ->where('userid', $clientId)
            ->where('serviceid', $serviceId)
            ->first();
    }
}

if (!function_exists('vpsrenew_set_autorenew_settings')) {
    function vpsrenew_set_autorenew_settings($clientId, $serviceId, $enabled, $months, $renewMode = 'cycle')
    {
        $renewMode = strtolower(trim((string) $renewMode));
        if (!in_array($renewMode, ['month', 'cycle', 'fixed'], true)) {
            $renewMode = 'cycle';
        }

        $exists = Capsule::table(VPSRENEW_AUTORENEW_TABLE)
            ->where('userid', $clientId)
            ->where('serviceid', $serviceId)
            ->first();

        $payload = [
            'enabled' => $enabled ? 1 : 0,
            'months' => (int) $months,
            'renew_mode' => $renewMode,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($exists) {
            Capsule::table(VPSRENEW_AUTORENEW_TABLE)
                ->where('id', $exists->id)
                ->update($payload);
            return;
        }

        $payload['userid'] = (int) $clientId;
        $payload['serviceid'] = (int) $serviceId;
        $payload['created_at'] = date('Y-m-d H:i:s');

        Capsule::table(VPSRENEW_AUTORENEW_TABLE)->insert($payload);
    }
}

if (!function_exists('vpsrenew_resolve_cycle_months')) {
    function vpsrenew_resolve_cycle_months($billingCycle, $fallbackMonths = 1, $maxMonths = 36)
    {
        $cycle = strtolower(trim((string) $billingCycle));
        $map = [
            'monthly' => 1,
            'quarterly' => 3,
            'semi-annually' => 6,
            'semiannually' => 6,
            'annually' => 12,
            'biennially' => 24,
            'triennially' => 36,
        ];
        $months = isset($map[$cycle]) ? (int) $map[$cycle] : (int) $fallbackMonths;
        $max = max(1, (int) $maxMonths);
        return max(1, min($max, $months));
    }
}

if (!function_exists('vpsrenew_extend_service_next_due_date')) {
    function vpsrenew_extend_service_next_due_date($serviceId, $months)
    {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$service || $months <= 0) {
            return false;
        }

        $fromDate = $service->nextduedate;
        if (!$fromDate || $fromDate === '0000-00-00') {
            $fromDate = date('Y-m-d');
        }

        $baseTs = strtotime($fromDate . ' 00:00:00');
        if ($baseTs === false) {
            $baseTs = time();
        }

        $newDate = date('Y-m-d', strtotime('+' . (int) $months . ' month', $baseTs));

        Capsule::table('tblhosting')->where('id', $serviceId)->update([
            'nextduedate' => $newDate,
            'nextinvoicedate' => $newDate,
        ]);

        return $newDate;
    }
}

if (!function_exists('vpsrenew_invoice_has_due_extended_marker')) {
    function vpsrenew_invoice_has_due_extended_marker($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return false;
        }
        $notes = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('notes');
        return strpos($notes, 'VPSRENEW_DUE_EXTENDED') !== false;
    }
}

if (!function_exists('vpsrenew_mark_invoice_due_extended')) {
    function vpsrenew_mark_invoice_due_extended($invoiceId, $serviceId, $months, $source = '')
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return false;
        }

        if (vpsrenew_invoice_has_due_extended_marker($invoiceId)) {
            return true;
        }

        $line = 'VPSRENEW_DUE_EXTENDED:sid=' . (int) $serviceId . ';months=' . (int) $months;
        if ($source !== '') {
            $line .= ';source=' . (string) $source;
        }

        $existing = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('notes');
        Capsule::table('tblinvoices')->where('id', $invoiceId)->update([
            'notes' => trim($existing . "\n" . $line),
        ]);

        return true;
    }
}

if (!function_exists('vpsrenew_run_autorenew_cycle')) {
    function vpsrenew_run_autorenew_cycle(array $opts = [])
    {
        $source = isset($opts['source']) ? (string) $opts['source'] : 'manual';
        $today = isset($opts['today']) ? (string) $opts['today'] : date('Y-m-d');

        $config = vpsrenew_get_module_config();
        if (!$config) {
            logActivity('VPS 自动续费跳过：模块配置不存在（source=' . $source . '）');
            return 0;
        }

        $rows = Capsule::table(VPSRENEW_AUTORENEW_TABLE . ' as ar')
            ->join('tblhosting as h', 'h.id', '=', 'ar.serviceid')
            ->join('tblclients as c', 'c.id', '=', 'ar.userid')
            ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('ar.enabled', 1)
            ->where('h.domainstatus', 'Active')
            ->where('h.nextduedate', '<=', $today)
            ->select([
                'ar.userid',
                'ar.serviceid',
                'ar.months',
                'ar.renew_mode',
                'h.packageid',
                'h.paymentmethod',
                'h.billingcycle',
                'h.domain',
                'h.amount',
                'p.name as product_name',
                'c.currency',
                'c.credit',
            ])
            ->get();

        $processed = 0;
        foreach ($rows as $row) {
            $processed++;
            $clientId = (int) $row->userid;
            $serviceId = (int) $row->serviceid;
            $renewMode = strtolower((string) ($row->renew_mode ?? 'cycle'));
            if (!in_array($renewMode, ['month', 'cycle', 'fixed'], true)) {
                $renewMode = 'cycle';
            }
            if ($renewMode === 'month') {
                // 按月续费固定为 1 个月，不读取历史 months 值。
                $months = 1;
            } elseif ($renewMode === 'fixed') {
                // 固定周期：使用设置值（3/6/12等）
                $months = max(1, min((int) $config['max_months'], (int) $row->months));
            } else {
                // 按当前账单周期续费
                $months = max(1, min((int) $config['max_months'], (int) $row->months));
                $months = vpsrenew_resolve_cycle_months((string) ($row->billingcycle ?? ''), $months, (int) $config['max_months']);
            }

            $unpaid = vpsrenew_find_unpaid_service_invoice($serviceId, $clientId);
            if ($unpaid) {
                logActivity('VPS 自动续费跳过：服务 #' . $serviceId . ' 存在未支付账单 #' . (int) $unpaid->invoiceid . '（source=' . $source . '）');
                continue;
            }

            $monthlyPrice = vpsrenew_resolve_service_monthly_price_breakdown($row, (int) $row->currency, (int) ($config['max_months'] ?? 36));
            if (($monthlyPrice['total_monthly_price'] ?? 0) <= 0) {
                logActivity('VPS 自动续费跳过：服务 #' . $serviceId . ' 无有效续费金额（source=' . $source . '）');
                continue;
            }

            $serviceName = vpsrenew_build_service_title($row->domain, $row->product_name, $serviceId);
            $price = vpsrenew_calculate_price($monthlyPrice, $months, $config);

            $invoiceId = vpsrenew_create_invoice(
                $clientId,
                $serviceId,
                $serviceName,
                $months,
                $price,
                $row->paymentmethod ?: 'mailin',
                false,
                true
            );

            $invoiceTotal = (float) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total');
            $credit = (float) Capsule::table('tblclients')->where('id', $clientId)->value('credit');

            if ($credit >= $invoiceTotal && $invoiceTotal > 0) {
                $apply = localAPI('ApplyCredit', [
                    'invoiceid' => $invoiceId,
                    'amount' => number_format($invoiceTotal, 2, '.', ''),
                ]);

                if (($apply['result'] ?? '') === 'success') {
                    logActivity('VPS 自动续费成功：服务 #' . $serviceId . ' 自动扣余额支付账单 #' . $invoiceId . '，金额：' . number_format($invoiceTotal, 2) . '（source=' . $source . '）');
                    if (!vpsrenew_invoice_has_due_extended_marker($invoiceId)) {
                        $newDate = vpsrenew_extend_service_next_due_date($serviceId, $months);
                        if ($newDate) {
                            vpsrenew_mark_invoice_due_extended($invoiceId, $serviceId, $months, 'autorenew_applycredit');
                            logActivity('VPS 自动续费已顺延服务 #' . $serviceId . ' 到期时间至 ' . $newDate . '（账单 #' . $invoiceId . '，source=' . $source . '）');
                        }
                    }
                } else {
                    $reason = '余额扣款失败：' . ($apply['message'] ?? '未知');
                    logActivity('VPS 自动续费账单已创建但余额扣款失败：服务 #' . $serviceId . '，账单 #' . $invoiceId . '，原因：' . ($apply['message'] ?? '未知') . '（source=' . $source . '）');
                    vpsrenew_send_failed_payment_email($clientId, $serviceId, $invoiceId, $reason, $source, $config);
                }
            } else {
                logActivity('VPS 自动续费账单已创建但余额不足：服务 #' . $serviceId . '，账单 #' . $invoiceId . '，需支付：' . number_format($invoiceTotal, 2) . '，余额：' . number_format($credit, 2) . '（source=' . $source . '）');
                vpsrenew_send_failed_payment_email(
                    $clientId,
                    $serviceId,
                    $invoiceId,
                    '账户余额不足（需支付 ' . number_format($invoiceTotal, 2) . '，当前余额 ' . number_format($credit, 2) . '）',
                    $source,
                    $config
                );
            }
        }

        return $processed;
    }
}

if (!function_exists('vpsrenew_send_failed_payment_email')) {
    function vpsrenew_send_failed_payment_email($clientId, $serviceId, $invoiceId, $reason, $source, array $config = [])
    {
        $enabled = ($config['notify_failed_payment'] ?? 'yes');
        $enabled = in_array(strtolower((string) $enabled), ['1', 'on', 'yes', 'true'], true);
        if (!$enabled) {
            return false;
        }

        $templateId = isset($config['failed_payment_email_template_id']) ? (int) $config['failed_payment_email_template_id'] : 70;
        if ($templateId <= 0) {
            return false;
        }

        $tpl = Capsule::table('tblemailtemplates')
            ->where('id', $templateId)
            ->first();
        if (!$tpl || empty($tpl->name)) {
            logActivity('VPS 自动续费扣款失败邮件发送跳过：模板ID不存在（id=' . $templateId . '）');
            return false;
        }

        $client = Capsule::table('tblclients')->where('id', (int) $clientId)->first();
        $service = Capsule::table('tblhosting')->where('id', (int) $serviceId)->first();
        $domain = $service ? (string) ($service->domain ?? '') : '';
        $nextDue = $service ? (string) ($service->nextduedate ?? '') : '';

        $customVars = base64_encode(serialize([
            'vpsrenew_service_id' => (int) $serviceId,
            'vpsrenew_service_domain' => $domain,
            'vpsrenew_invoice_id' => (int) $invoiceId,
            'vpsrenew_fail_reason' => (string) $reason,
            'vpsrenew_source' => (string) $source,
            'vpsrenew_next_due_date' => (string) $nextDue,
            'vpsrenew_client_email' => (string) ($client->email ?? ''),
        ]));

        $result = localAPI('SendEmail', [
            'messagename' => (string) $tpl->name,
            'id' => (int) $clientId,
            'customvars' => $customVars,
        ]);

        if (($result['result'] ?? '') !== 'success') {
            logActivity('VPS 自动续费扣款失败邮件发送失败：服务 #' . (int) $serviceId . '，账单 #' . (int) $invoiceId . '，模板 #' . $templateId . '，原因：' . ($result['message'] ?? '未知'));
            return false;
        }

        logActivity('VPS 自动续费扣款失败邮件已发送：服务 #' . (int) $serviceId . '，账单 #' . (int) $invoiceId . '，模板 #' . $templateId . '（source=' . $source . '）');
        return true;
    }
}

if (!function_exists('vpsrenew_split_ips')) {
    function vpsrenew_split_ips($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,;|]+/', $raw);
        $out = [];
        foreach ($parts as $part) {
            $ip = trim($part);
            if ($ip !== '') {
                $out[] = $ip;
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('vpsrenew_find_service_id_in_text')) {
    function vpsrenew_find_service_id_in_text($text)
    {
        $text = (string) $text;
        if (preg_match('/VPSRENEW:sid=(\d+)/', $text, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\[VPSRENEW:sid=(\d+);/', $text, $m)) {
            return (int) $m[1];
        }
        return 0;
    }
}

if (!function_exists('vpsrenew_get_invoice_service_map')) {
    function vpsrenew_get_invoice_service_map($clientId, array $invoiceIds)
    {
        $result = [];
        $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
        if (empty($invoiceIds)) {
            return $result;
        }

        $appendService = function (&$map, $invoiceId, array $service) {
            $invoiceId = (int) $invoiceId;
            if ($invoiceId <= 0 || empty($service['serviceid'])) {
                return;
            }

            if (!isset($map[$invoiceId])) {
                $map[$invoiceId] = [
                    'services' => [],
                ];
            } elseif (!isset($map[$invoiceId]['services']) || !is_array($map[$invoiceId]['services'])) {
                $map[$invoiceId]['services'] = [];
            }

            foreach ($map[$invoiceId]['services'] as $existing) {
                if ((int) ($existing['serviceid'] ?? 0) === (int) $service['serviceid']) {
                    return;
                }
            }

            $map[$invoiceId]['services'][] = $service;

            // 向后兼容：保留旧结构单服务字段，使用首个服务
            if (!isset($map[$invoiceId]['serviceid'])) {
                $map[$invoiceId]['serviceid'] = (int) $service['serviceid'];
                $map[$invoiceId]['title'] = (string) ($service['title'] ?? '');
                $map[$invoiceId]['ips'] = array_values(array_unique((array) ($service['ips'] ?? [])));
                $map[$invoiceId]['nextduedate'] = (string) ($service['nextduedate'] ?? '');
            }
        };

        $hostingRows = Capsule::table('tblinvoiceitems as ii')
            ->join('tblhosting as h', 'h.id', '=', 'ii.relid')
            ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->whereIn('ii.invoiceid', $invoiceIds)
            ->where('ii.type', 'Hosting')
            ->where('h.userid', (int) $clientId)
            ->select([
                'ii.invoiceid',
                'ii.relid as serviceid',
                'h.domain',
                'h.packageid',
                'h.dedicatedip',
                'h.assignedips',
                'p.name as product_name',
                'h.nextduedate',
            ])
            ->get();

        foreach ($hostingRows as $row) {
            $ips = array_merge(
                vpsrenew_split_ips($row->dedicatedip),
                vpsrenew_split_ips($row->assignedips)
            );
            $title = vpsrenew_build_service_title($row->domain, $row->product_name, $row->serviceid);
            $appendService($result, (int) $row->invoiceid, [
                'serviceid' => (int) $row->serviceid,
                'title' => $title,
                'domain' => (string) ($row->domain ?? ''),
                'product_name' => (string) ($row->product_name ?? ''),
                'packageid' => isset($row->packageid) ? (int) $row->packageid : 0,
                'ips' => array_values(array_unique($ips)),
                'nextduedate' => (string) $row->nextduedate,
            ]);
        }

        $missingIds = [];
        foreach ($invoiceIds as $id) {
            if (!isset($result[$id])) {
                $missingIds[] = $id;
            }
        }
        if (empty($missingIds)) {
            return $result;
        }

        $rawRows = Capsule::table('tblinvoiceitems')
            ->whereIn('invoiceid', $missingIds)
            ->orderBy('id', 'asc')
            ->select(['invoiceid', 'description'])
            ->get();

        $serviceIds = [];
        foreach ($rawRows as $row) {
            $invoiceId = (int) $row->invoiceid;
            $sid = vpsrenew_find_service_id_in_text($row->description);
            if ($sid > 0) {
                if (!isset($serviceIds[$invoiceId])) {
                    $serviceIds[$invoiceId] = [];
                }
                $serviceIds[$invoiceId][] = (int) $sid;
            }
        }

        // 新版本不再在账单项目描述里写 marker，改为从账单备注解析服务ID
        $noteRows = Capsule::table('tblinvoices')
            ->whereIn('id', $missingIds)
            ->where('userid', (int) $clientId)
            ->select(['id', 'notes'])
            ->get();
        foreach ($noteRows as $row) {
            $invoiceId = (int) $row->id;
            $sid = vpsrenew_find_service_id_in_text((string) $row->notes);
            if ($sid > 0) {
                if (!isset($serviceIds[$invoiceId])) {
                    $serviceIds[$invoiceId] = [];
                }
                $serviceIds[$invoiceId][] = (int) $sid;
            }
        }

        if (empty($serviceIds)) {
            return $result;
        }

        foreach ($serviceIds as $invoiceId => $ids) {
            $serviceIds[$invoiceId] = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
            if (empty($serviceIds[$invoiceId])) {
                unset($serviceIds[$invoiceId]);
            }
        }
        if (empty($serviceIds)) {
            return $result;
        }

        $services = Capsule::table('tblhosting as h')
            ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->whereIn('h.id', array_values(array_unique(array_merge(...array_values($serviceIds)))))
            ->where('h.userid', (int) $clientId)
            ->select(['h.id', 'h.domain', 'h.packageid', 'h.dedicatedip', 'h.assignedips', 'p.name as product_name', 'h.nextduedate'])
            ->get()
            ->keyBy('id');

        foreach ($serviceIds as $invoiceId => $ids) {
            foreach ($ids as $serviceId) {
                if (!isset($services[$serviceId])) {
                    continue;
                }
                $svc = $services[$serviceId];
                $ips = array_merge(
                    vpsrenew_split_ips($svc->dedicatedip),
                    vpsrenew_split_ips($svc->assignedips)
                );
                $title = vpsrenew_build_service_title($svc->domain, $svc->product_name, $serviceId);
                $appendService($result, (int) $invoiceId, [
                    'serviceid' => (int) $serviceId,
                    'title' => $title,
                    'domain' => (string) ($svc->domain ?? ''),
                    'product_name' => (string) ($svc->product_name ?? ''),
                    'packageid' => isset($svc->packageid) ? (int) $svc->packageid : 0,
                    'ips' => array_values(array_unique($ips)),
                    'nextduedate' => (string) $svc->nextduedate,
                ]);
            }
        }

        return $result;
    }
}

if (!function_exists('vpsrenew_split_multi_service_invoice')) {
    function vpsrenew_split_multi_service_invoice($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return [];
        }

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            return [];
        }

        // 新购订单发票不拆分，仅处理续费类账单
        if (!empty($invoice->orderid) && (int) $invoice->orderid > 0) {
            return [];
        }

        $notes = (string) ($invoice->notes ?? '');
        if (strpos($notes, 'VPSRENEW_SPLIT_CHILD') !== false || strpos($notes, 'VPSRENEW_SPLIT_PARENT') !== false) {
            return [];
        }

        $items = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->orderBy('id', 'asc')
            ->get();

        if ($items->count() < 2) {
            return [];
        }

        $hostingItems = [];
        $otherItems = [];
        foreach ($items as $item) {
            if ((string) $item->type === 'Hosting' && (int) $item->relid > 0) {
                $hostingItems[] = $item;
            } else {
                $otherItems[] = $item;
            }
        }

        // 仅当一张发票里存在2个及以上服务项时才拆分
        if (count($hostingItems) < 2) {
            return [];
        }

        $newInvoiceIds = [];
        $createInvoiceByItems = function (array $lineItems, $noteMarker) use ($invoiceId, $invoice, &$newInvoiceIds) {
            if (empty($lineItems)) {
                return;
            }

            $params = [
                'userid' => (int) $invoice->userid,
                'status' => 'Unpaid',
                'sendinvoice' => false,
                'paymentmethod' => (string) ($invoice->paymentmethod ?: 'mailin'),
                'date' => (string) $invoice->date,
                'duedate' => (string) $invoice->duedate,
            ];

            $index = 1;
            foreach ($lineItems as $line) {
                $params['itemdescription' . $index] = (string) $line->description;
                $params['itemamount' . $index] = number_format((float) $line->amount, 2, '.', '');
                $params['itemtaxed' . $index] = ((string) $line->taxed === '1' || (string) $line->taxed === 'true') ? true : false;
                $index++;
            }

            $result = localAPI('CreateInvoice', $params);
            if (($result['result'] ?? '') !== 'success') {
                throw new Exception('拆分账单创建失败: ' . ($result['message'] ?? '未知错误'));
            }

            $newId = (int) $result['invoiceid'];
            $newInvoiceIds[] = $newId;

            $childNotes = trim((string) Capsule::table('tblinvoices')->where('id', $newId)->value('notes'));
            Capsule::table('tblinvoices')->where('id', $newId)->update([
                'notes' => trim($childNotes . "\n" . $noteMarker . ';source=' . $invoiceId),
            ]);
        };

        foreach ($hostingItems as $idx => $item) {
            $createInvoiceByItems([$item], 'VPSRENEW_SPLIT_CHILD:serviceid=' . (int) $item->relid . ';part=' . ($idx + 1));
        }

        // 非服务项（如域名/附加服务等）单独汇总到一张发票，避免与机器续费关联
        if (!empty($otherItems)) {
            $createInvoiceByItems($otherItems, 'VPSRENEW_SPLIT_MISC');
        }

        Capsule::table('tblinvoices')->where('id', $invoiceId)->update([
            'status' => 'Cancelled',
            'notes' => trim($notes . "\n" . 'VPSRENEW_SPLIT_PARENT:children=' . implode(',', $newInvoiceIds)),
        ]);

        return $newInvoiceIds;
    }
}
