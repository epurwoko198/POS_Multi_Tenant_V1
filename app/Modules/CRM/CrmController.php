<?php
declare(strict_types=1);

namespace App\Modules\CRM;

use App\Core\Controller;
use App\Core\Database;
use App\Core\License;
use App\Modules\Audit\AuditLog;

final class CrmController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('crm.manage');
        $this->requireFeature('crm');
        $tenantId = $this->tenantId();
        $customers = Database::all(
            'SELECT c.*, (SELECT COUNT(*) FROM sales_orders o WHERE o.customer_id = c.id AND o.status IN ("paid","completed","partially_refunded")) AS orders
             FROM customers c WHERE c.tenant_id = ? ORDER BY c.total_spent DESC LIMIT 300',
            [$tenantId]
        );
        $loyalty = License::enabled('loyalty')
            ? Database::all(
                'SELECT lt.*, c.name AS customer_name FROM loyalty_transactions lt
                 JOIN customers c ON c.id = lt.customer_id
                 WHERE lt.tenant_id = ? ORDER BY lt.id DESC LIMIT 30',
                [$tenantId]
            )
            : [];
        $segments = Database::all(
            "SELECT tier, COUNT(*) AS total, COALESCE(SUM(total_spent),0) AS spend FROM customers WHERE tenant_id = ? GROUP BY tier",
            [$tenantId]
        );
        $this->view('crm/index', [
            'title' => 'Pelanggan & Loyalty',
            'customers' => $customers,
            'loyalty' => $loyalty,
            'segments' => $segments,
            'loyaltyEnabled' => License::enabled('loyalty'),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('crm.manage');
        $tenantId = $this->tenantId();
        $name = trim((string) $this->input('name', ''));
        $phone = trim((string) $this->input('phone', ''));
        if ($name === '') {
            flash('error', 'Nama pelanggan wajib diisi.');
            $this->redirect('/crm');
        }
        $id = $this->intInput('id');
        if ($id > 0) {
            $before = Database::one('SELECT * FROM customers WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
            if (!$before) {
                flash('error', 'Pelanggan tidak ditemukan.');
                $this->redirect('/crm');
            }
            Database::update('customers', [
                'name' => $name,
                'phone' => $phone ?: null,
                'email' => trim((string) $this->input('email', '')) ?: null,
                'notes' => substr((string) $this->input('notes', ''), 0, 250),
            ], 'id = ? AND tenant_id = ?', [$id, $tenantId]);
            AuditLog::log('customer_updated', 'customer', (string) $id, $before, ['name' => $name]);
            flash('success', 'Data pelanggan diperbarui.');
        } else {
            $newId = Database::insert('customers', [
                'public_id' => ulid(),
                'tenant_id' => $tenantId,
                'name' => $name,
                'phone' => $phone ?: null,
                'email' => trim((string) $this->input('email', '')) ?: null,
                'notes' => substr((string) $this->input('notes', ''), 0, 250),
                'points' => 0,
                'total_spent' => 0,
                'tier' => 'basic',
                'created_at' => now_utc(),
            ]);
            AuditLog::log('customer_created', 'customer', (string) $newId, null, ['name' => $name]);
            flash('success', 'Pelanggan ditambahkan.');
        }
        $this->redirect('/crm');
    }
}
