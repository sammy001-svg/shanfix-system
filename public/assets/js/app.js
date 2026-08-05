/* =====================================================================
   Shanfix BMS - shared UI behaviour
   No external libraries; CSP allows same-origin scripts only.
   ===================================================================== */
(function () {
  'use strict';

  const $  = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  /* ------------------------------------------------------------------
     Mobile sidebar
     ------------------------------------------------------------------ */
  function initSidebar() {
    const toggle = $('[data-sidebar-toggle]');
    const scrim  = $('.sidebar__scrim');

    if (toggle) {
      toggle.addEventListener('click', () => document.body.classList.toggle('nav-open'));
    }
    if (scrim) {
      scrim.addEventListener('click', () => document.body.classList.remove('nav-open'));
    }
  }

  /* ------------------------------------------------------------------
     Dropdowns
     ------------------------------------------------------------------ */
  function initDropdowns() {
    document.addEventListener('click', (e) => {
      const trigger = e.target.closest('[data-dropdown]');

      $$('.dropdown.is-open').forEach((d) => {
        if (!trigger || d !== trigger.closest('.dropdown')) d.classList.remove('is-open');
      });

      if (trigger) {
        e.preventDefault();
        trigger.closest('.dropdown').classList.toggle('is-open');
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') $$('.dropdown.is-open').forEach((d) => d.classList.remove('is-open'));
    });
  }

  /* ------------------------------------------------------------------
     Modals
     ------------------------------------------------------------------ */
  function openModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    const focusable = m.querySelector('input:not([type=hidden]), select, textarea, button');
    if (focusable) setTimeout(() => focusable.focus(), 40);
  }

  function closeModal(m) {
    if (!m) return;
    m.classList.remove('is-open');
    if (!$('.modal-backdrop.is-open')) document.body.style.overflow = '';
  }

  function initModals() {
    document.addEventListener('click', (e) => {
      const open = e.target.closest('[data-modal-open]');
      if (open) {
        e.preventDefault();
        openModal(open.dataset.modalOpen);
        return;
      }

      if (e.target.closest('[data-modal-close]')) {
        e.preventDefault();
        closeModal(e.target.closest('.modal-backdrop'));
        return;
      }

      // Click on the backdrop itself, not the panel
      if (e.target.classList.contains('modal-backdrop')) closeModal(e.target);
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeModal($('.modal-backdrop.is-open'));
    });
  }

  /* ------------------------------------------------------------------
     Toasts
     ------------------------------------------------------------------ */
  function toast(message, type) {
    let stack = $('.toasts');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'toasts';
      document.body.appendChild(stack);
    }

    const el = document.createElement('div');
    el.className = 'toast toast--' + (type || 'success');

    const body = document.createElement('div');
    body.textContent = message;
    el.appendChild(body);

    const close = document.createElement('button');
    close.className = 'toast__close';
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.textContent = '×';
    close.addEventListener('click', () => el.remove());
    el.appendChild(close);

    stack.appendChild(el);
    setTimeout(() => el.remove(), 5000);
  }

  function initFlashDismiss() {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.alert__close, .toast__close');
      if (btn) btn.closest('.alert, .toast').remove();
    });

    setTimeout(() => $$('.toasts .toast').forEach((t) => t.remove()), 5000);
  }

  /* ------------------------------------------------------------------
     Destructive-action confirmation
     ------------------------------------------------------------------ */
  function initConfirm() {
    document.addEventListener('submit', (e) => {
      const msg = e.target.dataset.confirm;
      if (msg && !window.confirm(msg)) e.preventDefault();
    });

    document.addEventListener('click', (e) => {
      const link = e.target.closest('a[data-confirm]');
      if (link && !window.confirm(link.dataset.confirm)) e.preventDefault();
    });
  }

  /* ------------------------------------------------------------------
     Print triggers (inline handlers are blocked by the CSP)
     ------------------------------------------------------------------ */
  function initPrint() {
    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-print]')) {
        e.preventDefault();
        window.print();
      }
    });
  }

  /* ------------------------------------------------------------------
     Auto-submitting filter forms
     ------------------------------------------------------------------ */
  function initAutoFilters() {
    $$('[data-auto-submit]').forEach((el) => {
      el.addEventListener('change', () => el.closest('form').submit());
    });

    $$('[data-debounce-submit]').forEach((el) => {
      let timer;
      el.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => el.closest('form').submit(), 450);
      });
    });
  }

  /* ------------------------------------------------------------------
     Prevent double submits
     ------------------------------------------------------------------ */
  function initSubmitGuard() {
    document.addEventListener('submit', (e) => {
      const form = e.target;
      if (form.dataset.noGuard !== undefined) return;

      const btn = form.querySelector('button[type="submit"]:not([data-no-guard])');
      if (!btn) return;

      // Let the browser serialise first, then lock the button.
      setTimeout(() => {
        if (form.dataset.submitting === '1') return;
        form.dataset.submitting = '1';
        btn.disabled = true;
        btn.dataset.originalText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner spinner--sm"></span> Working...';
      }, 0);
    });
  }

  /* ------------------------------------------------------------------
     Document line-item editor (quotations & invoices)
     ------------------------------------------------------------------ */
  function initLineItems() {
    const table = $('#items-table');
    if (!table) return;

    const tbody    = $('tbody', table);
    const catalog  = window.SHANFIX_CATALOG || { inventory: [], service: [] };
    const template = $('#item-row-template');

    function rowTotal(tr) {
      const q = parseFloat(($('[data-f=quantity]', tr) || {}).value) || 0;
      const p = parseFloat(($('[data-f=unit_price]', tr) || {}).value) || 0;
      return Math.round(q * p * 100) / 100;
    }

    function fmt(n) {
      return n.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function recalc() {
      let subtotal = 0;

      $$('tr', tbody).forEach((tr) => {
        const total = rowTotal(tr);
        subtotal += total;
        const cell = $('[data-f=line_total]', tr);
        if (cell) cell.textContent = fmt(total);
      });

      // Discount
      const discType  = ($('#discount_type') || {}).value || 'none';
      const discValue = parseFloat(($('#discount_value') || {}).value) || 0;

      let discount = 0;
      if (discType === 'percent') discount = subtotal * (discValue / 100);
      else if (discType === 'amount') discount = discValue;
      discount = Math.min(Math.round(discount * 100) / 100, subtotal);

      const net     = subtotal - discount;
      const vatMode = ($('#vat_mode') || {}).value || 'exclusive';
      const vatRate = parseFloat(($('#vat_rate') || {}).value) || 0;

      let vat = 0;
      let total = net;

      if (vatMode === 'exclusive') {
        vat = net * (vatRate / 100);
        total = net + vat;
      } else if (vatMode === 'inclusive') {
        // Net already contains VAT; back it out for display.
        vat = net - (net / (1 + vatRate / 100));
        total = net;
      }

      vat   = Math.round(vat * 100) / 100;
      total = Math.round(total * 100) / 100;

      const set = (sel, val) => { const el = $(sel); if (el) el.textContent = fmt(val); };
      set('#sum-subtotal', Math.round(subtotal * 100) / 100);
      set('#sum-discount', discount);
      set('#sum-vat', vat);
      set('#sum-total', total);

      const discRow = $('#row-discount');
      if (discRow) discRow.classList.toggle('hidden', discount <= 0);

      const vatRow = $('#row-vat');
      if (vatRow) {
        vatRow.classList.toggle('hidden', vatMode === 'exempt');
        const lbl = $('.totals__label', vatRow);
        if (lbl) {
          lbl.textContent = 'VAT (' + vatRate + '%)' + (vatMode === 'inclusive' ? ' — included' : '');
        }
      }
    }

    function reindex() {
      $$('tr', tbody).forEach((tr, i) => {
        $$('[name]', tr).forEach((input) => {
          input.name = input.name.replace(/items\[\d*\]/, 'items[' + i + ']');
        });
        const idx = $('[data-f=index]', tr);
        if (idx) idx.textContent = i + 1;
      });
    }

    function addRow(preset) {
      const frag = template.content.cloneNode(true);
      const tr   = frag.querySelector('tr');
      tbody.appendChild(frag);

      if (preset) {
        const set = (f, v) => { const el = $('[data-f=' + f + ']', tr); if (el) el.value = v; };
        set('item_type', preset.item_type);
        set('ref_id', preset.ref_id);
        set('description', preset.description);
        set('unit_price', preset.unit_price);
        set('quantity', preset.quantity || 1);
        set('unit', preset.unit || '');
        populateCatalog(tr);
        const sel = $('[data-f=ref_id]', tr);
        if (sel) sel.value = preset.ref_id;
      } else {
        populateCatalog(tr);
      }

      reindex();
      recalc();
      return tr;
    }

    /** Fill the catalogue <select> for the row's current item type. */
    function populateCatalog(tr) {
      const typeSel = $('[data-f=item_type]', tr);
      const refSel  = $('[data-f=ref_id]', tr);
      if (!typeSel || !refSel) return;

      const type = typeSel.value;
      refSel.innerHTML = '';

      if (type === 'custom') {
        refSel.classList.add('hidden');
        return;
      }

      refSel.classList.remove('hidden');

      const blank = document.createElement('option');
      blank.value = '';
      blank.textContent = type === 'inventory' ? 'Select item…' : 'Select service…';
      refSel.appendChild(blank);

      (catalog[type] || []).forEach((entry) => {
        const opt = document.createElement('option');
        opt.value = entry.id;
        opt.textContent = entry.label;
        opt.dataset.price = entry.price;
        opt.dataset.unit  = entry.unit || '';
        opt.dataset.desc  = entry.description || entry.label;
        refSel.appendChild(opt);
      });
    }

    tbody.addEventListener('input', (e) => {
      if (e.target.matches('[data-f=quantity], [data-f=unit_price]')) recalc();
    });

    tbody.addEventListener('change', (e) => {
      const tr = e.target.closest('tr');

      if (e.target.matches('[data-f=item_type]')) {
        populateCatalog(tr);
        recalc();
      }

      if (e.target.matches('[data-f=ref_id]')) {
        const opt = e.target.selectedOptions[0];
        if (opt && opt.value) {
          const desc  = $('[data-f=description]', tr);
          const price = $('[data-f=unit_price]', tr);
          const unit  = $('[data-f=unit]', tr);
          if (desc && !desc.value.trim()) desc.value = opt.dataset.desc || opt.textContent;
          if (price) price.value = opt.dataset.price || 0;
          if (unit) unit.value = opt.dataset.unit || '';
        }
        recalc();
      }
    });

    tbody.addEventListener('click', (e) => {
      if (e.target.closest('.items-table__del')) {
        if ($$('tr', tbody).length <= 1) {
          toast('A document needs at least one line item.', 'warning');
          return;
        }
        e.target.closest('tr').remove();
        reindex();
        recalc();
      }
    });

    const addBtn = $('#add-item-row');
    if (addBtn) addBtn.addEventListener('click', () => addRow());

    ['#discount_type', '#discount_value', '#vat_mode', '#vat_rate'].forEach((sel) => {
      const el = $(sel);
      if (el) el.addEventListener('input', recalc);
    });

    // Show/hide the discount value box based on type
    const discType = $('#discount_type');
    if (discType) {
      const sync = () => {
        const wrap = $('#discount_value_wrap');
        if (wrap) wrap.classList.toggle('hidden', discType.value === 'none');
      };
      discType.addEventListener('change', sync);
      sync();
    }

    // Quick-add from the catalogue side panel
    $$('[data-add-catalog]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const d = btn.dataset;
        const rows = $$('tr', tbody);
        const lastDesc = rows.length ? $('[data-f=description]', rows[rows.length - 1]) : null;

        // Reuse a blank trailing row instead of leaving it empty.
        if (rows.length === 1 && lastDesc && !lastDesc.value.trim()) rows[0].remove();

        addRow({
          item_type: d.addCatalog,
          ref_id: d.id,
          description: d.description || d.label,
          unit_price: d.price,
          unit: d.unit || '',
          quantity: 1
        });

        toast(d.label + ' added', 'success');
      });
    });

    if (!$$('tr', tbody).length) addRow();
    recalc();
  }

  /* ------------------------------------------------------------------
     Job card checklist rows (no pricing, so simpler than the doc editor)
     ------------------------------------------------------------------ */
  function initJobItems() {
    const table = $('#job-items-table');
    if (!table) return;

    const tbody    = $('tbody', table);
    const template = $('#job-item-template');
    if (!template) return;

    function reindex() {
      $$('tr', tbody).forEach((tr, i) => {
        $$('[name]', tr).forEach((input) => {
          input.name = input.name.replace(/items\[\d*\]/, 'items[' + i + ']');
        });
        const idx = $('[data-f=index]', tr);
        if (idx) idx.textContent = i + 1;
      });
    }

    function addRow() {
      tbody.appendChild(template.content.cloneNode(true));
      reindex();
      const rows = $$('tr', tbody);
      const desc = $('[data-f=description]', rows[rows.length - 1]);
      if (desc) desc.focus();
    }

    const addBtn = $('#add-job-item');
    if (addBtn) addBtn.addEventListener('click', addRow);

    tbody.addEventListener('click', (e) => {
      if (!e.target.closest('.items-table__del')) return;

      if ($$('tr', tbody).length <= 1) {
        toast('A job card needs at least one item.', 'warning');
        return;
      }

      e.target.closest('tr').remove();
      reindex();
    });

    if (!$$('tr', tbody).length) addRow();
  }

  /* ------------------------------------------------------------------
     KopoKopo STK Push status polling
     ------------------------------------------------------------------ */
  function initStkPolling() {
    const box = $('#stk-poll');
    if (!box) return;

    const id  = box.dataset.stkId;
    const url = box.dataset.pollUrl;
    let tries = 0;
    const MAX = 40; // ~2 minutes at 3s

    const tick = () => {
      tries++;

      fetch(url + '?id=' + encodeURIComponent(id), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      })
        .then((r) => r.json())
        .then((data) => {
          if (!data.ok) return;

          if (data.status === 'success') {
            box.innerHTML =
              '<div class="stk-status">' +
              '<div class="stk-status__icon">' + checkSvg() + '</div>' +
              '<div class="stk-status__title">Payment received</div>' +
              '<div class="stk-status__text">' + esc(data.message || '') + '</div>' +
              '</div>';
            setTimeout(() => window.location.reload(), 1800);
            return;
          }

          if (data.status === 'failed' || data.status === 'cancelled' || data.status === 'timeout') {
            box.classList.add('stk-status--failed');
            box.innerHTML =
              '<div class="stk-status stk-status--failed">' +
              '<div class="stk-status__icon">' + xSvg() + '</div>' +
              '<div class="stk-status__title">Payment not completed</div>' +
              '<div class="stk-status__text">' + esc(data.message || 'The customer did not complete the payment.') + '</div>' +
              '</div>';
            return;
          }

          if (tries < MAX) {
            setTimeout(tick, 3000);
          } else {
            const t = $('.stk-status__text', box);
            if (t) {
              t.textContent =
                'Still waiting for confirmation. You can close this window — ' +
                'the payment will be recorded automatically when M-Pesa confirms it.';
            }
          }
        })
        .catch(() => { if (tries < MAX) setTimeout(tick, 4000); });
    };

    setTimeout(tick, 3000);
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function checkSvg() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" ' +
           'stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
  }

  function xSvg() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" ' +
           'stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/>' +
           '<line x1="6" y1="6" x2="18" y2="18"/></svg>';
  }

  /* ------------------------------------------------------------------
     Chat
     ------------------------------------------------------------------ */
  function initChat() {
    const panel = $('#chat-panel');
    if (!panel) return;

    const stream = $('#chat-messages');
    const form   = $('#chat-form');
    const input  = $('#chat-input');
    const convId = panel.dataset.conversationId;
    const pollUrl = panel.dataset.pollUrl;

    let lastId = parseInt(panel.dataset.lastId || '0', 10);
    let polling = true;

    const scrollToEnd = () => { if (stream) stream.scrollTop = stream.scrollHeight; };
    scrollToEnd();

    function appendMessages(messages) {
      if (!messages || !messages.length) return;

      const nearBottom = stream.scrollHeight - stream.scrollTop - stream.clientHeight < 140;

      messages.forEach((m) => {
        if (m.id <= lastId) return;
        lastId = m.id;
        stream.insertAdjacentHTML('beforeend', renderMessage(m));
      });

      if (nearBottom) scrollToEnd();
    }

    function renderMessage(m) {
      const mine = m.is_mine ? ' msg--mine' : '';

      let file = '';
      if (m.attachment_url) {
        file =
          '<a class="msg__file" href="' + esc(m.attachment_url) + '" target="_blank" rel="noopener">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
          'stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/>' +
          '</svg>' + esc(m.attachment_name || 'Attachment') + '</a>';
      }

      return (
        '<div class="msg' + mine + '">' +
        '<div class="avatar avatar--sm" style="background:' + esc(m.color || '#0C2B4A') + '">' + esc(m.initials) + '</div>' +
        '<div class="msg__bubble">' +
        '<div class="msg__author">' + esc(m.author) + '</div>' +
        (m.body ? '<div class="msg__body">' + esc(m.body) + '</div>' : '') +
        file +
        '<div class="msg__time">' + esc(m.time) + '</div>' +
        '</div></div>'
      );
    }

    function poll() {
      if (!polling || document.hidden) {
        setTimeout(poll, 5000);
        return;
      }

      fetch(pollUrl + '?conversation_id=' + encodeURIComponent(convId) + '&after=' + lastId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      })
        .then((r) => r.json())
        .then((data) => {
          if (data.ok) appendMessages(data.messages);
          setTimeout(poll, 4000);
        })
        .catch(() => setTimeout(poll, 8000));
    }

    setTimeout(poll, 4000);

    // Enter sends, Shift+Enter adds a newline
    if (input) {
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          form.requestSubmit();
        }
      });

      input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 140) + 'px';
      });
    }

    if (form) {
      form.addEventListener('submit', (e) => {
        // Let file uploads post normally so the browser handles multipart.
        const fileField = $('#chat-file');
        if (fileField && fileField.files.length) return;

        e.preventDefault();
        const body = input.value.trim();
        if (!body) return;

        input.value = '';
        input.style.height = 'auto';

        const fd = new FormData();
        fd.append('conversation_id', convId);
        fd.append('body', body);
        fd.append('_token', csrf());

        fetch(form.action, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        })
          .then((r) => r.json())
          .then((data) => {
            if (data.ok && data.message) {
              appendMessages([data.message]);
              scrollToEnd();
            } else {
              toast(data.error || 'Message could not be sent.', 'error');
              input.value = body;
            }
          })
          .catch(() => {
            toast('Network error. Message not sent.', 'error');
            input.value = body;
          });
      });
    }

    window.addEventListener('beforeunload', () => { polling = false; });
  }

  /* ------------------------------------------------------------------
     Unread chat badge
     ------------------------------------------------------------------ */
  function initUnreadPoll() {
    const badge = $('#chat-unread-badge');
    if (!badge) return;

    const url = badge.dataset.url;
    if (!url) return;

    const refresh = () => {
      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then((r) => r.json())
        .then((d) => {
          if (!d.ok) return;
          badge.textContent = d.unread > 99 ? '99+' : d.unread;
          badge.classList.toggle('hidden', !d.unread);
        })
        .catch(() => {});
    };

    refresh();
    setInterval(refresh, 30000);
  }

  /* ------------------------------------------------------------------
     Client picker: fill phone when a client is chosen (STK form)
     ------------------------------------------------------------------ */
  function initLinkedSelects() {
    $$('[data-fills]').forEach((select) => {
      select.addEventListener('change', () => {
        const target = $(select.dataset.fills);
        const opt = select.selectedOptions[0];
        if (target && opt && opt.dataset.value !== undefined) target.value = opt.dataset.value;
      });
    });
  }

  /* ------------------------------------------------------------------
     Boot
     ------------------------------------------------------------------ */
  document.addEventListener('DOMContentLoaded', function () {
    initSidebar();
    initDropdowns();
    initModals();
    initFlashDismiss();
    initConfirm();
    initPrint();
    initAutoFilters();
    initSubmitGuard();
    initLineItems();
    initJobItems();
    initStkPolling();
    initChat();
    initUnreadPoll();
    initLinkedSelects();
  });

  window.Shanfix = { toast, openModal };
})();
