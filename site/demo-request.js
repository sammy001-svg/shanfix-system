/* The Request a demo dialog.
 *
 * Shared by every product page that offers one. It was written inline on
 * the Sacco Pro page first; a second page would have meant a second copy
 * of it, and the first fix to either landing in one of them.
 *
 * Opened by any button marked data-demo-open, closed by the backdrop,
 * the X, or Escape — all three, because a dialog that traps somebody is
 * worse than no dialog at all.
 */
(function () {
    'use strict';

    var modal  = document.getElementById('demoModal');
    var form   = document.getElementById('demoForm');
    var wrap   = document.getElementById('demoFormWrap');
    var done   = document.getElementById('demoDone');
    var msg    = document.getElementById('demoDoneMsg');
    var err    = document.getElementById('demoError');
    var submit = document.getElementById('demoSubmit');

    if (!modal || !form) { return; }

    var lastFocused = null;

    function open() {
        lastFocused = document.activeElement;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';

        var first = document.getElementById('d_name');
        if (first) { setTimeout(function () { first.focus(); }, 60); }
    }

    function close() {
        modal.hidden = true;
        document.body.style.overflow = '';
        if (lastFocused && lastFocused.focus) { lastFocused.focus(); }
    }

    document.querySelectorAll('[data-demo-open]').forEach(function (b) {
        b.addEventListener('click', open);
    });
    document.querySelectorAll('[data-demo-close]').forEach(function (b) {
        b.addEventListener('click', close);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { close(); }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var data = {};
        new FormData(form).forEach(function (v, k) {
            data[k] = typeof v === 'string' ? v.trim() : v;
        });

        if (!data.name || !data.email || !data.phone) {
            err.textContent = 'We need your name, email and phone — the login goes to both.';
            err.hidden = false;
            return;
        }

        err.hidden = true;
        submit.disabled = true;

        var original = submit.textContent;
        submit.textContent = 'Sending…';

        fetch('api/demo-request.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(data)
        })
        .then(function (res) { return res.json(); })
        .then(function (out) {
            if (out.success) {
                msg.textContent = out.message;
                wrap.hidden = true;
                done.hidden = false;
            } else {
                err.textContent = out.message || 'Something went wrong. Please try again.';
                err.hidden = false;
            }
        })
        .catch(function () {
            err.textContent = 'We could not reach the server. Please check your connection and try again.';
            err.hidden = false;
        })
        .then(function () {
            submit.disabled = false;
            submit.textContent = original;
        });
    });
}());
