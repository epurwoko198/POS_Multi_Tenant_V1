<?php
declare(strict_types=1);

namespace App\Modules\POS;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Audit\AuditLog;

final class CashController extends Controller
{
    public function index(): void
    {
        $tenantId = $this->tenantId();
        $outletId = $this->outletId();
        $shift = Database::one(
            "SELECT s.*, u.name AS user_name, r.name AS register_name FROM cash_shifts s
             JOIN users u ON u.id = s.user_id JOIN registers r ON r.id = s.register_id
             WHERE s.tenant_id = ? AND s.user_id = ? AND s.status = 'open' ORDER BY s.id DESC LIMIT 1",
            [$tenantId, Auth::id()]
        );
        $movements = $shift
            ? Database::all('SELECT * FROM cash_movements WHERE shift_id = ? ORDER BY id DESC', [(int) $shift['id']])
            : [];
        $cashSales = $shift ? (float) Database::value(
            "SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN sales_orders o ON o.id = p.order_id
             WHERE o.shift_id = ? AND p.method = 'cash' AND p.status IN ('paid','settled')",
            [(int) $shift['id']],
            0
        ) : 0;
        $nonCash = $shift ? (float) Database::value(
            "SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN sales_orders o ON o.id = p.order_id
             WHERE o.shift_id = ? AND p.method <> 'cash' AND p.status IN ('paid','settled')",
            [(int) $shift['id']],
            0
        ) : 0;
        $shifts = Database::all(
            'SELECT s.*, u.name AS user_name, r.name AS register_name FROM cash_shifts s
             JOIN users u ON u.id = s.user_id JOIN registers r ON r.id = s.register_id
             WHERE s.tenant_id = ? AND s.outlet_id = ? ORDER BY s.id DESC LIMIT 12',
            [$tenantId, $outletId]
        );
        $registers = Database::all('SELECT id, code, name FROM registers WHERE tenant_id = ? AND outlet_id = ? AND is_active = 1', [$tenantId, $outletId]);
        $this->view('pos/cash', [
            'title' => 'Kas & Shift',
            'shift' => $shift,
            'movements' => $movements,
            'cashSales' => $cashSales,
            'nonCash' => $nonCash,
            'shifts' => $shifts,
            'registers' => $registers,
        ]);
    }

    public function open(): void
    {
        $tenantId = $this->tenantId();
        $outletId = $this->outletId();
        $existing = Database::one(
            "SELECT id FROM cash_shifts WHERE tenant_id = ? AND user_id = ? AND status = 'open'",
            [$tenantId, Auth::id()]
        );
        if ($existing) {
            flash('error', 'Anda masih memiliki shift yang terbuka.');
            $this->redirect('/cash');
        }
        $registerId = $this->intInput('register_id');
        $register = Database::one('SELECT id FROM registers WHERE id = ? AND tenant_id = ? AND outlet_id = ?', [$registerId, $tenantId, $outletId]);
        if (!$register) {
            flash('error', 'Register tidak valid.');
            $this->redirect('/cash');
        }
        $float = max(0, $this->floatInput('opening_float'));
        $id = Database::insert('cash_shifts', [
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'register_id' => $registerId,
            'user_id' => Auth::id(),
            'opening_float' => $float,
            'status' => 'open',
            'opened_at' => now_utc(),
        ]);
        AuditLog::log('shift_opened', 'cash_shift', (string) $id, null, ['opening_float' => $float]);
        flash('success', 'Shift dibuka dengan modal awal ' . rupiah($float) . '.');
        $this->redirect('/pos');
    }

    public function close(): void
    {
        $tenantId = $this->tenantId();
        $shift = Database::one(
            "SELECT * FROM cash_shifts WHERE tenant_id = ? AND user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
            [$tenantId, Auth::id()]
        );
        if (!$shift) {
            flash('error', 'Tidak ada shift terbuka.');
            $this->redirect('/cash');
        }
        $cashSales = (float) Database::value(
            "SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN sales_orders o ON o.id = p.order_id
             WHERE o.shift_id = ? AND p.method = 'cash' AND p.status IN ('paid','settled')",
            [(int) $shift['id']],
            0
        );
        $cashIn = (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE shift_id = ? AND type = 'in'", [(int) $shift['id']], 0);
        $cashOut = (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE shift_id = ? AND type IN ('out','safe_drop')", [(int) $shift['id']], 0);
        $expected = round((float) $shift['opening_float'] + $cashSales + $cashIn - $cashOut, 2);
        $counted = max(0, $this->floatInput('closing_count'));
        $variance = round($counted - $expected, 2);
        Database::execute(
            "UPDATE cash_shifts SET closing_count = ?, expected_cash = ?, variance = ?, status = 'closed', closed_at = ?, notes = ? WHERE id = ?",
            [$counted, $expected, $variance, now_utc(), substr((string) $this->input('notes', ''), 0, 250), (int) $shift['id']]
        );
        AuditLog::log('shift_closed', 'cash_shift', (string) $shift['id'], null, [
            'expected' => $expected, 'counted' => $counted, 'variance' => $variance,
        ]);
        flash($variance === 0.0 ? 'success' : 'warning', 'Shift ditutup. Selisih kas: ' . rupiah($variance) . '.');
        $this->redirect('/cash');
    }

    public function movement(): void
    {
        $tenantId = $this->tenantId();
        $shift = Database::one(
            "SELECT * FROM cash_shifts WHERE tenant_id = ? AND user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
            [$tenantId, Auth::id()]
        );
        if (!$shift) {
            flash('error', 'Buka shift terlebih dahulu.');
            $this->redirect('/cash');
        }
        $type = (string) $this->input('type', 'in');
        if (!in_array($type, ['in', 'out', 'safe_drop'], true)) {
            flash('error', 'Jenis mutasi tidak valid.');
            $this->redirect('/cash');
        }
        $amount = $this->floatInput('amount');
        if ($amount <= 0) {
            flash('error', 'Nominal mutasi harus lebih dari 0.');
            $this->redirect('/cash');
        }
        Database::insert('cash_movements', [
            'tenant_id' => $tenantId,
            'shift_id' => (int) $shift['id'],
            'type' => $type,
            'amount' => $amount,
            'reason' => substr((string) $this->input('reason', ''), 0, 150),
            'created_by' => Auth::id(),
            'created_at' => now_utc(),
        ]);
        AuditLog::log('cash_movement', 'cash_shift', (string) $shift['id'], null, ['type' => $type, 'amount' => $amount]);
        flash('success', 'Mutasi kas dicatat.');
        $this->redirect('/cash');
    }
}
