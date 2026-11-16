<?php
declare(strict_types=1);

namespace App\Modules\SaaS;

use App\Core\Controller;
use App\Core\Database;
use App\Core\License;

final class LicenseController extends Controller
{
    public function index(): void
    {
        $tenantId = $this->tenantId();
        $ctx = License::context();
        $licenses = Database::all('SELECT * FROM licenses WHERE tenant_id = ? ORDER BY id DESC LIMIT 10', [$tenantId]);
        $invoices = Database::all('SELECT * FROM subscription_invoices WHERE tenant_id = ? ORDER BY id DESC LIMIT 10', [$tenantId]);
        $usage = [];
        foreach (array_keys(License::LIMITS) as $key) {
            $usage[$key] = ['used' => License::usage($key), 'limit' => License::limit($key)];
        }
        $this->view('license/index', [
            'title' => 'Lisensi & Langganan',
            'context' => $ctx,
            'licenses' => $licenses,
            'invoices' => $invoices,
            'usage' => $usage,
            'modules' => License::MODULES,
            'notice' => License::expiryNotice(),
        ]);
    }

    public function activate(): void
    {
        $token = trim((string) $this->input('token', ''));
        if ($token === '') {
            flash('error', 'Token lisensi wajib diisi.');
            $this->redirect('/license');
        }
        try {
            License::activate($this->tenantId(), $token);
            flash('success', 'Lisensi berhasil diaktifkan.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $this->redirect('/license');
    }
}
