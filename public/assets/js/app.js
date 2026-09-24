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
     Sidebar groups

     The markup is <details>, so opening and closing already works with
     the scripts off. This adds the two things it cannot do by itself:
     remembering what you left open between pages, and telling you that
     something is waiting inside a section you have shut.

     The section holding the page you are on is opened by the server and
     is left alone here — reopening it from a stored value would fight
     the highlight.
     ------------------------------------------------------------------ */
  function initNavGroups() {
    const groups = $$('.nav-group[data-nav-group]');
    if (!groups.length) return;

    const key = (g) => 'sf.nav.' + g.dataset.navGroup;

    // A private window, or storage the browser has turned off, must not
    // cost us the menu.
    const remember = (g) => {
      try { localStorage.setItem(key(g), g.open ? '1' : '0'); } catch (e) { /* nothing to do */ }
    };
    const recall = (g) => {
      try { return localStorage.getItem(key(g)); } catch (e) { return null; }
    };

    /* A shut section borrows the badges of the links inside it: if any of
       them is showing a number, the section shows a dot. Badges arrive
       later over fetch, so this runs again whenever the nav changes
       rather than only at load. */
    const syncDots = () => {
      groups.forEach((g) => {
        const waiting = $$('.nav-link__badge', g).some(
          (b) => !b.classList.contains('hidden') && b.textContent.trim() !== ''
        );
        g.classList.toggle('has-waiting', waiting);
      });
    };

    groups.forEach((g) => {
      const holdsCurrentPage = !!$('.nav-link.is-active', g);

      if (!holdsCurrentPage && recall(g) !== null) {
        g.open = recall(g) === '1';
      }

      g.addEventListener('toggle', () => { remember(g); syncDots(); });
    });

    syncDots();

    const nav = $('.sidebar__nav');
    if (nav && window.MutationObserver) {
      new MutationObserver(syncDots).observe(nav, {
        subtree: true,
        childList: true,
        characterData: true,
        attributes: true,
        attributeFilter: ['class'],
      });
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

    // What the room used to get wrong, and this now does instead:
    //
    //  - It only connected people once somebody shared a screen, so in an
    //    ordinary meeting with microphones on nobody heard anybody. Now
    //    everybody is connected to everybody as they arrive, and tracks
    //    are added to those connections whenever they are switched on.
    //  - Both sides could offer at once and one would throw. It now uses
    //    "perfect negotiation": one side of each pair is polite and gives
    //    way when offers cross.
    //  - Signals were handled concurrently, so a network candidate could be
    //    applied before the description it belonged to, and be dropped.
    //    They are now handled one at a time, in order, and early
    //    candidates wait for their description.
    //  - Voices played through the same element as the shared screen, so a
    //    voice arriving replaced the picture, and a presenter (whose player
    //    was muted to avoid their own echo) heard nobody. Each voice now has
    //    its own <audio> element.
    //  - Polls overlapped (setInterval, 1.5s, no matter how long a poll
    //    took) and could deliver the same signal twice.

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
    const rosterEl   = $('[data-roster]');
    const audioBox   = $('[data-audio]');

    /** peer id -> { pc, polite, makingOffer, ignoreOffer, pending, name, screenSenders } */
    const peers = {};

    let localScreen = null;
    let localMic    = null;
    let sharing     = false;
    let watching    = null;   // whose screen is on the stage
    let sinceSignal = parseInt(cfg.dataset.lastSignal || '0', 10);

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

    /* -- who is here ---------------------------------------------------- */

    function renderRoster() {
      // The first item is always "you"; everything after it is redrawn.
      while (rosterEl.children.length > 1) rosterEl.removeChild(rosterEl.lastChild);

      Object.keys(peers).forEach((id) => {
        const li = document.createElement('li');
        const dot = document.createElement('span');
        const state = peers[id].pc.connectionState;
        dot.className = 'roster__dot' + (state === 'failed' ? ' roster__dot--bad' : '');
        li.appendChild(dot);
        li.appendChild(document.createTextNode(peers[id].name));   // never innerHTML
        rosterEl.appendChild(li);
      });

      if (!Object.keys(peers).length && !sharing) {
        say('You are the only one here so far.');
      }
    }

    /* -- the stage and the voices -------------------------------------- */

    function showStage(stream, muted) {
      stage.srcObject = stream;
      stage.muted = muted;
      stage.hidden = false;
      stageIdle.hidden = true;
    }

    function clearStage() {
      stage.srcObject = null;
      stage.hidden = true;
      stageIdle.hidden = false;
      watching = null;
    }

    function playVoice(peerId, track) {
      const el = document.createElement('audio');
      el.autoplay = true;
      el.dataset.peer = peerId;
      el.dataset.track = track.id;
      el.srcObject = new MediaStream([track]);
      audioBox.appendChild(el);
      el.play().catch(() => {
        // A browser that refuses to play sound until the page is touched.
        say('Click anywhere in the room to hear the others.', 'warn');
        document.addEventListener('click', () => el.play().catch(() => {}), { once: true });
      });
      track.addEventListener('ended', () => el.remove());
    }

    /* -- connections ---------------------------------------------------- */

    function peer(id, name) {
      if (peers[id]) {
        if (name) peers[id].name = name;
        return peers[id];
      }

      const pc = new RTCPeerConnection({ iceServers: ice });
      const p = {
        pc: pc,
        // One side of each pair gives way when offers cross. Which one is
        // decided by comparing ids, so both sides agree without talking.
        polite: myPeer < id,
        makingOffer: false,
        ignoreOffer: false,
        pending: [],
        name: name || 'Someone',
        screenSenders: [],
        seen: Date.now(),
      };
      peers[id] = p;

      pc.onicecandidate = (e) => { if (e.candidate) signal('ice', id, e.candidate); };

      // Whenever a track is added or removed, the browser asks for a new
      // offer. This is the only place offers are made.
      pc.onnegotiationneeded = async () => {
        try {
          p.makingOffer = true;
          await pc.setLocalDescription();
          signal('offer', id, pc.localDescription);
        } catch (e) {
          /* the next negotiationneeded will try again */
        } finally {
          p.makingOffer = false;
        }
      };

      pc.ontrack = (e) => {
        const track = e.track;

        if (track.kind === 'audio') {
          playVoice(id, track);
          return;
        }

        // Video is only ever a shared screen. A presenter keeps looking at
        // their own; everybody else watches whoever shares.
        if (sharing) return;
        watching = id;
        showStage(new MediaStream([track]), true);   // its sound plays via playVoice
        say('Watching ' + p.name + "'s screen.", 'ok');
        track.addEventListener('ended', () => { if (watching === id) { clearStage(); say(''); } });
      };

      pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'failed') {
          // Almost always both ends behind strict NAT with no relay set up.
          say('Could not connect to ' + p.name + '. Your administrator can add a TURN relay under Settings → Meetings.', 'warn');
        }
        renderRoster();
      };

      // Whatever we are already sending goes to a newcomer too.
      if (localMic) localMic.getTracks().forEach((t) => pc.addTrack(t, localMic));
      if (localScreen) {
        localScreen.getTracks().forEach((t) => p.screenSenders.push(pc.addTrack(t, localScreen)));
      }

      renderRoster();
      return p;
    }

    function drop(id) {
      const p = peers[id];
      if (!p) return;
      p.pc.close();
      delete peers[id];
      audioBox.querySelectorAll('audio[data-peer="' + id + '"]').forEach((el) => el.remove());
      if (watching === id) { clearStage(); say(''); }
      renderRoster();
    }

    /* -- signals, one at a time and in order ------------------------------ */

    let queue = Promise.resolve();
    function enqueue(sig) {
      queue = queue.then(() => handle(sig)).catch(() => { /* one bad signal must not stop the rest */ });
    }

    async function handle(sig) {
      const from = sig.from_peer;
      let payload = null;

      try { payload = sig.payload ? JSON.parse(sig.payload) : null; } catch (e) { return; }

      if (sig.kind === 'bye') { drop(from); return; }

      // Anything a peer sends is proof they are still here.
      if (peers[from]) peers[from].seen = Date.now();

      // The heartbeat. From somebody we have not met — their hello was
      // missed — it introduces them as well.
      if (sig.kind === 'here') {
        peer(from, payload && payload.name).seen = Date.now();
        return;
      }

      if (sig.kind === 'hello') {
        peer(from, payload && payload.name);
        // Answer a room-wide hello so the newcomer learns we are here too.
        // Only broadcasts get a reply — answering a directed one would have
        // the two of us greeting each other for ever.
        if (!sig.to_peer) signal('hello', from, { name: meName });
        return;
      }

      if (sig.kind === 'unshare') { if (watching === from) { clearStage(); say(''); } return; }

      // An offer from somebody whose hello we missed is still an offer.
      const p = peer(from);
      const pc = p.pc;

      if (sig.kind === 'offer' || sig.kind === 'answer') {
        const collision = sig.kind === 'offer' && (p.makingOffer || pc.signalingState !== 'stable');
        p.ignoreOffer = !p.polite && collision;
        if (p.ignoreOffer) return;

        await pc.setRemoteDescription(payload);   // the polite side rolls back implicitly

        // Candidates that arrived before this description can be used now.
        const early = p.pending.splice(0);
        for (const c of early) { try { await pc.addIceCandidate(c); } catch (e) { /* stale */ } }

        if (sig.kind === 'offer') {
          await pc.setLocalDescription();
          signal('answer', from, pc.localDescription);
        }
        return;
      }

      if (sig.kind === 'ice') {
        if (!pc.remoteDescription) { p.pending.push(payload); return; }
        try { await pc.addIceCandidate(payload); } catch (e) { if (!p.ignoreOffer) { /* stale */ } }
      }
    }

    /* -- the microphone -------------------------------------------------- */

    function micOn(on) {
      micLabel.textContent = on ? 'Mute microphone' : 'Unmute microphone';
      micBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      micBtn.classList.toggle('is-on', on);
    }

    micBtn.addEventListener('click', async () => {
      if (localMic) {
        // Muting pauses the track rather than removing it, so nobody has to
        // renegotiate and unmuting is instant.
        const track = localMic.getAudioTracks()[0];
        track.enabled = !track.enabled;
        micOn(track.enabled);
        say(track.enabled ? 'Your microphone is on.' : 'You are muted.', track.enabled ? 'ok' : '');
        return;
      }

      try {
        localMic = await navigator.mediaDevices.getUserMedia({ audio: true });
      } catch (e) {
        say('No microphone available, or permission was refused.', 'warn');
        return;
      }

      micOn(true);
      say('Your microphone is on.', 'ok');
      Object.keys(peers).forEach((id) => {
        localMic.getTracks().forEach((t) => peers[id].pc.addTrack(t, localMic));
      });
    });

    /* -- sharing --------------------------------------------------------- */

    async function startSharing() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
        say('This browser cannot share a screen. Chrome, Edge or Firefox on a computer can.', 'warn');
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

      showStage(localScreen, true);   // never play your own audio back
      say('You are sharing your screen.', 'ok');

      // Stopping from the browser's own bar must tidy up here too.
      localScreen.getVideoTracks()[0].addEventListener('ended', stopSharing);

      Object.keys(peers).forEach((id) => {
        const p = peers[id];
        localScreen.getTracks().forEach((t) => p.screenSenders.push(p.pc.addTrack(t, localScreen)));
      });
    }

    function stopSharing() {
      if (!sharing) return;
      sharing = false;

      // Take the screen off every connection but leave the connection up:
      // the microphone may still be running over it.
      Object.keys(peers).forEach((id) => {
        const p = peers[id];
        p.screenSenders.forEach((s) => { try { p.pc.removeTrack(s); } catch (e) { /* already gone */ } });
        p.screenSenders = [];
      });

      if (localScreen) {
        localScreen.getTracks().forEach((t) => t.stop());
        localScreen = null;
      }

      shareLabel.textContent = 'Share my screen';
      shareBtn.classList.remove('is-on');
      clearStage();
      say('');
      signal('unshare', null, {});
    }

    shareBtn.addEventListener('click', () => (sharing ? stopSharing() : startSharing()));

    /* -- polling ---------------------------------------------------------- */

    // One poll at a time: the next starts only when the last has finished,
    // so a slow response can never be overtaken and delivered twice.
    function pollSignals() {
      fetch(base + '/signals?peer=' + encodeURIComponent(myPeer) + '&since=' + sinceSignal, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
        .then((r) => r.json())
        .then((data) => {
          if (!data.ok) return;
          sinceSignal = data.last || sinceSignal;
          (data.signals || []).forEach(enqueue);
        })
        .catch(() => { /* a dropped poll is not worth reporting */ })
        .then(() => setTimeout(pollSignals, 1200));
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
          (data.notes || []).forEach((n) => {
            // Our own note is drawn when it is saved; skip it coming back.
            if (Number(n.id) > lastNote) { renderNote(n); lastNote = Number(n.id); }
          });
        })
        .catch(() => {});
    }

    const noteForm = $('[data-note-form]');
    const noteText = noteForm.querySelector('[name=body]');

    function sendNote() {
      const body = noteText.value.trim();
      if (!body) return;

      const kind = (noteForm.querySelector('[name=kind]:checked') || {}).value || 'note';

      post('/notes', { body: body, kind: kind }).then((data) => {
        if (data.ok && data.note && Number(data.note.id) > lastNote) { renderNote(data.note); lastNote = Number(data.note.id); }
        noteText.value = '';
      });
    }

    noteForm.addEventListener('submit', (e) => { e.preventDefault(); sendNote(); });

    // Enter adds the note; Shift+Enter is a new line.
    noteText.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendNote(); }
    });

    // Announce ourselves, then keep listening.
    renderRoster();
    signal('hello', null, { name: meName });
    pollSignals();

    // A browser that vanishes without a 'bye' — a crash, a lost network, a
    // closed laptop — would otherwise stay in everybody's list for good.
    // Each tab checks in every 10 seconds; anybody silent for 35 is gone.
    setInterval(() => signal('here', null, { name: meName }), 10000);
    setInterval(() => {
      const cutoff = Date.now() - 35000;
      Object.keys(peers).forEach((id) => { if (peers[id].seen < cutoff) drop(id); });
    }, 5000);
    setInterval(pollNotes, 4000);
    noteBox.scrollTop = noteBox.scrollHeight;

    // Leaving without saying so leaves everyone else talking to nobody, so
    // tell them on the way out.
    window.addEventListener('beforeunload', () => {
      navigator.sendBeacon(
        base + '/signal',
        new URLSearchParams({ _token: csrf(), from: myPeer, kind: 'bye', payload: '{}' })
      );
    });

    const leave = $('[data-leave]');
    if (leave) {
      leave.addEventListener('click', (e) => {
        e.preventDefault();
        stopSharing();
        signal('bye', null, {}).then(() => window.close());
      });
    }
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
     Waiting live chats
     ------------------------------------------------------------------
     Counts only the people still waiting for a human, not everything
     unread. A number here means somebody is sitting on our website with
     an unanswered question, which is worth interrupting for; a count of
     everything would be a number nobody looks at twice.
     ------------------------------------------------------------------ */
  /* ------------------------------------------------------------------
     Live chat desk (staff side)
     ------------------------------------------------------------------
     The desk was built without a script, so a reply needed a full page
     load, nothing new appeared until somebody pressed refresh, Enter did
     not send despite the box saying it would, and choosing a saved reply
     did nothing. This is all of that.
     ------------------------------------------------------------------ */
  /* ------------------------------------------------------------------
     Being told a visitor is waiting

     The badge in the sidebar is a fine thing to look at and a poor thing
     to be told by. Since tawk.to came off the website, nothing made a
     sound when a stranger started a chat — so this does: a short two-note
     chime, and the tab title flashing until somebody looks.

     Both are deliberately cheap to ignore. The sound can be silenced per
     person on this machine, the title goes back to normal the moment the
     tab is focused, and neither ever fires on the first poll of a page
     — only when the number of people waiting actually goes up. Being
     chimed at on every navigation is how people turn the sound off for
     good.
     ------------------------------------------------------------------ */
  const chime = (function () {
    const KEY = 'sf.livechat.sound';

    let audio   = null;    // made on first use: a context built before a
                           // click is created suspended and stays silent
    let original = null;   // the real document title, while it is flashing
    let flashing = null;

    /** Whether this machine should make a noise. */
    function wanted() {
      try {
        const saved = localStorage.getItem(KEY);
        if (saved !== null) return saved === '1';
      } catch (e) { /* private window, or storage switched off */ }

      // Nothing chosen here, so fall back to what the office set. The
      // desk publishes it; away from the desk, assume yes.
      const el = $('[data-chime]');
      return !el || el.dataset.default !== '0';
    }

    function setWanted(on) {
      try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) { /* as above */ }
    }

    /**
     * Two short notes, synthesised rather than fetched.
     *
     * A sound file would be one more asset to ship, cache and get wrong
     * on a slow connection, for about a fifth of a second of audio.
     *
     * Browsers refuse to start audio until the person has interacted
     * with the page. Whoever is working the desk has clicked something,
     * so in practice it plays; when it does not, resume() is asked and
     * the failure is swallowed rather than thrown into the console on a
     * loop.
     */
    function play() {
      if (!wanted()) return;

      try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;

        if (!audio) audio = new Ctx();
        if (audio.state === 'suspended') audio.resume().catch(() => {});

        [[660, 0], [880, 0.12]].forEach(([hz, at]) => {
          const osc  = audio.createOscillator();
          const gain = audio.createGain();

          osc.type = 'sine';
          osc.frequency.value = hz;

          // Faded in and out rather than switched: a square edge on a
          // sine wave is an audible click at the start of every note.
          const t = audio.currentTime + at;
          gain.gain.setValueAtTime(0.0001, t);
          gain.gain.exponentialRampToValueAtTime(0.22, t + 0.02);
          gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.11);

          osc.connect(gain).connect(audio.destination);
          osc.start(t);
          osc.stop(t + 0.13);
        });
      } catch (e) { /* no audio here; the title still flashes */ }
    }

    /** Alternate the tab title until this tab is looked at. */
    function flash(text) {
      if (!document.hidden) return;       // they are already looking
      if (flashing) return;

      original = document.title;
      let on = false;

      flashing = setInterval(() => {
        document.title = (on = !on) ? text : original;
      }, 1200);

      const stop = () => {
        if (!flashing) return;
        clearInterval(flashing);
        flashing = null;
        document.title = original;
        document.removeEventListener('visibilitychange', check);
        window.removeEventListener('focus', stop);
      };
      const check = () => { if (!document.hidden) stop(); };

      document.addEventListener('visibilitychange', check);
      window.addEventListener('focus', stop);
    }

    return {
      wanted: wanted,
      setWanted: setWanted,
      /** Somebody new is waiting: make a noise and flash the title. */
      alert: function (n) {
        play();
        flash('(' + n + ') Somebody is waiting');
      },
    };
  })();

  /** The on/off button on the desk. */
  function initChimeToggle() {
    const btn = $('[data-chime]');
    if (!btn) return;

    const on  = $('[data-chime-on]', btn);
    const off = $('[data-chime-off]', btn);

    const paint = () => {
      const want = chime.wanted();
      btn.setAttribute('aria-pressed', want ? 'true' : 'false');
      on.hidden  = !want;
      off.hidden = want;
    };

    btn.addEventListener('click', () => {
      const want = !chime.wanted();
      chime.setWanted(want);
      paint();
      // Play it back, so "on" is something you hear rather than read.
      if (want) chime.alert(1);
    });

    paint();
  }

  function initLiveChatDesk() {
    const desk = $('[data-desk]');
    if (!desk) return;

    const pollUrl = desk.dataset.poll;
    const panel   = $('[data-conversation]', desk);
    let seenActive  = parseInt(desk.dataset.active || '0', 10);
    let seenWaiting = parseInt(desk.dataset.waiting || '0', 10);

    const list  = $('#lcMessages');
    const form  = $('#lcReply');
    const body  = $('#lcBody');
    const note  = form ? form.querySelector('[name=note]') : null;
    const btn   = form ? form.querySelector('button[type=submit], button:not([type])') : null;
    const convo = panel ? panel.dataset.conversation : null;

    let lastId = 0;
    if (list) {
      list.querySelectorAll('[data-mid]').forEach((el) => {
        lastId = Math.max(lastId, parseInt(el.dataset.mid, 10) || 0);
      });
      list.scrollTop = list.scrollHeight;
    }

    function draw(m) {
      if (list.querySelector('[data-mid="' + m.id + '"]')) return;

      const wrap = document.createElement('div');
      wrap.className = 'lc__msg lc__msg--' + m.sender + (m.note ? ' lc__msg--note' : '');
      wrap.dataset.mid = m.id;

      if (m.sender !== 'system') {
        const who = document.createElement('div');
        who.className = 'lc__msg-who';
        who.appendChild(document.createTextNode(m.who || (m.sender === 'staff' ? 'Us' : 'Visitor')));
        if (m.note) {
          const tag = document.createElement('span');
          tag.className = 'lc__note-tag';
          tag.textContent = 'private note';
          who.appendChild(tag);
        }
        const at = document.createElement('span');
        at.className = 'lc__msg-at';
        at.textContent = m.at || '';
        who.appendChild(at);
        wrap.appendChild(who);
      }

      const text = document.createElement('div');
      text.className = 'lc__msg-body';
      text.textContent = m.body;        // textContent, never innerHTML
      wrap.appendChild(text);

      const nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 80;
      list.appendChild(wrap);
      if (nearBottom || m.sender === 'staff') list.scrollTop = list.scrollHeight;
    }

    /* -- sending -------------------------------------------------------- */

    let sending = false;

    function send() {
      if (!form || sending) return;
      const text = body.value.trim();
      if (!text) { body.focus(); return; }

      sending = true;
      if (btn) btn.disabled = true;

      const data = new URLSearchParams();
      data.set('_token', csrf());
      data.set('message', text);
      if (note && note.checked) data.set('note', '1');

      fetch(form.action, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
        body: data,
      })
        .then((r) => r.json())
        .then((d) => {
          if (d && d.ok) {
            body.value = '';
            if (note) note.checked = false;   // a note is a one-off, never sticky
            poll();
          } else {
            alert((d && d.error) || 'That did not send. Please try again.');
          }
        })
        .catch(() => alert('That did not send. Check your connection and try again.'))
        .then(() => { sending = false; if (btn) btn.disabled = false; body.focus(); });
    }

    if (form) {
      form.addEventListener('submit', (e) => { e.preventDefault(); send(); });

      // Enter sends; Shift+Enter is a new line — what the box says, and
      // what every chat tool does.
      body.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
          e.preventDefault();
          send();
        }
      });

      // A saved reply goes into the box to be read and adjusted, not
      // straight to the customer.
      const canned = form.querySelector('.lc__canned');
      if (canned) {
        canned.addEventListener('change', () => {
          if (!canned.value) return;
          body.value = body.value.trim() ? body.value.trimEnd() + '\n' + canned.value : canned.value;
          canned.value = '';
          body.focus();
          body.setSelectionRange(body.value.length, body.value.length);
        });
      }

      body.focus();
    }

    /* -- keeping current ------------------------------------------------ */

    let notice = null;

    function queueChanged() {
      // Nothing half-typed to lose: just bring the queue up to date.
      if (!body || !body.value.trim()) { location.reload(); return; }

      if (notice) return;
      notice = document.createElement('button');
      notice.type = 'button';
      notice.className = 'lc__notice';
      notice.textContent = 'New activity in the queue — show it';
      notice.addEventListener('click', () => location.reload());
      const head = $('.chat__list', desk);
      head.insertBefore(notice, head.children[1] || null);
    }

    function poll() {
      const url = pollUrl + (convo ? '?conversation=' + encodeURIComponent(convo) + '&after=' + lastId : '');

      return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then((r) => r.json())
        .then((d) => {
          if (!d || !d.ok) return;

          (d.messages || []).forEach((m) => {
            draw(m);
            lastId = Math.max(lastId, m.id);
          });

          // A new conversation, or one becoming active again, changes the
          // counts. That is when the list on the left is out of date.
          const c = d.counts || {};
          if ((c.active || 0) > seenActive || (c.waiting || 0) > seenWaiting) {
            // Only a longer queue is worth a noise. A conversation going
            // from waiting to open is somebody doing their job.
            if ((c.waiting || 0) > seenWaiting) chime.alert(c.waiting);
            queueChanged();
          }
          seenActive  = c.active  || 0;
          seenWaiting = c.waiting || 0;
        })
        .catch(() => { /* a dropped poll is not worth reporting */ });
    }

    // Brisk while a conversation is open, gentler on the bare queue, and
    // slower still when the tab is in the background.
    (function loop() {
      poll().then(() => {
        const delay = document.hidden ? 15000 : (convo ? 3000 : 6000);
        setTimeout(loop, delay);
      });
    })();
  }

  /* ------------------------------------------------------------------
     Email
     ------------------------------------------------------------------ */
  function initMailbox() {
    // Ticking messages reveals what can be done to them.
    const bulk = $('#mbBulk');
    if (bulk) {
      const count = $('[data-bulk-count]', bulk);
      const sync = () => {
        const n = $$('.mb__pick:checked').length;
        bulk.hidden = n === 0;
        count.textContent = n + ' selected';
      };
      $$('.mb__pick').forEach((c) => c.addEventListener('change', sync));
    }

    // Choosing a folder in "Move to…" moves it straight away.
    $$('[data-move]').forEach((sel) => {
      sel.addEventListener('change', () => {
        if (!sel.value) return;
        const form = sel.form;
        let doInput = form.querySelector('input[name=do]');
        if (!doInput) {
          doInput = document.createElement('input');
          doInput.type = 'hidden';
          doInput.name = 'do';
          form.appendChild(doInput);
        }
        doInput.value = 'move';
        form.submit();
      });
    });

    // Pictures from the internet stay hidden until asked for: loading one
    // tells the sender the message was opened. The page says whether any
    // were held back; this only swaps the frame for one that loads them.
    const frame = $('[data-mail-body]');
    const show = $('[data-show-images]');
    if (frame && show) {
      show.addEventListener('click', () => {
        frame.src = frame.dataset.imagesSrc;
        show.closest('[data-images-note]').hidden = true;
      });
    }

    // Writing.
    const compose = $('[data-compose]');
    if (compose) {
      const ccBtn = $('[data-show-cc]', compose);
      if (ccBtn) {
        ccBtn.addEventListener('click', () => {
          $$('[data-cc-row]', compose).forEach((r) => { r.hidden = false; });
          ccBtn.hidden = true;
          $('#c_cc').focus();
        });
      }

      const attach = $('[data-attach]', compose);
      const list = $('[data-attach-list]', compose);
      if (attach && list) {
        const original = list.textContent;
        attach.addEventListener('change', () => {
          const files = Array.from(attach.files || []);
          if (!files.length) { list.textContent = original; return; }
          const mb = files.reduce((t, f) => t + f.size, 0) / 1048576;
          list.textContent = files.map((f) => f.name).join(', ') + ' (' + mb.toFixed(1) + ' MB)';
        });
      }

      // Ctrl+Enter sends, as in Outlook and Gmail. Plain Enter is a new
      // line: an email is written in paragraphs, unlike a chat.
      $('#c_text', compose).addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
          e.preventDefault();
          compose.requestSubmit();
        }
      });

      // A reply starts above the quoted message, not below it.
      const text = $('#c_text', compose);
      if (document.activeElement === text || text.hasAttribute('autofocus')) {
        text.focus();
        text.setSelectionRange(0, 0);
        text.scrollTop = 0;
      }

      compose.addEventListener('submit', () => {
        const btn = $('[data-send]', compose);
        btn.disabled = true;
        btn.textContent = 'Sending…';
      });
    }
  }

  function initMailBadge() {
    const badge = $('#mail-unread-badge');
    if (!badge || !badge.dataset.url) return;

    const refresh = () => {
      fetch(badge.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then((r) => r.json())
        .then((d) => {
          if (!d || !d.ok) return;
          badge.textContent = d.unread > 99 ? '99+' : d.unread;
          badge.classList.toggle('hidden', !d.unread);
        })
        .catch(() => {});
    };

    refresh();
    setInterval(refresh, 90000);
  }

  function initLiveChatBadge() {
    const badge = $('#livechat-waiting-badge');
    if (!badge) return;

    const url = badge.dataset.url;
    if (!url) return;

    // The desk has its own, faster loop and does its own chiming. Two
    // voices announcing the same visitor is worse than one.
    const atTheDesk = !!$('[data-desk]');

    // null until the first answer comes back, so opening a page with
    // three people already waiting is not announced as three arrivals.
    let seen = null;

    const refresh = () => {
      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then((r) => r.json())
        .then((d) => {
          if (!d.ok) return;
          badge.textContent = d.waiting > 99 ? '99+' : d.waiting;
          badge.classList.toggle('hidden', !d.waiting);

          if (!atTheDesk && seen !== null && d.waiting > seen) {
            chime.alert(d.waiting);
          }
          seen = d.waiting;
        })
        .catch(() => {});
    };

    refresh();
    setInterval(refresh, 20000);
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
    initNavGroups();
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
    initLiveChatBadge();
    initChimeToggle();
    initLiveChatDesk();
    initMailbox();
    initMailBadge();
    initLinkedSelects();
    initRoleMatrix();
    initTheme();
    initCycleDays();
    initQuickOpen();
    initStackTables();
    initLightbox();
    initPortalPay();
    initSmsCounter();
    initSmsTemplate();
    initSmsProgress();
    initSmsTopup();
    initSmsFilePreview();
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

  /* ------------------------------------------------------------------
     Watching an M-Pesa prompt, in the client portal
     ------------------------------------------------------------------
     Somebody has a prompt on their handset and is looking at this page
     wondering whether it worked. Without this they refresh, and a refresh
     re-posts the form and sends a second prompt.

     Stops on its own: once it is settled there is nothing more to say,
     and after three minutes an unanswered prompt has expired anyway.
     ------------------------------------------------------------------ */
  function initPortalPay() {
    const box = $('[data-pay-status]');
    if (!box) return;

    const url = box.dataset.payStatus;
    let tries = 0;

    function say(text, tone) {
      box.textContent = text;
      box.style.color = tone === 'good' ? 'var(--green-600)'
                      : tone === 'bad'  ? 'var(--red-600)' : '';
    }

    function tick() {
      // 36 checks at five seconds is three minutes, which is longer than
      // any prompt stays live.
      if (tries++ > 36) return;

      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : null))
        .then((data) => {
          if (!data || !data.ok) return;

          if (data.state === 'none') { setTimeout(tick, 5000); return; }

          if (data.state === 'pending') {
            say('Waiting for you to enter your M-Pesa PIN…');
            setTimeout(tick, 5000);
            return;
          }

          if (data.state === 'success') {
            say('Payment received' + (data.receipt ? ' — ' + data.receipt : '')
                + '. Reloading your invoice…', 'good');
            setTimeout(() => window.location.reload(), 1800);
            return;
          }

          say(data.message || 'That payment did not go through. You can try again.', 'bad');
        })
        .catch(() => { setTimeout(tick, 8000); });
    }

    tick();
  }

  /* ------------------------------------------------------------------
     Bulk SMS
     ------------------------------------------------------------------
     Three small things the SMS pages need:

     1. What a message will cost, live. The number of parts is the one
        surprise people hate about SMS — a single emoji drops a message
        from 160 characters a part to 70 — so it is worked out here with
        the same rules the server charges by, and shown as they type.
     2. Filling the box from a saved message.
     3. Watching something that takes a while: a campaign sending, or an
        M-Pesa prompt sitting on somebody's phone.
     ------------------------------------------------------------------ */

  // The GSM-7 alphabet. Anything outside it forces the whole message into
  // UCS-2, where a part is 70 characters instead of 160. Mirrors
  // Engine::isUnicode() on the server; if they ever disagree, the server
  // is right and this is only a warning.
  const GSM7 = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
             + '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà'
             + '|^€{}[]~\\';

  function smsSize(text) {
    let unicode = false;

    for (const ch of text) {
      if (GSM7.indexOf(ch) === -1) { unicode = true; break; }
    }

    const per = unicode ? 70 : 160;
    const len = [...text].length;

    return { length: len, unicode, parts: Math.max(1, Math.ceil(len / per)), per };
  }

  function initSmsCounter() {
    $$('[data-sms-counter]').forEach((box) => {
      const out = $(box.dataset.smsCounter);
      if (!out) return;

      const people = () => {
        const field = $('#recipients');
        if (!field) return 0;
        return field.value.split(/[\s,;]+/).filter((n) => n.trim() !== '').length;
      };

      const draw = () => {
        const s = smsSize(box.value);
        let text = s.length + ' character' + (s.length === 1 ? '' : 's') + ' · '
                 + s.parts + ' part' + (s.parts === 1 ? '' : 's') + ' each';

        if (s.unicode) text += ' (special characters — 70 per part)';

        const n = people();
        if (n > 0) text += ' · about ' + (n * s.parts) + ' units for ' + n + ' recipient' + (n === 1 ? '' : 's');

        out.textContent = text;
      };

      box.addEventListener('input', draw);
      const to = $('#recipients');
      if (to) to.addEventListener('input', draw);
      draw();
    });
  }

  function initSmsTemplate() {
    $$('[data-sms-template]').forEach((select) => {
      select.addEventListener('change', () => {
        const box = $('#message');
        if (!box || select.value === '') return;
        box.value = select.value;
        box.dispatchEvent(new Event('input'));
        box.focus();
      });
    });
  }

  /* A campaign that is still sending: move its progress bar without the
     reader pressing refresh. Stops as soon as it finishes. */
  function initSmsProgress() {
    const wrap = $('[data-sms-progress]');
    if (!wrap) return;

    const url  = wrap.dataset.smsProgress;
    const bar  = $('[data-sms-bar]', wrap);
    const note = $('[data-sms-note]', wrap);
    let misses = 0;

    function tick() {
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : null))
        .then((d) => {
          if (!d) { if (++misses < 5) setTimeout(tick, 6000); return; }

          const done = (d.sent || 0) + (d.failed || 0);
          const pct  = d.total > 0 ? Math.min(100, Math.round((done / d.total) * 100)) : 0;

          if (bar) bar.style.width = pct + '%';
          if (note) {
            note.textContent = done.toLocaleString() + ' of ' + (d.total || 0).toLocaleString()
                             + ' · ' + (d.failed || 0).toLocaleString() + ' failed';
          }

          if (d.running) { setTimeout(tick, 4000); return; }

          window.location.reload();
        })
        .catch(() => { if (++misses < 5) setTimeout(tick, 8000); });
    }

    setTimeout(tick, 3000);
  }

  /* Waiting for an M-Pesa PIN on a units top-up. */
  function initSmsTopup() {
    const wrap = $('[data-sms-topup]');
    if (!wrap) return;

    const url  = wrap.dataset.smsTopup;
    const note = $('[data-sms-topup-note]', wrap);
    let tries  = 0;

    function say(message, tone) {
      if (!note) return;
      note.textContent = message;
      note.className = 'field-hint' + (tone ? ' text-' + tone : '');
    }

    function tick() {
      if (++tries > 40) { say('Still waiting. If you have paid, your units will appear shortly.', 'muted'); return; }

      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : null))
        .then((d) => {
          if (!d) { setTimeout(tick, 5000); return; }

          if (d.status === 'pending') { say(d.message || 'Check your phone…'); setTimeout(tick, 4000); return; }

          say(d.message, d.status === 'success' ? 'good' : 'bad');

          if (d.status === 'success') setTimeout(() => window.location.reload(), 1500);
        })
        .catch(() => setTimeout(tick, 8000));
    }

    tick();
  }

  /* ------------------------------------------------------------------
     Looking inside a chosen file before it is uploaded
     ------------------------------------------------------------------
     Shows what the file holds — the columns found, the first few rows,
     how many numbers there are — and offers each column as a
     {placeholder} to drop into the message.

     This is a courtesy, not a check. The file is read again on the
     server when the campaign actually sends, and nothing here is sent
     with the form. An .xlsx is a zip, which cannot be read this way, so
     it says so rather than guessing.
     ------------------------------------------------------------------ */

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  function csvRows(text) {
    // A small CSV reader: quoted fields, doubled quotes inside them, and
    // commas or semicolons as the separator — which is what a European
    // Excel produces and what trips up a naive split().
    const head = text.slice(0, 2000);
    const sep = (head.split(';').length > head.split(',').length) ? ';' : ',';

    const rows = [];
    let row = [], field = '', quoted = false;

    for (let i = 0; i < text.length; i++) {
      const ch = text[i];

      if (quoted) {
        if (ch === '"') {
          if (text[i + 1] === '"') { field += '"'; i++; } else { quoted = false; }
        } else { field += ch; }
        continue;
      }

      if (ch === '"') { quoted = true; continue; }
      if (ch === sep) { row.push(field); field = ''; continue; }

      if (ch === '\n' || ch === '\r') {
        if (ch === '\r' && text[i + 1] === '\n') i++;
        row.push(field); field = '';
        if (row.some((c) => c.trim() !== '')) rows.push(row);
        row = [];
        continue;
      }

      field += ch;
    }

    row.push(field);
    if (row.some((c) => c.trim() !== '')) rows.push(row);

    return rows;
  }

  function looksLikePhone(value) {
    const digits = String(value === undefined ? '' : value).replace(/\D+/g, '');
    if (!digits) return false;
    if (digits.length === 9 && (digits[0] === '7' || digits[0] === '1')) return true;
    if (digits.length === 10 && digits[0] === '0') return true;
    if (digits.length === 12 && digits.slice(0, 3) === '254') return true;
    return false;
  }

  function initSmsFilePreview() {
    $$('[data-sms-preview]').forEach((input) => {
      const box = $(input.dataset.smsPreview);
      if (!box) return;

      const holder = input.dataset.smsPlaceholders ? $(input.dataset.smsPlaceholders) : null;

      input.addEventListener('change', () => {
        const file = input.files && input.files[0];

        box.hidden = false;
        if (holder) holder.hidden = true;

        if (!file) { box.innerHTML = ''; return; }

        if (file.name.toLowerCase().endsWith('.xlsx')) {
          box.innerHTML = '<div class="alert alert--info" style="margin:0"><div class="alert__body">'
            + 'Excel file chosen. We read it when the send starts — it cannot be previewed here. '
            + 'To see it first, save it as CSV.</div></div>';
          return;
        }

        const reader = new FileReader();

        reader.onload = () => {
          const rows = csvRows(String(reader.result || ''));

          if (!rows.length) {
            box.innerHTML = '<p class="text-sm text-muted">That file looks empty.</p>';
            return;
          }

          const headers = rows[0].map((h) => h.trim());
          const lower = headers.map((h) => h.toLowerCase());

          let phoneAt = lower.findIndex((h) =>
            h.includes('phone') || h.includes('mobile') || ['number', 'contact', 'msisdn'].indexOf(h) !== -1);

          // No headings at all: a bare list of numbers is common.
          const bare = phoneAt === -1 && looksLikePhone(rows[0][0]);
          const body = bare ? rows : rows.slice(1);
          if (bare) phoneAt = 0;

          const valid = body.filter((r) => looksLikePhone(r[phoneAt === -1 ? 0 : phoneAt])).length;

          let html = '';

          if (phoneAt === -1) {
            html += '<div class="alert alert--warning" style="margin:0 0 10px"><div class="alert__body">'
              + 'No column of phone numbers found. One heading should be '
              + '<span class="code">phone</span>, <span class="code">mobile</span> or '
              + '<span class="code">number</span>.</div></div>';
          } else {
            html += '<p class="text-sm">Found <strong>' + valid.toLocaleString() + '</strong> phone number'
              + (valid === 1 ? '' : 's') + ' in <strong>' + body.length.toLocaleString() + '</strong> row'
              + (body.length === 1 ? '' : 's')
              + (bare ? ', with no headings — the first column is taken as the number.' : '.') + '</p>';

            if (valid < body.length) {
              html += '<p class="text-sm text-muted">' + (body.length - valid).toLocaleString()
                + ' row(s) hold nothing we recognise as a Kenyan mobile number. '
                + 'They are counted and reported, never sent to.</p>';
            }
          }

          const show = body.slice(0, 3);

          if (show.length) {
            const cols = bare ? ['phone'] : headers;
            html += '<div class="table-wrap"><table class="table table--compact"><thead><tr>';
            cols.forEach((h) => { html += '<th>' + escapeHtml(h) + '</th>'; });
            html += '</tr></thead><tbody>';
            show.forEach((r) => {
              html += '<tr>';
              cols.forEach((_, i) => {
                html += '<td class="text-sm">' + escapeHtml(r[i] === undefined ? '' : r[i]) + '</td>';
              });
              html += '</tr>';
            });
            html += '</tbody></table></div>';
          }

          box.innerHTML = html;

          // Each column becomes a button that drops {column} into the
          // message at the cursor.
          if (holder && !bare) {
            const usable = headers.filter((h, i) => h !== '' && i !== phoneAt);

            if (usable.length) {
              holder.hidden = false;
              holder.innerHTML = '<span class="text-sm text-muted">Put their own details in: </span>'
                + usable.map((h) => '<button type="button" class="btn btn--ghost btn--sm" data-ph="{'
                  + escapeHtml(h.toLowerCase()) + '}">{' + escapeHtml(h.toLowerCase()) + '}</button>').join(' ');
            }
          }
        };

        // Enough to show a few rows and count what is there, without
        // pulling a 40MB file into the page.
        reader.readAsText(file.slice(0, 2 * 1024 * 1024));
      });
    });

    // Dropping a placeholder into the message, where the cursor is.
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-ph]');
      if (!btn) return;

      const box = $('#message');
      if (!box) return;

      const at = box.selectionStart || box.value.length;
      const end = box.selectionEnd || at;

      box.value = box.value.slice(0, at) + btn.dataset.ph + box.value.slice(end);
      box.dispatchEvent(new Event('input'));
      box.focus();
      box.selectionStart = box.selectionEnd = at + btn.dataset.ph.length;
    });
  }

  window.Shanfix = { toast, openModal };
})();
