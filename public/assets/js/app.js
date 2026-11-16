(function () {
  'use strict';

  const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  const App = {
    csrf,
    money(n, prefix = true) {
      const v = Number(n || 0);
      const s = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.round(v));
      return prefix ? 'Rp ' + s : s;
    },
    number(n, dec = 0) {
      return new Intl.NumberFormat('id-ID', { maximumFractionDigits: dec }).format(Number(n || 0));
    },
    async fetchJson(url, options = {}) {
      const opts = Object.assign({ method: 'GET', headers: {} }, options);
      opts.headers = Object.assign({
        'Accept': 'application/json',
        'X-CSRF-Token': csrf
      }, opts.headers || {});
      if (opts.body && typeof opts.body !== 'string') {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(opts.body);
      }
      const res = await fetch(url, opts);
      let data = null;
      try { data = await res.json(); } catch (e) { data = { ok: false, message: 'Respons tidak valid.' }; }
      if (!res.ok) {
        const err = new Error(data?.message || ('HTTP ' + res.status));
        err.status = res.status;
        err.data = data;
        throw err;
      }
      return data;
    },
    toast(message, type = 'info', timeout = 3800) {
      let stack = document.querySelector('.toast-stack');
      if (!stack) {
        stack = document.createElement('div');
        stack.className = 'toast-stack';
        document.body.appendChild(stack);
      }
      const el = document.createElement('div');
      el.className = 'toast ' + type;
      el.textContent = message;
      stack.appendChild(el);
      setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .2s'; setTimeout(() => el.remove(), 220); }, timeout);
    },
    openModal(id) {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.remove('hidden');
      document.body.style.overflow = 'hidden';
      const focus = el.querySelector('input:not([type=hidden]), select, textarea');
      if (focus) setTimeout(() => focus.focus(), 60);
    },
    closeModal(id) {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.add('hidden');
      if (!document.querySelector('.modal-backdrop:not(.hidden)')) {
        document.body.style.overflow = '';
      }
    },
    async copy(text, label = 'Disalin') {
      try {
        await navigator.clipboard.writeText(text);
        App.toast(label + ' ke clipboard.', 'success');
      } catch (e) {
        App.toast('Gagal menyalin.', 'error');
      }
    },
    init() {
      document.addEventListener('click', (ev) => {
        const open = ev.target.closest('[data-modal-open]');
        if (open) {
          ev.preventDefault();
          App.openModal(open.getAttribute('data-modal-open'));
          return;
        }
        const close = ev.target.closest('[data-modal-close]');
        if (close) {
          ev.preventDefault();
          const id = close.getAttribute('data-modal-close');
          if (id) App.closeModal(id); else close.closest('.modal-backdrop')?.classList.add('hidden');
          return;
        }
        if (ev.target.classList.contains('modal-backdrop')) {
          ev.target.classList.add('hidden');
          if (!document.querySelector('.modal-backdrop:not(.hidden)')) document.body.style.overflow = '';
        }
        const copy = ev.target.closest('[data-copy]');
        if (copy) {
          ev.preventDefault();
          App.copy(copy.getAttribute('data-copy'), copy.getAttribute('data-copy-label') || 'Disalin');
        }
      });
      document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') {
          document.querySelectorAll('.modal-backdrop:not(.hidden)').forEach(m => m.classList.add('hidden'));
          document.body.style.overflow = '';
        }
      });
      document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (ev) => {
          if (!window.confirm(form.getAttribute('data-confirm'))) ev.preventDefault();
        });
      });
      const toggle = document.querySelector('.menu-toggle');
      const sidebar = document.querySelector('.sidebar');
      if (toggle && sidebar) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
        document.addEventListener('click', (ev) => {
          if (window.innerWidth <= 980 && sidebar.classList.contains('open') &&
              !sidebar.contains(ev.target) && !toggle.contains(ev.target)) {
            sidebar.classList.remove('open');
          }
        });
      }
      const clock = document.querySelector('[data-clock]');
      if (clock) {
        const tick = () => {
          clock.textContent = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        };
        tick(); setInterval(tick, 1000);
      }
    }
  };

  window.App = App;
  document.addEventListener('DOMContentLoaded', App.init);
})();
