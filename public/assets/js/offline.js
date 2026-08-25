/**
 * Offline support for the Shanfix BMS.
 *
 * Three jobs:
 *   1. register the service worker, which caches pages for reading offline
 *   2. hold shop-floor actions in IndexedDB when the network is unreachable
 *   3. replay them, in order, once it comes back
 *
 * Only forms marked data-offline are queued. That is deliberate: stage
 * moves, checklist ticks and notes are safe to replay, whereas a payment
 * or an invoice allocates a sequential number and moves a balance, and two
 * devices queueing those offline would corrupt the books. Those forms stay
 * online-only and say so.
 *
 * Every queued action carries an idempotency key generated when the user
 * pressed the button, so a replay the server has already seen is ignored
 * rather than applied twice.
 */
(function () {
  'use strict';

  // How long to wait for the connectivity probe before giving up on it,
  // and how long its answer stays good for. Four seconds is long enough
  // for a slow mobile connection and short enough that a genuinely dead
  // network is reported quickly.
  var PROBE_TIMEOUT = 4000;
  var PROBE_CACHE   = 5000;

  var DB_NAME  = 'shanfix-offline';
  var STORE    = 'queue';
  var MAX_TRIES = 8;

  var base = (function () {
    var el = document.querySelector('meta[name="app-base"]');
    return el ? el.getAttribute('content') || '' : '';
  })();

  function path(p) {
    return base.replace(/\/$/, '') + p;
  }

  function csrfToken() {
    var el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') || '' : '';
  }

  function newKey() {
    if (window.crypto && window.crypto.randomUUID) {
      return window.crypto.randomUUID().replace(/-/g, '');
    }
    var bytes = new Uint8Array(16);
    (window.crypto || {}).getRandomValues
      ? window.crypto.getRandomValues(bytes)
      : bytes.forEach(function (_, i) { bytes[i] = Math.floor(Math.random() * 256); });
    return Array.prototype.map.call(bytes, function (b) {
      return ('0' + b.toString(16)).slice(-2);
    }).join('');
  }

  // -- IndexedDB ------------------------------------------------------

  var dbPromise = null;

  function db() {
    if (dbPromise) return dbPromise;

    dbPromise = new Promise(function (resolve, reject) {
      var req = indexedDB.open(DB_NAME, 1);

      req.onupgradeneeded = function () {
        if (!req.result.objectStoreNames.contains(STORE)) {
          req.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
        }
      };

      req.onsuccess = function () { resolve(req.result); };
      req.onerror   = function () { reject(req.error); };
    });

    return dbPromise;
  }

  function tx(mode, fn) {
    return db().then(function (conn) {
      return new Promise(function (resolve, reject) {
        var t = conn.transaction(STORE, mode);
        var store = t.objectStore(STORE);
        var out = fn(store);
        t.oncomplete = function () { resolve(out && out.result !== undefined ? out.result : out); };
        t.onerror    = function () { reject(t.error); };
        t.onabort    = function () { reject(t.error); };
      });
    });
  }

  function enqueue(item) { return tx('readwrite', function (s) { return s.add(item); }); }
  function remove(id)    { return tx('readwrite', function (s) { return s.delete(id); }); }
  function put(item)     { return tx('readwrite', function (s) { return s.put(item); }); }

  function all() {
    return db().then(function (conn) {
      return new Promise(function (resolve, reject) {
        var out = [];
        var cursor = conn.transaction(STORE, 'readonly').objectStore(STORE).openCursor();
        cursor.onsuccess = function (e) {
          var c = e.target.result;
          if (!c) { resolve(out); return; }
          out.push(c.value);
          c.continue();
        };
        cursor.onerror = function () { reject(cursor.error); };
      });
    });
  }

  // -- Status strip ---------------------------------------------------

  var strip = null;

  function ensureStrip() {
    if (strip) return strip;
    strip = document.createElement('div');
    strip.className = 'offline-bar';
    strip.setAttribute('role', 'status');
    strip.setAttribute('aria-live', 'polite');
    document.body.appendChild(strip);
    return strip;
  }

  /**
   * Whether the server is actually reachable.
   *
   * navigator.onLine answers a different question — whether the device has
   * a network interface at all — and on Windows it is routinely wrong. A
   * VPN client or a VirtualBox/VMware adapter leaves it stuck reporting
   * offline on a machine whose internet is working perfectly well, and
   * believing it put a false "no internet" banner in front of people all
   * day on a connection that was never down.
   *
   * So a claim of "offline" now has to be proven. HEAD, because the
   * service worker only intercepts GET, and a probe answered out of a
   * cache would report the network as up when it is not.
   */
  var lastKnown = { online: true, at: 0 };

  function reachable() {
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, PROBE_TIMEOUT) : null;

    return fetch(path('/up'), {
      method: 'HEAD',
      cache: 'no-store',
      credentials: 'same-origin',
      signal: controller ? controller.signal : undefined
    }).then(function () {
      // Any reply at all proves the network carried it. Even a 404 or a
      // 500 came from somewhere, which is the only fact being asked for.
      if (timer) clearTimeout(timer);
      return true;
    }).catch(function () {
      if (timer) clearTimeout(timer);
      return false;
    });
  }

  /**
   * The current state, checked no more often than it is worth checking.
   *
   * A browser saying it IS online is believed without a probe: that
   * direction is rarely wrong, and probing on every render would put a
   * request behind every page. Saying it is offline is only a suspicion,
   * and gets verified.
   */
  function connectionState() {
    if (navigator.onLine) {
      lastKnown = { online: true, at: Date.now() };
      return Promise.resolve(true);
    }

    if (Date.now() - lastKnown.at < PROBE_CACHE) {
      return Promise.resolve(lastKnown.online);
    }

    return reachable().then(function (ok) {
      lastKnown = { online: ok, at: Date.now() };
      return ok;
    });
  }

  function render(pending) {
    var bar = ensureStrip();
    var offline = !lastKnown.online;

    if (!offline && pending === 0) {
      bar.classList.remove('is-visible', 'is-offline', 'is-syncing');
      document.body.classList.remove('has-offline-bar');
      bar.textContent = '';
      return;
    }

    bar.classList.add('is-visible');
    document.body.classList.add('has-offline-bar');
    bar.classList.toggle('is-offline', offline);
    bar.classList.toggle('is-syncing', !offline && pending > 0);

    if (offline) {
      bar.textContent = pending > 0
        ? 'Offline — ' + pending + ' change' + (pending === 1 ? '' : 's') + ' saved on this device'
        : 'Offline — you can keep working, pages you have opened are still available';
    } else {
      bar.textContent = 'Back online — sending ' + pending + ' saved change'
        + (pending === 1 ? '' : 's') + '…';
    }
  }

  function refreshStatus() {
    return connectionState().then(function () {
      return all();
    }).then(function (items) {
      render(items.length);
      return items.length;
    }).catch(function () { render(0); return 0; });
  }

  function toast(message, kind) {
    var el = document.createElement('div');
    el.className = 'offline-toast' + (kind ? ' offline-toast--' + kind : '');
    el.textContent = message;
    document.body.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('is-in'); });
    setTimeout(function () {
      el.classList.remove('is-in');
      setTimeout(function () { el.remove(); }, 250);
    }, 4200);
  }

  // -- Capturing a submission ------------------------------------------

  function serialise(form) {
    var fields = [];
    new FormData(form).forEach(function (value, key) {
      // The token is re-stamped at send time; a stored one goes stale.
      if (key === '_token' || value instanceof File) return;
      fields.push([key, String(value)]);
    });
    return fields;
  }

  function bodyFor(item) {
    var body = new FormData();
    item.fields.forEach(function (pair) { body.append(pair[0], pair[1]); });
    body.append('_token', csrfToken());
    body.append('_idem', item.idem);
    return body;
  }

  function send(item) {
    return fetch(item.url, {
      method:      'POST',
      body:        bodyFor(item),
      credentials: 'same-origin',
      redirect:    'follow',
      headers:     {
        'X-Requested-With':  'XMLHttpRequest',
        'X-CSRF-Token':      csrfToken(),
        'X-Idempotency-Key': item.idem
      }
    });
  }

  /**
   * Marked forms go through fetch rather than a native submit, so a
   * request that dies on the wire can be caught and queued. navigator
   * .onLine is not trusted for this — it reports the network interface,
   * not whether anything is actually reachable.
   */
  function intercept(event) {
    var form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-offline')) return;
    if (form.querySelector('input[type="file"]')) return;   // uploads need the network

    event.preventDefault();

    var label   = form.getAttribute('data-offline-label') || 'Change';
    var buttons = form.querySelectorAll('button[type="submit"], button:not([type])');
    buttons.forEach(function (b) { b.disabled = true; });

    // No shortcut on navigator.onLine here. It fires wrongly often enough
    // that this used to hold somebody's work on their device while their
    // connection was fine. The attempt below is the honest test: if the
    // network really is down, send() rejects and the catch queues it.

    var item = {
      url:      form.getAttribute('action') || window.location.pathname,
      fields:   serialise(form),
      idem:     newKey(),
      label:    label,
      attempts: 0
    };

    send(item)
      .then(function (response) {
        if (response.status === 419) {
          window.location.reload();     // session rotated; a reload restores it
          return;
        }
        // Follow the post-redirect-get target so flash messages appear as usual.
        window.location.href = response.url || window.location.href;
      })
      .catch(function () {
        // The network went while we were talking to it.
        item.createdAt = Date.now();
        enqueue(item).then(function () {
          toast('No connection — saved on this device and it will sync later.', 'queued');
          buttons.forEach(function (b) { b.disabled = false; });
          refreshStatus();
        });
      });
  }

  // -- Replay -----------------------------------------------------------

  var flushing = false;
  var delivered = 0;

  function flush() {
    // lastKnown, not navigator.onLine: a stuck flag would leave everything
    // queued on the device for ever, which is how saved work goes missing.
    if (flushing || !lastKnown.online) return Promise.resolve();
    flushing = true;
    delivered = 0;

    return all().then(function (items) {
      if (items.length === 0) return;

      render(items.length);

      // Sequentially, oldest first: two stage moves on one job must land in
      // the order the user made them.
      return items.reduce(function (chain, item) {
        return chain.then(function (stop) {
          if (stop) return true;

          return send(item).then(function (response) {
            var landedOnLogin = response.redirected
              && response.url.indexOf('/login') !== -1;

            if (response.status === 401 || response.status === 403 || landedOnLogin) {
              toast('Sign in again to send your saved changes.', 'error');
              return true;                       // stop; keep the queue intact
            }

            if (response.status === 419) {
              return true;                       // stale token; retry after reload
            }

            if (response.ok || response.redirected) {
              delivered++;
              return remove(item.id).then(function () { return false; });
            }

            if (response.status >= 400 && response.status < 500) {
              // The server will refuse this every time; do not spin on it.
              return remove(item.id).then(function () {
                toast(item.label + ' could not be saved and was discarded.', 'error');
                return false;
              });
            }

            return retryLater(item);
          }).catch(function () {
            return retryLater(item).then(function () { return true; });
          });
        });
      }, Promise.resolve(false));
    }).then(function () {
      flushing = false;
      return refreshStatus().then(function (left) {
        // Only claim success for things that actually landed — a queue
        // emptied by discarding failures is not "all sent".
        if (left === 0 && delivered > 0) {
          toast(delivered + ' saved change' + (delivered === 1 ? '' : 's')
                + ' sent to the server.', 'ok');

          // Pages cached while offline are now stale.
          if (navigator.serviceWorker && navigator.serviceWorker.controller) {
            warmCache();
          }
        }
      });
    }).catch(function () {
      flushing = false;
    });
  }

  function retryLater(item) {
    item.attempts = (item.attempts || 0) + 1;

    if (item.attempts >= MAX_TRIES) {
      return remove(item.id).then(function () {
        toast(item.label + ' could not be sent after several tries and was discarded.', 'error');
        return false;
      });
    }

    return put(item).then(function () { return false; });
  }

  // -- Service worker ----------------------------------------------------

  function warmCache() {
    fetch(path('/offline/precache'), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !navigator.serviceWorker.controller) return;
        navigator.serviceWorker.controller.postMessage({
          type: 'precache',
          urls: data.urls
        });
      })
      .catch(function () { /* not critical */ });
  }

  function registerWorker() {
    if (!('serviceWorker' in navigator)) return;

    navigator.serviceWorker.register(path('/sw.js'), { scope: path('/') + '' })
      .then(function () {
        if (navigator.serviceWorker.controller) warmCache();
        else navigator.serviceWorker.ready.then(warmCache);
      })
      .catch(function () { /* unsupported or blocked; the app still works */ });
  }

  /**
   * Signing out clears the cached pages. Workshop tablets get shared, and
   * the next person must not find the last one's jobs and figures sitting
   * in the cache.
   */
  function wireLogout() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      if ((form.getAttribute('action') || '').indexOf('/logout') === -1) return;

      if (navigator.serviceWorker && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({ type: 'clear' });
      }
      indexedDB.deleteDatabase(DB_NAME);
    }, true);
  }

  // -- Install prompt -----------------------------------------------------

  var deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    document.querySelectorAll('[data-install-app]').forEach(function (el) {
      el.hidden = false;
      el.addEventListener('click', function () {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.finally(function () {
          deferredPrompt = null;
          el.hidden = true;
        });
      }, { once: true });
    });
  });

  // -- Start --------------------------------------------------------------

  document.addEventListener('submit', intercept, false);
  window.addEventListener('online', function () {
    lastKnown = { online: true, at: Date.now() };
    refreshStatus();
    flush();
  });

  // Not taken at face value: this is the event that was wrongly firing on
  // working connections and putting the banner up.
  window.addEventListener('offline', function () {
    lastKnown.at = 0;             // force a real check
    refreshStatus();
  });

  document.addEventListener('DOMContentLoaded', function () {
    registerWorker();
    wireLogout();
    refreshStatus().then(function (pending) {
      if (pending > 0) flush();
    });
  });

  // Retry quietly while the tab is open, for a connection that comes back
  // without the browser firing an "online" event.
  setInterval(function () {
    if (lastKnown.online) {
      all().then(function (items) { if (items.length) flush(); }).catch(function () {});
    }
  }, 60000);

  // While the bar is showing, keep asking. A connection that comes back
  // does not always fire an "online" event, and a banner that will not go
  // away on a working connection is worse than no banner at all.
  setInterval(function () {
    if (lastKnown.online) return;

    lastKnown.at = 0;
    refreshStatus().then(function (pending) {
      if (lastKnown.online && pending > 0) flush();
    });
  }, 10000);
})();
