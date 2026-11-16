<?php
declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Audit\AuditLog;

final class ReportController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('reports.view');
        $this->requireFeature('reports');
        $tenantId = $this->tenantId();
        $outletId = $this->scopeOutlet();
        $to = (string) ($_GET['to'] ?? dt_local(now_utc(), null, 'Y-m-d'));
        $from = (string) ($_GET['from'] ?? dt_local(gmdate('Y-m-d H:i:s', time() - 13 * 86400), null, 'Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $from = dt_local(now_utc(), null, 'Y-m-d');
            $to = $from;
        }
        $kpi = ReportService::kpi($tenantId, $outletId, $from, $to);
        $rows = ReportService::dailyRows($tenantId, $outletId, $from, $to);
        $top = ReportService::topProducts($tenantId, $outletId, 30, 10);
        $mix = ReportService::paymentMix($tenantId, $outletId, 30);
        $hourly = ReportService::hourly($tenantId, $outletId);
        $outlets = Database::all('SELECT id, name FROM outlets WHERE tenant_id = ? ORDER BY name', [$tenantId]);
        $byOutlet = ReportService::salesByOutlet($tenantId, $from, $to);
        $shifts = Database::all(
            'SELECT s.*, u.name AS user_name, r.name AS register_name FROM cash_shifts s
             JOIN users u ON u.id = s.user_id JOIN registers r ON r.id = s.register_id
             WHERE s.tenant_id = ? AND s.status = "closed" ORDER BY s.id DESC LIMIT 10',
            [$tenantId]
        );
        $this->view('reports/index', [
            'title' => 'Laporan',
            'kpi' => $kpi,
            'rows' => $rows,
            'top' => $top,
            'mix' => $mix,
            'hourly' => $hourly,
            'outlets' => $outlets,
            'byOutlet' => $byOutlet,
            'from' => $from,
            'to' => $to,
            'shifts' => $shifts,
        ]);
    }

    public function export(): void
    {
        $this->requirePermission('reports.view');
        $tenantId = $this->tenantId();
        $outletId = (int) ($_GET['outlet_id'] ?? 0) ?: null;
        $from = (string) ($_GET['from'] ?? dt_local(now_utc(), null, 'Y-m-d'));
        $to = (string) ($_GET['to'] ?? $from);
        $rows = ReportService::dailyRows($tenantId, $outletId, $from, $to);
        AuditLog::log('report_exported', 'report', null, null, ['from' => $from, 'to' => $to]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="laporan-penjualan-' . $from . '-' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Tanggal', 'Transaksi', 'Subtotal', 'Diskon', 'Pajak', 'Omzet']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['sales_date'], $row['orders'], $row['subtotal'], $row['discounts'], $row['taxes'], $row['revenue'],
            ]);
        }
        fclose($out);
        exit;
    }

    public function dashboardData(): void
    {
        $this->requirePermission('reports.view');
        $tenantId = $this->tenantId();
        $outletId = $this->scopeOutlet();
        $days = max(7, min(90, (int) ($_GET['days'] ?? 14)));
        $this->json([
            'series' => ReportService::series($tenantId, $outletId, $days),
            'top' => ReportService::topProducts($tenantId, $outletId, 30, 8),
            'mix' => ReportService::paymentMix($tenantId, $outletId, 30),
            'hourly' => ReportService::hourly($tenantId, $outletId),
        ]);
    }

    private function scopeOutlet(): ?int
    {
        $role = (string) (Auth::user()['role_code'] ?? '');
        return in_array($role, ['cashier', 'waiter', 'kitchen'], true) ? (Auth::outletId() ?: null) : null;
    }
}
