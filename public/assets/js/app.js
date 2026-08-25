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
     Click-to-select fields (share links)
     ------------------------------------------------------------------ */
  function initSelectOnFocus() {
    document.addEventListener('focusin', (e) => {
      if (e.target.matches('[data-select-on-focus]')) e.target.select();
    });
  }

  /* ------------------------------------------------------------------
     Preview picked images before they are uploaded
     ------------------------------------------------------------------ */
  function initImagePreview() {
    $$('[data-image-preview]').forEach((input) => {
      const target = $(input.dataset.imagePreview);
      if (!target) return;

      input.addEventListener('change', () => {
        // Release the previous batch, or the object URLs leak.
        $$('img', target).forEach((img) => URL.revokeObjectURL(img.src));
        target.innerHTML = '';

        Array.from(input.files || []).forEach((file) => {
          if (!file.type.startsWith('image/')) return;

          const tile = document.createElement('span');
          tile.className = 'thumb';

          const img = document.createElement('img');
          img.src = URL.createObjectURL(file);
          img.alt = file.name;
          img.onload = () => { /* keep the URL: revoked on the next change */ };

          tile.appendChild(img);
          target.appendChild(tile);
        });
      });
    });
  }

  /* ------------------------------------------------------------------
     Show / hide password
     ------------------------------------------------------------------ */
  function initPasswordToggle() {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-toggle-password]');
      if (!btn) return;

      e.preventDefault();

      const input = $(btn.dataset.togglePassword);
      if (!input) return;

      const revealing = input.type === 'password';
      input.type = revealing ? 'text' : 'password';

      // Marked so the submit handler can put it back — browsers are more
      // reliable about offering to save a password from a password field.
      if (revealing) {
        input.dataset.wasPassword = '1';
      } else {
        delete input.dataset.wasPassword;
      }

      btn.setAttribute('aria-pressed', String(revealing));
      btn.setAttribute('aria-label', revealing ? 'Hide password' : 'Show password');

      const show = $('[data-icon-show]', btn);
      const hide = $('[data-icon-hide]', btn);
      if (show) show.hidden = revealing;
      if (hide) hide.hidden = !revealing;

      // Keep the caret where it was rather than jumping to the start.
      const pos = input.value.length;
      input.focus();
      try { input.setSelectionRange(pos, pos); } catch (_) { /* type change race */ }
    });

    // Never leave a password on screen after the form is submitted.
    document.addEventListener('submit', (e) => {
      $$('input[type="text"][data-was-password]', e.target).forEach((i) => {
        i.type = 'password';
      });
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
  /**
   * The meeting room: screen sharing, and minutes typed as you go.
   *
   * Screen sharing is done browser-to-browser. The server only carries the
   * introductions — each side's description of itself and the network
   * routes it can be reached on — because ordinary hosting cannot hold a
   * socket open. Those introductions go through the same kind of polling
   * the chat uses.
   *
   * One person shares at a time and everyone else watches, which is what a
   * business meeting actually needs and is far more reliable than everyone
   * connecting to everyone.
   */
  /**
   * The WhatsApp inbox: send without losing your place, and pick up
   * replies as they arrive.
   *
   * Polls rather than pushes, for the same reason as the chat — nothing
   * on this hosting can hold a connection open. Five seconds is frequent
   * enough to feel live for a conversation carried out by typing.
   */
  function initWhatsApp() {
    const box = $('[data-wa-messages]');
    if (!box) return;

    let last = parseInt(box.dataset.last || '0', 10);
    const pollUrl = box.dataset.pollUrl;

    function atBottom() {
      // Only auto-scroll if they are already at the bottom; yanking the
      // view down while somebody is reading back is worse than a missed
      // message.
      return box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    }

    function render(m) {
      const wrap = document.createElement('div');
      wrap.className = 'wa__msg wa__msg--' + (m.direction === 'out' ? 'out' : 'in');

      const bubble = document.createElement('div');
      bubble.className = 'wa__bubble';

      if (m.msg_type !== 'text' && !m.body) {
        bubble.textContent = m.msg_type.charAt(0).toUpperCase() + m.msg_type.slice(1);
      } else {
        bubble.textContent = m.body || '';    // textContent, never innerHTML
      }

      const meta = document.createElement('span');
      meta.className = 'wa__meta';
      const when = (m.wa_timestamp || m.created_at || '').slice(11, 16);
      meta.textContent = (m.direction === 'out' && m.sender ? m.sender.split(' ')[0] + ' · ' : '') +
                         when + (m.direction === 'out' ? ' · ' + m.status : '');

      bubble.appendChild(meta);

      if (m.error) {
        const err = document.createElement('span');
        err.className = 'wa__error';
        err.textContent = m.error;
        bubble.appendChild(err);
      }

      wrap.appendChild(bubble);
      box.appendChild(wrap);
    }

    function poll() {
      fetch(pollUrl + '?since=' + last, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
        .then((r) => r.json())
        .then((data) => {
          if (!data.ok || !data.messages || !data.messages.length) return;

          const stick = atBottom();
          data.messages.forEach((m) => { render(m); last = m.id; });
          if (stick) box.scrollTop = box.scrollHeight;
        })
        .catch(() => { /* a dropped poll corrects itself on the next one */ });
    }

    const form = $('[data-wa-form]');

    if (form) {
      const input = $('[data-wa-input]', form);

      const send = () => {
        const body = input.value.trim();
        if (!body) return;

        const params = new URLSearchParams({ _token: csrf(), body: body });
        input.value = '';
        input.style.height = '';

        fetch(form.dataset.url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          body: params,
        })
          .then((r) => r.json())
          .then((data) => {
            if (data.ok && data.message) {
              render(data.message);
              last = data.message.id;
              box.scrollTop = box.scrollHeight;
              return;
            }

            // Put the text back so nothing is lost to a refusal — most
            // often the 24-hour window having closed mid-conversation.
            input.value = body;
            toast(data.error || 'The message could not be sent.', 'error');
          })
          .catch(() => {
            input.value = body;
            toast('The message could not be sent.', 'error');
          });
      };

      form.addEventListener('submit', (e) => { e.preventDefault(); send(); });

      // Enter sends, Shift+Enter starts a new line — as in WhatsApp itself.
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
      });

      input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 140) + 'px';
      });
    }

    box.scrollTop = box.scrollHeight;
    setInterval(poll, 5000);
  }

  function initMeetingRoom() {
    const cfg = $('[data-room]');
    if (!cfg) return;

    const base   = cfg.dataset.base;
    const meName = cfg.dataset.me;
    const ice    = JSON.parse(cfg.dataset.ice || '[]');

    // Identifies this tab, not this person — somebody may join twice.
    const myPeer = 'p' + Math.random().toString(36).slice(2, 10);

    const stage      = $('[data-stage-video]');
    const stageIdle  = $('[data-stage-idle]');
    const statusEl   = $('[data-status]');
    const shareBtn   = $('[data-share-screen]');
    const shareLabel = $('[data-share-label]');
    const micBtn     = $('[data-toggle-mic]');
    const micLabel   = $('[data-mic-label]');

    /** peer id -> RTCPeerConnection */
    const peers = {};

    /**
     * Everyone we know is in the room, whether or not we have a connection
     * to them yet.
     *
     * Needed because arriving and sharing happen in either order. Someone
     * who joins before the screen goes up must still be offered it, and
     * without a roster the presenter has nobody to call.
     */
    const known = new Set();

    let localScreen = null;
    let localMic    = null;
    let sinceSignal = 0;
    let sharing     = false;

    function say(msg, tone) {
      statusEl.textContent = msg || '';
      statusEl.className = 'room__status' + (tone ? ' room__status--' + tone : '');
    }

    function post(path, data) {
      const body = new URLSearchParams(data);
      body.set('_token', csrf());

      return fetch(base + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: body,
      }).then((r) => r.json()).catch(() => ({ ok: false }));
    }

    function signal(kind, to, payload) {
      return post('/signal', {
        from: myPeer,
        to: to || '',
        kind: kind,
        payload: payload ? JSON.stringify(payload) : '',
      });
    }

    /* -- connections ------------------------------------------------- */

    function connectionTo(peerId) {
      if (peers[peerId]) return peers[peerId];

      const pc = new RTCPeerConnection({ iceServers: ice });
      peers[peerId] = pc;

      pc.onicecandidate = (e) => {
        if (e.candidate) signal('ice', peerId, e.candidate);
      };

      // Whatever the other side sends becomes what we show.
      pc.ontrack = (e) => {
        stage.srcObject = e.streams[0];
        stage.hidden = false;
        stageIdle.hidden = true;
        say('Watching a shared screen.', 'ok');
      };

      pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'failed') {
          // Almost always both ends behind strict NAT with no relay set up.
          say('Could not connect directly to the other person. A TURN relay may be needed — see Settings.', 'warn');
        }
      };

      // Anything we are already sending goes to a newcomer too.
      if (localScreen) localScreen.getTracks().forEach((t) => pc.addTrack(t, localScreen));
      if (localMic)    localMic.getTracks().forEach((t) => pc.addTrack(t, localMic));

      return pc;
    }

    async function offerTo(peerId) {
      const pc = connectionTo(peerId);
      const offer = await pc.createOffer();
      await pc.setLocalDescription(offer);
      signal('offer', peerId, offer);
    }

    async function handle(sig) {
      const from = sig.from_peer;
      let payload = null;

      try { payload = sig.payload ? JSON.parse(sig.payload) : null; } catch (e) { return; }

      if (sig.kind === 'hello') {
        known.add(from);

        // Answer a room-wide hello so the newcomer learns we are here too.
        // Only broadcasts get a reply — answering a directed one would have
        // the two of us greeting each other for ever.
        if (!sig.to_peer) signal('hello', from, { name: meName });

        // If our screen is already up, they should be seeing it.
        if (sharing) offerTo(from);
        return;
      }

      if (sig.kind === 'offer') {
        const pc = connectionTo(from);
        await pc.setRemoteDescription(new RTCSessionDescription(payload));
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        signal('answer', from, answer);
        return;
      }

      if (sig.kind === 'answer') {
        const pc = peers[from];
        if (pc) await pc.setRemoteDescription(new RTCSessionDescription(payload));
        return;
      }

      if (sig.kind === 'ice') {
        const pc = peers[from];
        // A candidate can arrive before the description it belongs to;
        // failing here is normal and not worth surfacing.
        if (pc) { try { await pc.addIceCandidate(payload); } catch (e) { /* ignore */ } }
        return;
      }

      if (sig.kind === 'bye') {
        known.delete(from);
        if (peers[from]) { peers[from].close(); delete peers[from]; }
        if (!Object.keys(peers).length) {
          stage.hidden = true;
          stageIdle.hidden = false;
          say('');
        }
      }
    }

    /* -- sharing ------------------------------------------------------ */

    async function startSharing() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
        say('This browser cannot share a screen. Chrome, Edge or Firefox can.', 'warn');
        return;
      }

      try {
        localScreen = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: true });
      } catch (e) {
        // Cancelling the picker is a normal thing to do, not an error.
        say('');
        return;
      }

      sharing = true;
      shareLabel.textContent = 'Stop sharing';
      shareBtn.classList.add('is-on');

      stage.srcObject = localScreen;
      stage.muted = true;               // never play your own audio back
      stage.hidden = false;
      stageIdle.hidden = true;
      say('You are sharing your screen.', 'ok');

      // Stopping from the browser's own bar must tidy up here too.
      localScreen.getVideoTracks()[0].addEventListener('ended', stopSharing);

      // Call everyone already in the room. connectionTo() attaches whatever
      // we are sending, so the tracks come along with the offer.
      known.forEach((id) => offerTo(id));

      // And announce ourselves, in case somebody arrived without us hearing.
      signal('hello', null, { name: meName });
    }

    function stopSharing() {
      if (localScreen) {
        localScreen.getTracks().forEach((t) => t.stop());
        localScreen = null;
      }

      sharing = false;
      shareLabel.textContent = 'Share my screen';
      shareBtn.classList.remove('is-on');
      stage.hidden = true;
      stageIdle.hidden = false;
      say('');
      signal('bye', null, {});
    }

    shareBtn.addEventListener('click', () => (sharing ? stopSharing() : startSharing()));

    micBtn.addEventListener('click', async () => {
      if (localMic) {
        localMic.getTracks().forEach((t) => t.stop());
        localMic = null;
        micLabel.textContent = 'Turn on microphone';
        micBtn.setAttribute('aria-pressed', 'false');
        micBtn.classList.remove('is-on');
        return;
      }

      try {
        localMic = await navigator.mediaDevices.getUserMedia({ audio: true });
      } catch (e) {
        say('No microphone available, or permission was refused.', 'warn');
        return;
      }

      micLabel.textContent = 'Mute microphone';
      micBtn.setAttribute('aria-pressed', 'true');
      micBtn.classList.add('is-on');

      Object.keys(peers).forEach((id) => {
        localMic.getTracks().forEach((t) => peers[id].addTrack(t, localMic));
        offerTo(id);
      });
    });

    /* -- polling ------------------------------------------------------ */

    function pollSignals() {
      fetch(base + '/signals?peer=' + encodeURIComponent(myPeer) + '&since=' + sinceSignal, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
        .then((r) => r.json())
        .then((data) => {
          if (!data.ok) return;
          sinceSignal = data.last || sinceSignal;
          (data.signals || []).forEach(handle);
        })
        .catch(() => { /* a dropped poll is not worth reporting */ });
    }

    let lastNote = parseInt(cfg.dataset.lastNote || '0', 10);
    const noteBox = $('[data-notes]');

    function renderNote(n) {
      const wrap = document.createElement('div');
      wrap.className = 'note note--' + n.kind;

      const meta = document.createElement('div');
      meta.className = 'note__meta';

      const who = document.createElement('span');
      who.className = 'note__who';
      who.textContent = n.author_name;

      const at = document.createElement('span');
      at.className = 'note__at';
      at.textContent = (n.created_at || '').slice(11, 16);

      meta.appendChild(who);
      meta.appendChild(at);

      const body = document.createElement('div');
      body.className = 'note__body';
      body.textContent = n.body;          // textContent, never innerHTML

      wrap.appendChild(meta);
      wrap.appendChild(body);
      noteBox.appendChild(wrap);
      noteBox.scrollTop = noteBox.scrollHeight;
    }

    function pollNotes() {
      fetch(base + '/notes?since=' + lastNote, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
        .then((r) => r.json())
        .then((data) => {
          if (!data.ok) return;
          (data.notes || []).forEach((n) => { renderNote(n); lastNote = n.id; });
        })
        .catch(() => {});
    }

    const noteForm = $('[data-note-form]');

    noteForm.addEventListener('submit', (e) => {
      e.preventDefault();

      const body = noteForm.querySelector('[name=body]').value.trim();
      if (!body) return;

      const kind = (noteForm.querySelector('[name=kind]:checked') || {}).value || 'note';

      post('/notes', { body: body, kind: kind }).then((data) => {
        if (data.ok && data.note) { renderNote(data.note); lastNote = data.note.id; }
        noteForm.querySelector('[name=body]').value = '';
      });
    });

    // Announce ourselves, then keep listening.
    signal('hello', null, { name: meName });
    setInterval(pollSignals, 1500);
    setInterval(pollNotes, 4000);
    noteBox.scrollTop = noteBox.scrollHeight;

    // Leaving without saying so leaves everyone else watching a frozen
    // picture, so tell them on the way out.
    window.addEventListener('beforeunload', () => {
      navigator.sendBeacon(
        base + '/signal',
        new URLSearchParams({ _token: csrf(), from: myPeer, kind: 'bye', payload: '{}' })
      );
    });

    const leave = $('[data-leave]');
    if (leave) leave.addEventListener('click', (e) => { e.preventDefault(); stopSharing(); window.close(); });
  }

  /** Another blank row of guest fields on the meeting form. */
  function initGuestRows() {
    const btn = $('[data-add-guest]');
    const box = $('[data-guest-rows]');
    if (!btn || !box) return;

    btn.addEventListener('click', () => {
      const row = box.lastElementChild.cloneNode(true);
      $$('input', row).forEach((i) => { i.value = ''; });
      box.appendChild(row);
      row.querySelector('input').focus();
    });
  }

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
  /**
   * On the user form, the "main role" select and the role tick-boxes describe
   * the same thing, so they must not be able to disagree. Whichever role is
   * chosen as the main one stays ticked and cannot be cleared here.
   *
   * A disabled checkbox is not submitted, which is fine: the server adds the
   * main role back regardless, so the two can never drift apart even if this
   * script never runs.
   */
  /**
   * Dark and light.
   *
   * The choice is applied by a small script in <head> so the page never
   * paints the wrong theme first; this only handles switching and the icon.
   * Dark is the default, so "dark" is still written to storage explicitly —
   * otherwise someone who switches to light and back would be indistinguish-
   * able from someone who never chose, which matters if the default changes.
   */
  /**
   * "Days in a cycle" only means anything for a custom billing cycle, so it
   * stays hidden until that is chosen. Hidden rather than removed: the value
   * still posts, so switching to Custom and back does not lose what was typed.
   */
  function initCycleDays() {
    var cycle = document.querySelector('[data-cycle]');
    var field = document.querySelector('[data-cycle-days]');
    if (!cycle || !field) return;

    function sync() { field.hidden = cycle.value !== 'custom'; }

    cycle.addEventListener('change', sync);
    sync();
  }

  function initTheme() {
    var root = document.documentElement;
    var KEY  = 'shanfix-theme';

    function current() {
      return root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    }

    function paintIcon() {
      var showing = current();
      document.querySelectorAll('[data-theme-icon]').forEach(function (el) {
        // Offer the mode you would move to, not the one you are in.
        el.hidden = el.getAttribute('data-theme-icon') === showing;
      });
    }

    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var next = current() === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);

        try { localStorage.setItem(KEY, next); } catch (e) { /* not fatal */ }

        paintIcon();
      });
    });

    paintIcon();
  }

  function initRoleMatrix() {
    var select = document.getElementById('role');
    if (!select) return;

    var rows = document.querySelectorAll('[data-role-option]');
    if (!rows.length) return;

    var previous = select.value;

    // Anything ticked by hand is the person's own decision and is left alone.
    rows.forEach(function (row) {
      var box = row.querySelector('input[type="checkbox"]');
      if (box) box.addEventListener('change', function () { box.dataset.userSet = '1'; });
    });

    function sync() {
      rows.forEach(function (row) {
        var box = row.querySelector('input[type="checkbox"]');
        if (!box) return;

        var role = row.getAttribute('data-role-option');

        if (role === select.value) {
          box.checked = true;
          box.disabled = true;
          row.title = 'This is the main role and is always included.';
          return;
        }

        box.disabled = false;
        row.removeAttribute('title');

        // The role that was the main one a moment ago: clear it, unless the
        // account already held it or someone ticked it deliberately.
        // Without this, changing the main role from Staff to Reception would
        // quietly leave Staff assigned as well.
        if (role === previous && !box.dataset.userSet && !box.hasAttribute('data-held')) {
          box.checked = false;
        }
      });

      previous = select.value;
    }

    select.addEventListener('change', sync);
    sync();
  }

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
    initImagePreview();
    initPasswordToggle();
    initPrint();
    initSelectOnFocus();
    initAutoFilters();
    initSubmitGuard();
    initLineItems();
    initJobItems();
    initStkPolling();
    initChat();
    initMeetingRoom();
    initWhatsApp();
    initGuestRows();
    initUnreadPoll();
    initLinkedSelects();
    initRoleMatrix();
    initTheme();
    initCycleDays();
    initQuickOpen();
    initStackTables();
    initLightbox();
  });

  /* ------------------------------------------------------------------
     Quick open
     ------------------------------------------------------------------
     Getting to a record was: reach for the mouse, click the search box,
     type, Enter, read a page of results, click the right one. For anyone
     who does that forty times a day it is most of the work.

     This is Ctrl-K (or "/"), type, arrow down, Enter. The rows come from
     the same data the search page uses, through the same permission
     checks — a JSON route is not a way around them.
     ------------------------------------------------------------------ */
  function initQuickOpen() {
    let box = null, input = null, list = null;
    let items = [], active = -1, timer = null, seq = 0;

    function build() {
      if (box) return box;

      box = document.createElement('div');
      box.className = 'quickopen';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-label', 'Quick open');
      box.innerHTML =
        '<div class="quickopen__panel">' +
          '<input class="quickopen__input" type="search" autocomplete="off" spellcheck="false"' +
                ' placeholder="Search clients, documents, jobs, leads…" aria-label="Search everything">' +
          '<div class="quickopen__list" role="listbox"></div>' +
          '<div class="quickopen__hint">' +
            '<span><kbd>↑</kbd><kbd>↓</kbd> move</span>' +
            '<span><kbd>Enter</kbd> open</span>' +
            '<span><kbd>Esc</kbd> close</span>' +
          '</div>' +
        '</div>';

      document.body.appendChild(box);
      input = $('.quickopen__input', box);
      list  = $('.quickopen__list', box);

      box.addEventListener('click', (e) => { if (e.target === box) close(); });
      input.addEventListener('input', schedule);
      input.addEventListener('keydown', onKey);

      return box;
    }

    function open() {
      build();
      box.classList.add('is-open');
      document.body.classList.add('quickopen-open');
      input.value = '';
      render([]);
      input.focus();
    }

    function close() {
      if (!box) return;
      box.classList.remove('is-open');
      document.body.classList.remove('quickopen-open');
    }

    function schedule() {
      clearTimeout(timer);
      // Long enough that a fast typist sends one request rather than six.
      timer = setTimeout(search, 160);
    }

    function search() {
      const q = input.value.trim();
      if (q.length < 2) { render([]); return; }

      // Replies can arrive out of order; only the newest may draw.
      const mine = ++seq;

      fetch(basePath() + '/search/quick?q=' + encodeURIComponent(q), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      })
        .then((r) => (r.ok ? r.json() : { results: [] }))
        .then((data) => { if (mine === seq) render(data.results || []); })
        .catch(() => { if (mine === seq) render([]); });
    }

    function render(rows) {
      items = rows;
      active = rows.length ? 0 : -1;

      if (!rows.length) {
        list.innerHTML = input.value.trim().length >= 2
          ? '<div class="quickopen__empty">Nothing matching that.</div>'
          : '';
        return;
      }

      list.innerHTML = rows.map((r, i) =>
        '<a class="quickopen__row' + (i === 0 ? ' is-active' : '') + '"' +
        ' role="option" aria-selected="' + (i === 0) + '"' +
        ' href="' + esc(r.url) + '" data-i="' + i + '">' +
          '<span class="quickopen__kind">' + esc(r.kind) + '</span>' +
          '<span class="quickopen__label">' + esc(r.label) + '</span>' +
          (r.meta ? '<span class="quickopen__meta">' + esc(r.meta) + '</span>' : '') +
        '</a>'
      ).join('');

      $$('.quickopen__row', list).forEach((el) => {
        el.addEventListener('mouseenter', () => setActive(Number(el.dataset.i)));
      });
    }

    function setActive(i) {
      if (!items.length) return;
      active = (i + items.length) % items.length;

      $$('.quickopen__row', list).forEach((el, k) => {
        const on = k === active;
        el.classList.toggle('is-active', on);
        el.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) el.scrollIntoView({ block: 'nearest' });
      });
    }

    function onKey(e) {
      if (e.key === 'ArrowDown')    { e.preventDefault(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Escape')  { e.preventDefault(); close(); }
      else if (e.key === 'Enter' && active >= 0 && items[active]) {
        e.preventDefault();
        window.location.href = items[active].url;
      }
    }

    function esc(v) {
      return String(v == null ? '' : v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function basePath() {
      const m = document.querySelector('meta[name="app-base"]');
      return m ? (m.content || '') : '';
    }

    document.addEventListener('keydown', (e) => {
      if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        open();
        return;
      }

      // "/" is a shortcut only when it is not being typed into something.
      const tag = (e.target.tagName || '').toLowerCase();
      const typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;

      if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault();
        open();
      }
    });
  }

  /* ------------------------------------------------------------------
     Tables that stack on a phone
     ------------------------------------------------------------------
     A list here is nine to eleven columns wide. On a 360px screen that
     means scrolling sideways to read one row, and scrolling back to read
     the next — which is how a delivery driver ends up ringing the office
     to ask what they are delivering.

     Rather than editing every table by hand, this copies each column
     heading onto its cells. The stylesheet then turns each row into a
     small card below the breakpoint, with the heading printed beside the
     value. Above it nothing changes.

     Only tables that are laid out plainly are touched: a colspan means
     the cells no longer line up with the headings, and a wrong label is
     worse than none.
     ------------------------------------------------------------------ */
  function initStackTables() {
    $$('.table').forEach((table) => {
      const head = $('thead tr', table);
      if (!head) return;

      const labels = $$('th', head).map((th) => (th.textContent || '').trim());
      if (!labels.length) return;

      const rows = $$('tbody tr', table);
      let usable = true;

      rows.forEach((tr) => {
        const cells = $$('td', tr);
        if (!cells.length) return;

        // A row that spans columns cannot be matched to the headings.
        if (cells.length !== labels.length) { usable = false; return; }
        if (cells.some((td) => td.colSpan > 1)) { usable = false; }
      });

      if (!usable) return;

      rows.forEach((tr) => {
        $$('td', tr).forEach((td, i) => {
          const label = labels[i];
          // An empty heading is a column of buttons or an avatar; giving
          // it a label would print a stray colon on a card.
          if (label) td.setAttribute('data-label', label);
        });
      });

      table.classList.add('table--stacks');
    });
  }

  /* ------------------------------------------------------------------
     Image viewer
     ------------------------------------------------------------------
     Clicking a product photo opened it as a bare file in a new tab: no
     way to reach the next one without going back, and on a phone it left
     the system entirely. This keeps you on the page and lets you move
     through the set with arrows, a swipe, or the buttons.

     Any element marked data-gallery="name" joins that group, in document
     order. Nothing else needs to know about it.
     ------------------------------------------------------------------ */
  function initLightbox() {
    let box = null, imgEl = null, capEl = null, countEl = null;
    let group = [], at = 0, lastFocus = null;

    function build() {
      if (box) return box;

      box = document.createElement('div');
      box.className = 'lightbox';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-label', 'Image viewer');
      box.innerHTML =
        '<button class="lightbox__close" type="button" aria-label="Close viewer">&times;</button>' +
        '<button class="lightbox__nav lightbox__nav--prev" type="button" aria-label="Previous image">&#8249;</button>' +
        '<figure class="lightbox__stage">' +
          '<img class="lightbox__img" alt="">' +
          '<figcaption class="lightbox__cap"></figcaption>' +
        '</figure>' +
        '<button class="lightbox__nav lightbox__nav--next" type="button" aria-label="Next image">&#8250;</button>' +
        '<div class="lightbox__count" aria-live="polite"></div>';

      document.body.appendChild(box);
      imgEl   = $('.lightbox__img', box);
      capEl   = $('.lightbox__cap', box);
      countEl = $('.lightbox__count', box);

      $('.lightbox__close', box).addEventListener('click', close);
      $('.lightbox__nav--prev', box).addEventListener('click', () => step(-1));
      $('.lightbox__nav--next', box).addEventListener('click', () => step(1));

      // Clicking the backdrop closes; clicking the picture does not.
      box.addEventListener('click', (e) => {
        if (e.target === box || e.target.classList.contains('lightbox__stage')) close();
      });

      let startX = null;
      box.addEventListener('touchstart', (e) => { startX = e.touches[0].clientX; }, { passive: true });
      box.addEventListener('touchend', (e) => {
        if (startX === null) return;
        const dx = e.changedTouches[0].clientX - startX;
        if (Math.abs(dx) > 45) step(dx < 0 ? 1 : -1);
        startX = null;
      });

      return box;
    }

    function show(i) {
      at = (i + group.length) % group.length;
      const a = group[at];

      imgEl.src = a.getAttribute('href') || a.dataset.full || '';
      imgEl.alt = a.dataset.caption || ($('img', a) || {}).alt || '';
      capEl.textContent = a.dataset.caption || '';
      capEl.style.display = a.dataset.caption ? '' : 'none';

      countEl.textContent = group.length > 1 ? (at + 1) + ' of ' + group.length : '';
      box.classList.toggle('is-single', group.length < 2);
    }

    function step(by) { if (group.length > 1) show(at + by); }

    function open(a) {
      build();
      const name = a.dataset.gallery;
      lastFocus = document.activeElement;

      // The same picture often appears twice on a page — once large and
      // again as its own thumbnail. Collapsed by source, so the set is
      // the pictures there are rather than the links to them, and
      // clicking either one lands on the same place in the set.
      const seen = new Map();

      $$('[data-gallery="' + name + '"]').forEach((el) => {
        const src = el.getAttribute('href') || el.dataset.full || '';
        if (!seen.has(src)) seen.set(src, el);
      });

      group = Array.from(seen.values());

      const src = a.getAttribute('href') || a.dataset.full || '';
      show(Math.max(0, group.indexOf(seen.get(src))));
      box.classList.add('is-open');
      document.body.classList.add('lightbox-open');
      $('.lightbox__close', box).focus();
    }

    function close() {
      if (!box) return;
      box.classList.remove('is-open');
      document.body.classList.remove('lightbox-open');
      imgEl.src = '';
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.addEventListener('click', (e) => {
      const a = e.target.closest('[data-gallery]');
      if (!a) return;
      e.preventDefault();
      open(a);
    });

    document.addEventListener('keydown', (e) => {
      if (!box || !box.classList.contains('is-open')) return;
      if (e.key === 'Escape')          { e.preventDefault(); close(); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
      else if (e.key === 'ArrowLeft')  { e.preventDefault(); step(-1); }
    });
  }

  window.Shanfix = { toast, openModal };
})();
