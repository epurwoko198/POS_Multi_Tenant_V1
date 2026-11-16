<?php
declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $tenantId = $this->tenantId();
        if (Auth::isSuperAdmin()) {
            $this->redirect('/admin');
        }
        $outletId = $this->scopeOutlet();
        $today = dt_local(now_utc(), null, 'Y-m-d');
        $yesterday = dt_local(gmdate('Y-m-d H:i:s', time() - 86400), null, 'Y-m-d');
        $kpiToday = ReportService::kpi($tenantId, $outletId, $today, $today);
        $kpiYesterday = ReportService::kpi($tenantId, $outletId, $yesterday, $yesterday);
        $ctx = \App\Core\License::context();
        $lowStock = ReportService::lowStock($tenantId, $outletId ?: Auth::outletId(), 6);
        $recent = Database::all(
            'SELECT o.public_id, o.order_no, o.grand_total, o.status, o.order_type, o.created_at, u.name AS cashier
             FROM sales_orders o JOIN users u ON u.id = o.cashier_id
             WHERE o.tenant_id = ?' . ($outletId ? ' AND o.outlet_id = ?' : '') . '
             ORDER BY o.id DESC LIMIT 8',
            $outletId ? [$tenantId, $outletId] : [$tenantId]
        );
        $top = ReportService::topProducts($tenantId, $outletId, 30, 6);
        $mix = ReportService::paymentMix($tenantId, $outletId, 30);
        $series = ReportService::series($tenantId, $outletId, 14);
        $outletCompare = ReportService::salesByOutlet($tenantId, dt_local(gmdate('Y-m-d H:i:s', time() - 30 * 86400), null, 'Y-m-d'), $today);
        $kds = \App\Core\License::enabled('kds')
            ? Database::one("SELECT COUNT(*) AS c FROM kitchen_tickets WHERE tenant_id = ? AND status IN ('queued','preparing')", [$tenantId])
            : ['c' => 0];
        $shift = Database::one(
            "SELECT * FROM cash_shifts WHERE tenant_id = ? AND user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
            [$tenantId, Auth::id()]
        );
        $growth = (float) $kpiYesterday['revenue'] > 0
            ? (($kpiToday['revenue'] - $kpiYesterday['revenue']) / (float) $kpiYesterday['revenue']) * 100
            : 0;

        $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'kpi' => $kpiToday,
            'kpiYesterday' => $kpiYesterday,
            'growth' => $growth,
            'series' => $series,
            'top' => $top,
            'mix' => $mix,
            'recent' => $recent,
            'lowStock' => $lowStock,
            'outletCompare' => $outletCompare,
            'context' => $ctx,
            'pendingKds' => (int) ($kds['c'] ?? 0),
            'shift' => $shift,
            'outletId' => $outletId,
            'licenseNotice' => \App\Core\License::expiryNotice(),
        ]);
    }

    private function scopeOutlet(): ?int
    {
        $role = (string) (Auth::user()['role_code'] ?? '');
        if (in_array($role, ['cashier', 'waiter', 'kitchen'], true)) {
            return Auth::outletId() ?: null;
        }
        return null;
    }
}
