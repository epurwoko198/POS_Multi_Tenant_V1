(function () {
  'use strict';

  const cfg = JSON.parse(document.getElementById('pos-data').textContent);
  const products = cfg.products;
  const state = {
    cart: [],
    method: 'cash',
    category: '',
    search: '',
    orderType: 'retail',
    customerId: '',
    tableId: '',
    discount: 0,
    order: null,
    payment: null,
    polling: null
  };

  const el = (id) => document.getElementById(id);
  const money = (n) => App.money(n);

  function renderProducts() {
    const grid = el('productGrid');
    const q = state.search.toLowerCase().trim();
    const list = products.filter(p => {
      if (state.category && p.category !== state.category) return false;
      if (!q) return true;
      return p.name.toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q) || (p.barcode || '').includes(q);
    });
    grid.innerHTML = list.map(p => `
      <button class="product-card" type="button" data-add="${p.id}" ${p.track_stock && p.stock <= 0 ? 'data-out="1"' : ''}>
        <div class="p-sku">${escapeHtml(p.sku)}${p.station ? ' · ' + escapeHtml(p.station) : ''}</div>
        <div class="p-name">${escapeHtml(p.name)}</div>
        ${p.track_stock ? `<div class="p-stock">Stok ${App.number(p.stock, 0)} ${escapeHtml(p.unit)}</div>` : '<div class="p-stock">&nbsp;</div>'}
        <div class="p-price">${money(p.price)}</div>
      </button>
    `).join('');
    el('productEmpty').classList.toggle('hidden', list.length > 0);
    grid.querySelectorAll('[data-out="1"]').forEach(c => c.classList.add('out'));
  }

  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
  }

  function addProduct(id) {
    const p = products.find(x => x.id === id);
    if (!p) return;
    const line = state.cart.find(x => x.id === id);
    if (line) line.qty += 1;
    else state.cart.push({ id: p.id, name: p.name, price: p.price, qty: 1, tax_rate: p.tax_rate, unit: p.unit, station: p.station });
    renderCart();
    el('cartPanel').classList.add('open');
  }

  function setQty(id, qty) {
    const line = state.cart.find(x => x.id === id);
    if (!line) return;
    line.qty = qty;
    if (line.qty <= 0) state.cart = state.cart.filter(x => x.id !== id);
    renderCart();
  }

  function computeTotals() {
    let subtotal = 0;
    state.cart.forEach(l => { subtotal += l.qty * l.price; });
    const discount = Math.min(Math.max(0, Number(state.discount) || 0), subtotal);
    const factor = subtotal > 0 ? (subtotal - discount) / subtotal : 0;
    let tax = 0;
    state.cart.forEach(l => { tax += (l.qty * l.price * factor) * (l.tax_rate / 100); });
    const service = state.orderType === 'dine_in' ? (subtotal - discount) * (cfg.outlet.service / 100) : 0;
    const total = subtotal - discount + tax + service;
    return {
      subtotal, discount,
      tax: Math.round(tax * 100) / 100,
      service: Math.round(service * 100) / 100,
      total: Math.round(total * 100) / 100
    };
  }

  function renderCart() {
    const box = el('cartItems');
    el('cartEmpty').style.display = state.cart.length ? 'none' : 'block';
    box.querySelectorAll('.cart-row').forEach(n => n.remove());
    const count = state.cart.reduce((a, l) => a + l.qty, 0);
    el('cartCount').textContent = count;
    const t = computeTotals();
    state.cart.forEach(l => {
      const row = document.createElement('div');
      row.className = 'cart-row';
      row.innerHTML = `
        <div class="info">
          <div class="name">${escapeHtml(l.name)}</div>
          <div class="meta">${money(l.price)} · ${escapeHtml(l.unit)}</div>
          <div class="qty-stepper mt-1">
            <button type="button" data-qty="${l.id}" data-delta="-1">−</button>
            <span>${App.number(l.qty, 0)}</span>
            <button type="button" data-qty="${l.id}" data-delta="1">+</button>
          </div>
        </div>
        <div class="line-total">${money(l.qty * l.price)}</div>
        <button class="btn btn-ghost btn-icon btn-sm" type="button" data-remove="${l.id}" title="Hapus">${'×'}</button>
      `;
      box.appendChild(row);
    });
    el('sumSubtotal').textContent = money(t.subtotal);
    el('sumTax').textContent = money(t.tax);
    el('sumService').textContent = money(t.service);
    el('serviceRow').style.display = state.orderType === 'dine_in' ? 'flex' : 'none';
    el('sumTotal').textContent = money(t.total);
    el('checkoutBtn').disabled = state.cart.length === 0 || !cfg.shift;
    el('checkoutBtn').textContent = cfg.shift ? 'Bayar & Cetak Nota' : 'Buka shift untuk transaksi';
    const tendered = Number(el('cashTendered').value || 0);
    el('changeDue').textContent = money(Math.max(0, tendered - t.total));
    el('orderDiscount').value = state.discount || 0;
  }

  function setMethod(method) {
    state.method = method;
    document.querySelectorAll('.pay-tab').forEach(b => b.classList.toggle('active', b.dataset.method === method));
    el('cashPanel').classList.toggle('hidden', method !== 'cash');
    el('refPanel').classList.toggle('hidden', !(method === 'edc' || method === 'transfer'));
  }

  async function checkout() {
    if (!cfg.shift) {
      App.openModal('shiftModal');
      return;
    }
    if (!state.cart.length) return;
    const t = computeTotals();
    if (state.method === 'cash') {
      const tendered = Number(el('cashTendered').value || 0);
      if (tendered + 0.009 < t.total) {
        App.toast('Uang diterima kurang dari total tagihan.', 'error');
        el('cashTendered').focus();
        return;
      }
    }
    if ((state.method === 'edc' || state.method === 'transfer') && !el('providerRef').value.trim()) {
      App.toast('Isi referensi EDC / transfer untuk rekonsiliasi.', 'error');
      el('providerRef').focus();
      return;
    }
    const btn = el('checkoutBtn');
    btn.disabled = true;
    btn.textContent = 'Memproses…';
    try {
      const payload = {
        items: state.cart.map(l => ({ product_id: l.id, qty: l.qty })),
        payment_method: state.method,
        order_type: state.orderType,
        customer_id: state.customerId || null,
        table_id: state.tableId || null,
        discount_total: Number(state.discount || 0),
        tendered: Number(el('cashTendered').value || 0),
        provider_ref: el('providerRef').value.trim(),
        notes: '',
        outlet_id: cfg.outlet.id,
        register_id: cfg.shift.register_id,
        idempotency_key: 'pos-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9)
      };
      const res = await App.fetchJson('/pos/checkout', { method: 'POST', body: payload });
      state.order = res.order;
      state.payment = res.payment;
      if (res.payment.status === 'pending' || res.payment.status === 'created') {
        showQris(res);
      } else {
        showSuccess(res);
      }
    } catch (e) {
      App.toast(e.message || 'Checkout gagal.', 'error', 6000);
    } finally {
      btn.disabled = false;
      renderCart();
    }
  }

  function showQris(res) {
    el('qrisOrderNo').textContent = res.order.order_no;
    el('qrisAmount').textContent = money(res.order.grand_total);
    el('qrisString').textContent = res.payment.qr_string || '';
    const holder = el('qrisCanvas');
    holder.innerHTML = '';
    if (window.QRCode && res.payment.qr_string) {
      new QRCode(holder, { text: res.payment.qr_string, width: 190, height: 190, correctLevel: QRCode.CorrectLevel.M });
    }
    App.openModal('qrisModal');
    startPolling(res.payment.public_id, res);
  }

  function startPolling(paymentId, initial) {
    stopPolling();
    let seconds = 900;
    const timer = el('qrisTimer');
    state.polling = setInterval(async () => {
      seconds -= 3;
      if (seconds <= 0) { stopPolling(); timer.textContent = 'QR kedaluwarsa. Buat order baru.'; return; }
      timer.textContent = 'QR berlaku ' + Math.floor(seconds / 60) + ' menit ' + (seconds % 60) + ' detik';
      try {
        const data = await App.fetchJson('/api/payments/' + paymentId + '/status');
        if (data.status === 'paid' || data.status === 'settled') {
          stopPolling();
          App.closeModal('qrisModal');
          showSuccess({ order: { order_no: initial.order.order_no, grand_total: initial.order.grand_total, public_id: initial.order.public_id }, payment: data, receipt_url: data.receipt_url, change_due: 0 });
        } else if (['expired', 'failed', 'cancelled'].includes(data.status)) {
          stopPolling();
          timer.textContent = 'Pembayaran ' + data.status + '.';
        }
      } catch (e) { /* transient */ }
    }, 3000);
  }

  function stopPolling() {
    if (state.polling) { clearInterval(state.polling); state.polling = null; }
  }

  function showSuccess(res) {
    el('successOrderNo').textContent = res.order.order_no;
    el('successTotal').textContent = money(res.order.grand_total);
    el('successChange').textContent = money(res.change_due || 0);
    el('successReceipt').href = res.receipt_url;
    el('printAgain').dataset.orderId = res.order.public_id;
    el('printAgain').disabled = false;
    App.closeModal('qrisModal');
    App.openModal('successModal');
    resetCart();
  }

  function resetCart() {
    state.cart = [];
    state.discount = 0;
    state.customerId = '';
    state.tableId = '';
    state.order = null;
    state.payment = null;
    el('orderDiscount').value = 0;
    el('cashTendered').value = '';
    el('providerRef').value = '';
    el('posCustomer').value = '';
    el('posTable').value = '';
    el('orderType').value = 'retail';
    state.orderType = 'retail';
    renderCart();
  }

  document.addEventListener('click', async function (ev) {
    const add = ev.target.closest('[data-add]');
    if (add) { addProduct(Number(add.dataset.add)); return; }
    const q = ev.target.closest('[data-qty]');
    if (q) {
      const line = state.cart.find(x => x.id === Number(q.dataset.qty));
      if (line) setQty(line.id, line.qty + Number(q.dataset.delta));
      return;
    }
    const rem = ev.target.closest('[data-remove]');
    if (rem) { setQty(Number(rem.dataset.remove), 0); return; }
    const tab = ev.target.closest('[data-method]');
    if (tab) { setMethod(tab.dataset.method); return; }
    const cash = ev.target.closest('[data-cash]');
    if (cash) {
      el('cashTendered').value = Math.ceil(computeTotals().total / 1000) * 1000;
      renderCart();
      return;
    }
    const cat = ev.target.closest('[data-cat]');
    if (cat) {
      state.category = cat.dataset.cat;
      document.querySelectorAll('.cat-tab').forEach(b => b.classList.toggle('active', b.dataset.cat === state.category));
      renderProducts();
    }
  });

  el('posSearch').addEventListener('input', function () {
    state.search = this.value;
    renderProducts();
  });

  el('posSearch').addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') {
      ev.preventDefault();
      const q = this.value.trim().toLowerCase();
      const exact = products.find(p => (p.barcode || '').toLowerCase() === q || (p.sku || '').toLowerCase() === q);
      if (exact) { addProduct(exact.id); this.value = ''; state.search = ''; renderProducts(); }
    }
  });

  el('orderDiscount').addEventListener('input', function () {
    state.discount = Number(this.value || 0);
    renderCart();
  });
  el('cashTendered').addEventListener('input', renderCart);
  el('posCustomer').addEventListener('change', function () { state.customerId = this.value; });
  el('posTable').addEventListener('change', function () { state.tableId = this.value; });
  el('orderType').addEventListener('change', function () {
    state.orderType = this.value;
    if (this.value === 'dine_in') {
      App.toast('Mode dine-in: service charge outlet akan diterapkan.', 'info');
    }
    renderCart();
  });
  el('clearCart').addEventListener('click', resetCart);
  el('checkoutBtn').addEventListener('click', checkout);

  el('simulatePaid').addEventListener('click', async function () {
    if (!state.payment?.public_id) return;
    this.disabled = true;
    try {
      const data = await App.fetchJson('/api/payments/' + state.payment.public_id + '/simulate', { method: 'POST', body: {} });
      stopPolling();
      App.closeModal('qrisModal');
      showSuccess({
        order: { order_no: el('qrisOrderNo').textContent, grand_total: Number(el('qrisAmount').textContent.replace(/[^0-9]/g, '')), public_id: state.order.public_id },
        payment: data, receipt_url: data.receipt_url, change_due: 0
      });
    } catch (e) {
      App.toast(e.message, 'error');
    } finally {
      this.disabled = false;
    }
  });

  el('cancelOrder').addEventListener('click', async function () {
    if (!state.order?.public_id) return;
    try {
      await App.fetchJson('/pos/cancel/' + state.order.public_id, { method: 'POST', body: { reason: 'Dibatalkan dari layar kasir' } });
      stopPolling();
      App.closeModal('qrisModal');
      resetCart();
      App.toast('Order dibatalkan.', 'success');
    } catch (e) { App.toast(e.message, 'error'); }
  });

  el('printAgain').addEventListener('click', async function () {
    if (!this.dataset.orderId) return;
    try {
      await App.fetchJson('/pos/print/' + this.dataset.orderId, { method: 'POST', body: {} });
      App.toast('Permintaan cetak ulang masuk antrean.', 'success');
    } catch (e) { App.toast(e.message, 'error'); }
  });

  el('newOrder').addEventListener('click', function () {
    App.closeModal('successModal');
    el('posSearch').focus();
  });

  el('qrisManualClose').addEventListener('click', function () {
    stopPolling();
    App.closeModal('qrisModal');
  });

  renderProducts();
  renderCart();
  if (!cfg.shift) setTimeout(() => App.openModal('shiftModal'), 400);

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'F2') { ev.preventDefault(); el('posSearch').focus(); }
    if (ev.key === 'F4') { ev.preventDefault(); checkout(); }
  });
})();
