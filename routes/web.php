<?php
declare(strict_types=1);

use App\Modules\AI\AiController;
use App\Modules\Catalog\CatalogController;
use App\Modules\CRM\CrmController;
use App\Modules\Identity\AuthController;
use App\Modules\Identity\UserController;
use App\Modules\Integrations\ApiController;
use App\Modules\Inventory\InventoryController;
use App\Modules\POS\CashController;
use App\Modules\POS\PosController;
use App\Modules\Purchasing\PurchasingController;
use App\Modules\Reports\DashboardController;
use App\Modules\Reports\ReportController;
use App\Modules\Restaurant\RestaurantController;
use App\Modules\SaaS\AdminController;
use App\Modules\SaaS\LicenseController;
use App\Modules\SaaS\SettingsController;

/** @var \App\Core\Router $router */

$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);
$router->get('/profile', [AuthController::class, 'profile'], ['auth']);
$router->post('/profile', [AuthController::class, 'updateProfile'], ['auth', 'csrf']);

$router->get('/', [DashboardController::class, 'index'], ['auth']);
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth', 'license']);

$router->get('/pos/dashboard', [PosController::class, 'dashboard'], ['auth', 'license']);
$router->get('/pos', [PosController::class, 'index'], ['auth', 'license']);
$router->post('/pos/checkout', [PosController::class, 'checkout'], ['auth', 'license', 'csrf']);
$router->get('/pos/receipt/{id}', [PosController::class, 'receipt'], ['auth']);
$router->post('/pos/print/{id}', [PosController::class, 'printReceipt'], ['auth', 'license', 'csrf']);
$router->post('/pos/cancel/{id}', [PosController::class, 'cancel'], ['auth', 'license', 'csrf']);

$router->get('/cash', [CashController::class, 'index'], ['auth', 'license']);
$router->post('/cash/open', [CashController::class, 'open'], ['auth', 'license', 'csrf']);
$router->post('/cash/close', [CashController::class, 'close'], ['auth', 'license', 'csrf']);
$router->post('/cash/movement', [CashController::class, 'movement'], ['auth', 'license', 'csrf']);

$router->get('/catalog', [CatalogController::class, 'index'], ['auth', 'license']);
$router->post('/catalog/product', [CatalogController::class, 'store'], ['auth', 'license', 'csrf']);
$router->post('/catalog/category', [CatalogController::class, 'category'], ['auth', 'license', 'csrf']);

$router->get('/inventory', [InventoryController::class, 'index'], ['auth', 'license']);
$router->post('/inventory/adjust', [InventoryController::class, 'adjust'], ['auth', 'license', 'csrf']);
$router->post('/inventory/transfer', [InventoryController::class, 'transfer'], ['auth', 'license', 'csrf']);

$router->get('/purchasing', [PurchasingController::class, 'index'], ['auth', 'license']);
$router->post('/purchasing/supplier', [PurchasingController::class, 'supplier'], ['auth', 'license', 'csrf']);
$router->post('/purchasing/order', [PurchasingController::class, 'storeOrder'], ['auth', 'license', 'csrf']);
$router->post('/purchasing/receive/{id}', [PurchasingController::class, 'receive'], ['auth', 'license', 'csrf']);

$router->get('/restaurant', [RestaurantController::class, 'index'], ['auth', 'license']);
$router->post('/restaurant/table', [RestaurantController::class, 'storeTable'], ['auth', 'license', 'csrf']);
$router->post('/restaurant/table/{id}/status', [RestaurantController::class, 'setStatus'], ['auth', 'license', 'csrf']);

$router->get('/kds', [RestaurantController::class, 'kds'], ['auth', 'license']);
$router->get('/api/kds/tickets', [RestaurantController::class, 'kdsData'], ['auth', 'license']);
$router->post('/api/kds/{id}/status', [RestaurantController::class, 'kdsStatus'], ['auth', 'license', 'csrf']);

$router->get('/crm', [CrmController::class, 'index'], ['auth', 'license']);
$router->post('/crm/customer', [CrmController::class, 'store'], ['auth', 'license', 'csrf']);

