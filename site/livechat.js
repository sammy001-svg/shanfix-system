/* ---------------------------------------------------------------------
   Live chat widget — ours, replacing tawk.to
   ---------------------------------------------------------------------
   Plain JavaScript, no dependencies, one file. It has to run on every
   page of the marketing site, some written years apart, so it assumes
   nothing about what else is on the page and touches nothing outside
   its own element.

   The visitor's credential is a token the server issues and this keeps
   in localStorage. It is the only thing proving who they are, so it is
   never put in the URL where it would end up in a browser history or a
   referrer header.

   Polling rather than websockets, because the system runs on cPanel
   where a long-lived connection is not something to rely on. The rate
   adapts: brisk while the panel is open, slow when it is shut, slower
   still when the tab is in the background. That keeps a reply feeling
   immediate without hammering the server from every open tab on the
   site.
   --------------------------------------------------------------------- */
(function () {
  'use strict';

  var API = '/api/chat';
  var KEY = 'shanfix_chat_token';

  var state = {
    token: null,
    lastId: 0,
    open: false,
    started: false,
    department: null,
    timer: null,
    config: null,
    unread: 0,
    // Ids of messages this browser drew the moment it sent them, so
    // the poll that hands them back does not draw them again.
    mine: {}
  };

  // localStorage throws in some private-browsing modes rather than
  // simply being empty, so every use of it is guarded. Losing the token
  // only costs a new conversation; a thrown error would cost the page.
  function remember(token) {
    state.token = token;
    try { localStorage.setItem(KEY, token); } catch (e) { /* not fatal */ }
  }

  function recall() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }

  function forget() {
    state.token = null;
    try { localStorage.removeItem(KEY); } catch (e) { /* not fatal */ }
  }

  // -- Talking to the server -------------------------------------------
  function post(action, data) {
    var body = new FormData();
    Object.keys(data || {}).forEach(function (k) {
      if (data[k] !== null && data[k] !== undefined) { body.append(k, data[k]); }
    });

    return fetch(API + '/' + action, { method: 'POST', body: body })
      .then(function (r) { return r.json(); });
  }

  function get(action, params) {
    var q = Object.keys(params || {})
      .map(function (k) { return k + '=' + encodeURIComponent(params[k]); })
      .join('&');

    return fetch(API + '/' + action + (q ? '?' + q : ''))
      .then(function (r) { return r.json(); });
  }

  // -- Building it -----------------------------------------------------
  var el = {};

  function icon(paths) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
           'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
  }

  var ICON_CHAT  = icon('<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.9-.9L3 21l1.9-5A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/>');
  var ICON_CLOSE = icon('<path d="M18 6 6 18M6 6l12 12"/>');
  var ICON_SEND  = icon('<path d="m22 2-7 20-4-9-9-4 20-7z"/>');

  function build(config) {
    var root = document.createElement('div');
    root.className = 'sfc';

    root.innerHTML =
      '<div class="sfc-panel" role="dialog" aria-label="Chat with us">' +
        '<div class="sfc-head">' +
          '<div class="sfc-head-av">' + ICON_CHAT + '</div>' +
          '<div class="sfc-head-txt">' +
            '<div class="sfc-head-name"></div>' +
            '<div class="sfc-head-sub"><span class="sfc-dot"></span><span class="sfc-status"></span></div>' +
          '</div>' +
          '<button type="button" class="sfc-x" aria-label="Close chat">' + ICON_CLOSE + '</button>' +
        '</div>' +
        '<div class="sfc-body"></div>' +
        '<div class="sfc-foot">' +
          '<textarea class="sfc-text" rows="1" maxlength="4000" ' +
            'placeholder="Type your message…" aria-label="Your message"></textarea>' +
          '<button type="button" class="sfc-send" aria-label="Send">' + ICON_SEND + '</button>' +
        '</div>' +
        '<div class="sfc-note"></div>' +
      '</div>' +
      '<button type="button" class="sfc-btn" aria-label="Chat with us">' +
        ICON_CHAT + '<span>Chat with us</span>' +
        '<span class="sfc-badge">1</span>' +
      '</button>';

    document.body.appendChild(root);

    el.root   = root;
    el.panel  = root.querySelector('.sfc-panel');
    el.body   = root.querySelector('.sfc-body');
    el.text   = root.querySelector('.sfc-text');
    el.send   = root.querySelector('.sfc-send');
    el.toggle = root.querySelector('.sfc-btn');
    el.badge  = root.querySelector('.sfc-badge');
    el.close  = root.querySelector('.sfc-x');
    el.name   = root.querySelector('.sfc-head-name');
    el.status = root.querySelector('.sfc-status');
    el.dot    = root.querySelector('.sfc-dot');
    el.note   = root.querySelector('.sfc-note');

    el.name.textContent = config.company || 'Chat with us';
    setPresence(config.open);

    el.toggle.addEventListener('click', openPanel);
    el.close.addEventListener('click', closePanel);
    el.send.addEventListener('click', submit);

    // Enter sends, Shift+Enter makes a new line — what everybody expects
    // from a chat box, and the opposite of what a bare textarea does.
    el.text.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
    });

    el.text.addEventListener('input', function () {
      el.text.style.height = 'auto';
      el.text.style.height = Math.min(el.text.scrollHeight, 96) + 'px';
    });
  }

  function setPresence(isOpen) {
    el.status.textContent = isOpen ? 'We usually reply in a few minutes' : 'Away — leave a message';
    el.dot.className = 'sfc-dot' + (isOpen ? '' : ' is-off');
  }

  // -- Drawing the conversation ----------------------------------------
  function bubble(text, mine, who, isSystem) {
    if (isSystem) {
      var s = document.createElement('div');
      s.className = 'sfc-msg sfc-msg--sys';
      s.textContent = text;
      el.body.appendChild(s);
      scroll();
      return;
    }

    if (!mine && who) {
      var w = document.createElement('div');
      w.className = 'sfc-who';
      w.textContent = who;
      el.body.appendChild(w);
    }

    var b = document.createElement('div');
    b.className = 'sfc-msg' + (mine ? ' sfc-msg--me' : '');
    b.textContent = text;          // textContent, never innerHTML
    el.body.appendChild(b);
    scroll();
  }

  function scroll() { el.body.scrollTop = el.body.scrollHeight; }

  /** The greeting, and the department question if there is one. */
  function greet() {
    var config = state.config;

    bubble(config.greeting, false, null, true);

    if (!config.open && config.offline) {
      bubble(config.offline, false, null, true);
    }

    if (config.ask && config.departments.length > 1) {
      var pick = document.createElement('div');
      pick.className = 'sfc-pick';
      pick.innerHTML = '<div class="sfc-pick-q">What is it about?</div>';

      config.departments.forEach(function (d) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'sfc-dept';
        b.innerHTML = '<span></span>' + (d.blurb ? '<small></small>' : '');
        b.querySelector('span').textContent = d.name;
        if (d.blurb) { b.querySelector('small').textContent = d.blurb; }

        b.addEventListener('click', function () {
          state.department = d.slug;
          Array.prototype.forEach.call(pick.querySelectorAll('.sfc-dept'), function (o) {
            o.classList.remove('is-on');
          });
          b.classList.add('is-on');
          el.text.focus();
        });

        pick.appendChild(b);
      });

      el.body.appendChild(pick);
    }

    // Asked for, never required. A visitor who would rather not say who
    // they are should still be able to ask a question — and somebody
    // deciding whether to buy from us is exactly the person a form would
    // send away.
    var lead = document.createElement('div');
    lead.className = 'sfc-lead';
    lead.innerHTML =
      '<input class="sfc-in sfc-nm" type="text" placeholder="Your name (optional)" maxlength="100">' +
      '<input class="sfc-in sfc-em" type="email" placeholder="Email, if you would like a reply (optional)" maxlength="160">';
    el.body.appendChild(lead);
    el.lead = lead;

    scroll();
  }

  // -- Sending ---------------------------------------------------------
  function submit() {
    var message = el.text.value.trim();
    if (!message) { return; }

    el.text.value = '';
    el.text.style.height = 'auto';
    bubble(message, true);

    if (!state.started) {
      var name  = el.lead ? el.lead.querySelector('.sfc-nm').value : '';
      var email = el.lead ? el.lead.querySelector('.sfc-em').value : '';

      if (el.lead) { el.lead.remove(); el.lead = null; }
      var pick = el.body.querySelector('.sfc-pick');
      if (pick) { pick.remove(); }

      post('start', {
        message: message,
        name: name,
        email: email,
        department: state.department,
        page_url: location.href.slice(0, 255),
        page_title: document.title.slice(0, 160)
      }).then(function (d) {
        if (!d.ok) { bubble(d.error || 'That did not send. Please try again.', false, null, true); return; }
        state.started = true;
        state.mine[d.id] = true;
        remember(d.token);
        loop();
      }).catch(function () {
        bubble('We could not reach the office just now. Please try again.', false, null, true);
      });

      return;
    }

    post('send', { token: state.token, message: message }).then(function (d) {
      if (!d.ok) { bubble(d.error || 'That did not send.', false, null, true); return; }
      state.mine[d.id] = true;
      pollNow();
    }).catch(function () {
      bubble('That did not send — check your connection.', false, null, true);
    });
  }

  // -- Polling ---------------------------------------------------------
  function poll() {
    if (!state.token) { return Promise.resolve(); }

    return get('poll', { token: state.token, after: state.lastId })
      .then(function (d) {
        if (!d.ok) {
          // The conversation is gone or the token is stale. Start clean
          // rather than polling a dead id for the rest of the session.
          if (d.error) { forget(); state.started = false; }
          return;
        }

        setPresence(d.open);

        d.messages.forEach(function (m) {
          if (m.id > state.lastId) { state.lastId = m.id; }

          if (m.sender === 'visitor') {
            // We drew this one ourselves the moment it was typed. Any
            // other visitor message is from a second tab or a reload,
            // and does need drawing.
            if (state.mine[m.id]) { return; }
            bubble(m.body, true);
            return;
          }

          bubble(m.body, false, m.who, m.sender === 'system');

          if (!state.open) {
            state.unread++;
            el.badge.textContent = state.unread;
            el.badge.style.display = 'flex';
          }
        });
      })
      .catch(function () { /* a dropped request is not worth telling anybody about */ });
  }

  function delay() {
    if (document.hidden) { return 20000; }
    return state.open ? 3000 : 10000;
  }

  function loop() {
    poll().then(schedule, schedule);
  }

  function schedule() {
    clearTimeout(state.timer);
    state.timer = setTimeout(loop, delay());
  }

  function pollNow() {
    clearTimeout(state.timer);
    loop();
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && state.token) { pollNow(); }
  });

  // -- Opening and shutting --------------------------------------------
  function openPanel() {
    state.open = true;
    state.unread = 0;
    el.root.classList.add('is-open');
    el.badge.style.display = 'none';

    if (!state.greeted) { state.greeted = true; greet(); }

    el.text.focus();
    scroll();

    if (state.token) { pollNow(); }
  }

  function closePanel() {
    state.open = false;
    el.root.classList.remove('is-open');
    schedule();
  }

  // -- Start -----------------------------------------------------------
  function init() {
    get('hello').then(function (config) {
      if (!config || !config.ok || !config.enabled) { return; }

      state.config = config;
      build(config);

      el.note.textContent = 'You are chatting with ' + (config.company || 'us') + '.';

      // Somebody coming back to a conversation they already started: pick
      // it up where it was, including anything answered while they were
      // away, and tell them there is something waiting.
      state.token = recall();

      if (state.token) {
        state.started = true;
        state.greeted = true;
        loop();
      }
    }).catch(function () {
      // Chat being unreachable must never break the page it is on.
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
