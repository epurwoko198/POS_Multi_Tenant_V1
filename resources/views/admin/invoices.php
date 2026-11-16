<?php use App\Core\Csrf; ?>
<div class="page-head">
  <div><h2>Invoice SaaS</h2><p>Tagihan langganan, jatuh tempo, dan perpanjangan otomatis.</p></div>
  <button class="btn btn-primary" type="button" data-modal-open="invoiceModal"><?= icon('plus') ?> Invoice Baru</button>
</div>

<div class="card">
  <div class="card-head"><div><h3>Daftar invoice</h3><p><?= count($invoices) ?> invoice</p></div></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Nomor</th><th>Tenant</th><th>Periode</th><th>Jatuh tempo</th><th class="num">Nominal</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$invoices): ?><tr><td colspan="7" class="muted center" style="padding:24px">Belum ada invoice.</td></tr><?php endif; ?>
      <?php foreach ($invoices as $i): ?>
        <tr>
          <td class="mono strong"><?= e($i['number']) ?></td>
          <td><?= e($i['tenant_name']) ?></td>
          <td class="sub"><?= e(date_local($i['period_start'], 'd M')) ?> – <?= e(date_local($i['period_end'], 'd M Y')) ?></td>
          <td class="sub"><?= e(date('d M Y', strtotime($i['due_date']))) ?></td>
          <td class="num strong"><?= rupiah($i['amount']) ?></td>
          <td><span class="badge badge-<?= badge_status($i['status']) ?>"><?= e(status_label($i['status'])) ?></span></td>
          <td class="right">
            <?php if (in_array($i['status'], ['open', 'overdue'], true)): ?>
              <form method="post" action="/admin/invoices/<?= (int) $i['id'] ?>/paid" data-confirm="Tandai invoice lunas dan perpanjang langganan?">
                <?= Csrf::field() ?><button class="btn btn-xs btn-success" type="submit">Tandai Lunas</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-backdrop hidden" id="invoiceModal">
  <div class="modal">
    <div class="modal-head"><div><h3>Invoice baru</h3><p>Invoice dibuat manual untuk perpanjangan / proration.</p></div></div>
    <form method="post" action="/admin/invoices">
      <?= Csrf::field() ?>
      <div class="modal-body">
        <div class="field"><label>Tenant</label><select class="select" name="tenant_id" required>
          <?php foreach ($tenants as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label>Nominal</label><input class="input" type="number" name="amount" min="1" step="1000" required></div>
        <div class="alert alert-info"><?= icon('alert') ?><div>Periode: 1 bulan ke depan. Jatuh tempo 7 hari.</div></div>
      </div>
      <div class="modal-foot"><button class="btn" type="button" data-modal-close="invoiceModal">Batal</button><button class="btn btn-primary" type="submit">Buat Invoice</button></div>
    </form>
  </div>
</div>