$router->get('/reports', [ReportController::class, 'index'], ['auth', 'license']);
$router->get('/reports/export', [ReportController::class, 'export'], ['auth']);
$router->get('/api/reports/dashboard', [ReportController::class, 'dashboardData'], ['auth', 'license']);

$router->get('/ai', [AiController::class, 'index'], ['auth', 'license']);
$router->get('/api/ai/{action}', [AiController::class, 'api'], ['auth', 'license']);
$router->post('/api/ai/ask', [AiController::class, 'ask'], ['auth', 'license', 'csrf']);

$router->get('/settings', [SettingsController::class, 'index'], ['auth', 'license']);
$router->post('/settings', [SettingsController::class, 'update'], ['auth', 'license', 'csrf']);
$router->post('/settings/outlet', [SettingsController::class, 'storeOutlet'], ['auth', 'license', 'csrf']);
$router->post('/settings/token', [SettingsController::class, 'createToken'], ['auth', 'license', 'csrf']);
$router->post('/settings/token/{id}/revoke', [SettingsController::class, 'revokeToken'], ['auth', 'license', 'csrf']);

$router->get('/users', [UserController::class, 'index'], ['auth', 'license']);
$router->post('/users', [UserController::class, 'store'], ['auth', 'license', 'csrf']);
$router->post('/users/{id}/toggle', [UserController::class, 'toggle'], ['auth', 'license', 'csrf']);
$router->post('/users/{id}/reset', [UserController::class, 'reset'], ['auth', 'license', 'csrf']);

$router->get('/license', [LicenseController::class, 'index'], ['auth']);
$router->get('/billing', [LicenseController::class, 'index'], ['auth']);
$router->post('/license/activate', [LicenseController::class, 'activate'], ['auth', 'csrf']);

// ---------------------------------------------------------------- SaaS admin
$router->get('/admin', [AdminController::class, 'index'], ['auth', 'super']);
$router->get('/admin/tenants', [AdminController::class, 'tenants'], ['auth', 'super']);
$router->post('/admin/tenants', [AdminController::class, 'storeTenant'], ['auth', 'super', 'csrf']);
$router->get('/admin/tenants/{id}', [AdminController::class, 'tenantDetail'], ['auth', 'super']);
$router->post('/admin/tenants/{id}/features', [AdminController::class, 'setFeatures'], ['auth', 'super', 'csrf']);
$router->get('/admin/plans', [AdminController::class, 'plans'], ['auth', 'super']);
$router->post('/admin/plans/{id}', [AdminController::class, 'savePlan'], ['auth', 'super', 'csrf']);
$router->get('/admin/licenses', [AdminController::class, 'licenses'], ['auth', 'super']);
$router->get('/admin/licenses/{id}', [AdminController::class, 'licenseDetail'], ['auth', 'super']);
$router->post('/admin/licenses', [AdminController::class, 'issueLicense'], ['auth', 'super', 'csrf']);
$router->post('/admin/licenses/{id}/revoke', [AdminController::class, 'revokeLicense'], ['auth', 'super', 'csrf']);
$router->get('/admin/invoices', [AdminController::class, 'invoices'], ['auth', 'super']);
$router->post('/admin/invoices', [AdminController::class, 'storeInvoice'], ['auth', 'super', 'csrf']);
$router->post('/admin/invoices/{id}/paid', [AdminController::class, 'payInvoice'], ['auth', 'super', 'csrf']);
$router->get('/admin/mail', [AdminController::class, 'mail'], ['auth', 'super']);
$router->post('/admin/mail', [AdminController::class, 'saveMail'], ['auth', 'super', 'csrf']);
$router->post('/admin/mail/test', [AdminController::class, 'testMail'], ['auth', 'super', 'csrf']);
$router->get('/admin/audit', [AdminController::class, 'audit'], ['auth', 'super']);

$router->get('/api/payments/{id}/status', [PosController::class, 'paymentStatus'], ['auth']);
$router->post('/api/payments/{id}/simulate', [PosController::class, 'simulatePaid'], ['auth', 'csrf']);
