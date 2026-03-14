<?php
/**
 * VPS Renew - WHMCS Addon Module
 * 允许客户自由选择 1-36 个月续费周期 + 自动续费管理
 *
 * @package VPSRenew
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib_vpsrenew.php';

/**
 * 模块配置
 */
function vpsrenew_config()
{
    return [
        'name' => 'VPS 续费模块',
        'description' => '支持手动续费、自动续费、批量折扣与自动续费管理页面',
        'version' => '1.1.0',
        'author' => 'NextCLi.com',
        'language' => 'chinese',
        'fields' => [
            'enable_discount' => [
                'FriendlyName' => '启用批量折扣',
                'Type' => 'yesno',
                'Description' => '勾选后启用续费折扣：3个月100折，6个月80折，12个月80折',
                'Default' => 'yes',
            ],
            'discount_3months' => [
                'FriendlyName' => '3个月折扣（%）',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '100',
                'Description' => '3-5个月续费折扣百分比（0-100）',
            ],
            'discount_6months' => [
                'FriendlyName' => '6个月折扣（%）',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '80',
                'Description' => '6-11个月续费折扣百分比（0-100）',
            ],
            'discount_12months' => [
                'FriendlyName' => '12个月折扣（%）',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '80',
                'Description' => '12个月及以上续费折扣百分比（0-100）',
            ],
            'max_months' => [
                'FriendlyName' => '最大续费月数',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '36',
                'Description' => '允许的最大续费月数（1-36）',
            ],
            'split_multi_service_invoice' => [
                'FriendlyName' => '拆分多服务账单',
                'Type' => 'yesno',
                'Description' => '当同一张发票包含多个服务器服务项时自动拆分为独立发票',
                'Default' => 'yes',
            ],
            'notify_failed_payment' => [
                'FriendlyName' => '扣款失败邮件通知',
                'Type' => 'yesno',
                'Description' => '扣款失败时给客户发送邮件通知',
                'Default' => 'yes',
            ],
            'failed_payment_email_template_id' => [
                'FriendlyName' => '扣款失败邮件模板ID',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '70',
                'Description' => '当自动续费扣款失败时发送此模板（默认70）',
            ],
        ],
    ];
}

/**
 * 模块激活
 */
function vpsrenew_activate()
{
    try {
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
        }

        return [
            'status' => 'success',
            'description' => 'VPS 续费模块已激活，自动续费数据表已初始化。',
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'description' => '激活失败：' . $e->getMessage(),
        ];
    }
}

/**
 * 模块停用
 */
function vpsrenew_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'VPS 续费模块已停用（数据表保留）。',
    ];
}

/**
 * 模块升级
 */
function vpsrenew_upgrade($vars)
{
    $currentVersion = $vars['version'] ?? '1.0.0';

    if (version_compare($currentVersion, '1.1.0', '<')) {
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
    }
    if (!Capsule::schema()->hasColumn(VPSRENEW_AUTORENEW_TABLE, 'renew_mode')) {
        Capsule::schema()->table(VPSRENEW_AUTORENEW_TABLE, function ($table) {
            $table->string('renew_mode', 16)->default('cycle')->after('months');
        });
    }
}

/**
 * 管理员界面输出
 */
function vpsrenew_output($vars)
{
    echo '<div class="infobox">';
    echo '<h2>VPS 续费模块</h2>';
    echo '<p>此模块已启用并正常运行。</p>';
    echo '<h3>功能说明：</h3>';
    echo '<ul>';
    echo '<li>✅ 支持 1-36 个月自由选择续费周期</li>';
    echo '<li>✅ 自动计算价格并应用折扣</li>';
    echo '<li>✅ 产品详情页快速续费弹窗</li>';
    echo '<li>✅ 自动续费管理页（autocreditlist）</li>';
    echo '<li>✅ 到期自动扣余额续费（有未支付账单则跳过）</li>';
    echo '</ul>';

    try {
        $enabledCount = Capsule::table(VPSRENEW_AUTORENEW_TABLE)->where('enabled', 1)->count();
        echo '<p><strong>自动续费开启服务数：</strong> ' . (int) $enabledCount . '</p>';
    } catch (Exception $e) {
        echo '<p>自动续费统计读取失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    }

    echo '<p><strong>模块版本：</strong> ' . htmlspecialchars((string) ($vars['version'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</div>';
}

/**
 * 侧边栏输出（保留）
 */
function vpsrenew_sidebar($vars)
{
}
