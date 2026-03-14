# VPS Renew（WHMCS 续费插件）

## 简介
VPS Renew 用于在 WHMCS 产品详情页提供“按月数快速续费”能力，支持 1-36 个月续费和阶梯折扣。

## 功能
- 自由选择续费周期（1-36 个月）
- 阶梯折扣（默认：3个月100折、6个月80折、12个月80折）
- 前端实时显示续费价格
- 一键创建发票并跳转支付页
- 支持 `six` 与 `twenty-one` 主题

## 文件结构
```text
vpsrenew/
├── vpsrenew.php   # 模块主文件（配置、激活、后台页面）
├── hooks.php      # 产品详情页数据注入
├── renewal.php    # 续费请求处理
├── README.md
└── INSTALL.md     # 仅保留跳转说明
```

## 安装与激活
1. 将插件放到 `whmcs/modules/addons/vpsrenew`。
2. 登录 WHMCS 后台，进入 `Setup -> Addon Modules`。
3. 找到 `VPS 续费模块`，点击 `Activate`。
4. 点击 `Configure` 并保存一次配置（会写入 `tbladdonmodules`）。

## 配置项说明
- `enable_discount`：是否启用折扣
- `discount_3months`：3-5 个月折扣百分比
- `discount_6months`：6-11 个月折扣百分比
- `discount_12months`：12 个月及以上折扣百分比
- `max_months`：最大可续费月数（默认 36）
- `notify_failed_payment`：扣款失败时是否发邮件通知（默认开启）
- `failed_payment_email_template_id`：扣款失败邮件模板ID（默认 `70`）

## 使用流程
1. 客户进入 `Client Area -> My Services -> Product Details`。
2. 在左侧操作区看到“快速续费”卡片。
3. 选择月数后点击“立即续费”。
4. 系统创建发票并跳转 `viewinvoice.php`。

## 自动续费（独立 Cron）
- 自动续费已改为独立脚本执行：`modules/addons/vpsrenew/cron_autorenew.php`
- 自动续费管理页支持两种模式：
  - `按月续费`：固定按 1 个月续费
  - `按周期续费`：按服务账单周期自动换算（月付1、季付3、半年6、年付12、两年24、三年36，默认模式）
- 建议计划任务（建议放在WHMCS cron周期之前，如每天 01:00）：
```bash
0 1 * * * /usr/bin/php -q /path/to/whmcs/modules/addons/vpsrenew/cron_autorenew.php
```
- 扣款失败通知：
  - 当自动续费创建账单后扣款失败（余额不足或 ApplyCredit 失败）会触发邮件通知。
  - 默认使用邮件模板 ID `70`（可在模块配置中修改）。
  - 可在模板中使用自定义变量：
    - `{$vpsrenew_service_id}`
    - `{$vpsrenew_service_domain}`
    - `{$vpsrenew_invoice_id}`
    - `{$vpsrenew_fail_reason}`
    - `{$vpsrenew_source}`
    - `{$vpsrenew_next_due_date}`
- 如需继续走 `DailyCronJob`（不推荐），可在 `configuration.php` 加：
```php
define('VPSRENEW_ENABLE_DAILY_AUTORENEW', true);
```

### 按月/按周期续费测试脚本
- 脚本：`modules/addons/vpsrenew/test_renew_mode.sh`
- 用法：
```bash
./test_renew_mode.sh --service-id 42
```
- 可选参数：
  - `--whmcs-root /path/to/whmcs`
  - `--allow-unpaid`（默认遇到未支付续费账单会终止）
  - `--cancel-test-invoices`（仅在需要时取消本次测试账单；默认保留测试账单）

## 价格逻辑
1. 优先使用产品月付价。
2. 若无月付价，依次用季付/3、半年/6、年付/12推算月价。
3. 月价乘以续费月数。
4. 根据月数应用折扣。

## 常见问题
### 1) 页面不显示续费按钮
- 确认模块已激活且保存过配置（`tbladdonmodules` 有 `vpsrenew` 记录）。
- 确认产品有价格配置。
- 清理模板缓存：`rm -rf whmcs/templates_c/*`。
- 确认主题模板包含 `$vpsHasRenewalOptions` 渲染代码。

### 2) 点击续费报 `viewinvoice.php not found`
- 原因通常是相对路径跳转错误。
- 确认 `renewal.php` 中跳转为根路径（例如 `/viewinvoice.php?id=xx`）。

### 3) 点击续费报 `init.php` 找不到
- 确认插件目录路径正确，且 `renewal.php` 能正确加载 WHMCS 根目录下的 `init.php`。

## 兼容性
- WHMCS: 7.x / 8.x
- PHP: 7.2+

## License
MIT
