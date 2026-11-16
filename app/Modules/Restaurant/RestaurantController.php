<?php
declare(strict_types=1);

namespace App\Modules\Restaurant;

use App\Core\Controller;
use App\Core\Database;
use App\Core\License;
use App\Modules\Audit\AuditLog;

final class RestaurantController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('restaurant.manage');
        $this->requireFeature('restaurant');
        $tenantId = $this->tenantId();
        $outletId = $this->outletId();
        $tables = Database::all(
            'SELECT t.*, o.order_no, o.grand_total, o.status AS order_status
             FROM dining_tables t
             LEFT JOIN sales_orders o ON o.id = (
                SELECT id FROM sales_orders WHERE table_id = t.id AND status IN ("awaiting_payment","completed","paid") ORDER BY id DESC LIMIT 1
             )
             WHERE t.tenant_id = ? AND t.outlet_id = ? ORDER BY t.area, t.name',
            [$tenantId, $outletId]
        );
        $areas = Database::all(
            'SELECT area, COUNT(*) AS total FROM dining_tables WHERE tenant_id = ? AND outlet_id = ? GROUP BY area',
            [$tenantId, $outletId]
        );
        $this->view('restaurant/tables', [
            'title' => 'Meja & Reservasi',
            'tables' => $tables,
            'areas' => $areas,
        ]);
    }

    public function storeTable(): void
    {
        $this->requirePermission('restaurant.manage');
        $name = trim((string) $this->input('name', ''));
        if ($name === '') {
            flash('error', 'Nama meja wajib diisi.');
            $this->redirect('/restaurant');
        }
        Database::insert('dining_tables', [
            'tenant_id' => $this->tenantId(),
            'outlet_id' => $this->outletId(),
            'name' => $name,
            'area' => trim((string) $this->input('area', 'Main')) ?: 'Main',
            'capacity' => max(1, $this->intInput('capacity', 4)),
            'status' => 'free',
            'created_at' => now_utc(),
        ]);
        AuditLog::log('table_created', 'dining_table', null, null, ['name' => $name]);
        flash('success', 'Meja ditambahkan.');
        $this->redirect('/restaurant');
    }

    public function setStatus(string $id): void
    {
        $this->requirePermission('restaurant.manage');
        $status = (string) $this->input('status', 'free');
        if (!in_array($status, ['free', 'occupied', 'reserved'], true)) {
            flash('error', 'Status meja tidak valid.');
            $this->redirect('/restaurant');
        }
        Database::execute('UPDATE dining_tables SET status = ? WHERE id = ? AND tenant_id = ?', [$status, (int) $id, $this->tenantId()]);
        AuditLog::log('table_status', 'dining_table', $id, null, ['status' => $status]);
        $this->redirect('/restaurant');
    }

    public function kds(): void
    {
        $this->requirePermission('kitchen.view');
        $this->requireFeature('kds');
        $this->view('restaurant/kds', ['title' => 'Kitchen Display']);
    }

    public function kdsData(): void
    {
        $this->requirePermission('kitchen.view');
        $tenantId = $this->tenantId();
        $tickets = Database::all(
            'SELECT kt.*, o.order_no, o.order_type, t.name AS table_name
             FROM kitchen_tickets kt
             JOIN sales_orders o ON o.id = kt.order_id
             LEFT JOIN dining_tables t ON t.id = o.table_id
             WHERE kt.tenant_id = ? AND kt.status IN ("queued","preparing","ready")
             ORDER BY kt.id LIMIT 40',
            [$tenantId]
        );
        $ids = array_map(static fn ($t) => (int) $t['id'], $tickets);
        $items = [];
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $items = Database::all("SELECT * FROM kitchen_ticket_items WHERE ticket_id IN ($placeholders) ORDER BY id", $ids);
        }
        $byTicket = [];
        foreach ($items as $item) {
            $byTicket[(int) $item['ticket_id']][] = $item;
        }
        foreach ($tickets as &$ticket) {
            $ticket['items'] = $byTicket[(int) $ticket['id']] ?? [];
            $ticket['age_minutes'] = max(0, (int) floor((time() - strtotime($ticket['created_at'] . ' UTC')) / 60));
        }
        unset($ticket);
        $this->json(['ok' => true, 'tickets' => $tickets, 'time' => now_utc()]);
    }

    public function kdsStatus(string $id): void
    {
        $this->requirePermission('kitchen.view');
        $payload = $this->payload();
        $status = (string) ($payload['status'] ?? '');
        if (!in_array($status, ['queued', 'preparing', 'ready', 'served'], true)) {
            $this->json(['ok' => false, 'message' => 'Status tidak valid.'], 422);
        }
        $ticket = Database::one('SELECT * FROM kitchen_tickets WHERE id = ? AND tenant_id = ?', [(int) $id, $this->tenantId()]);
        if (!$ticket) {
            $this->json(['ok' => false, 'message' => 'Tiket tidak ditemukan.'], 404);
        }
        Database::execute('UPDATE kitchen_tickets SET status = ?, updated_at = ? WHERE id = ?', [$status, now_utc(), (int) $id]);
        AuditLog::log('kds_status', 'kitchen_ticket', $id, ['status' => $ticket['status']], ['status' => $status]);
        $this->json(['ok' => true, 'status' => $status]);
    }
}
