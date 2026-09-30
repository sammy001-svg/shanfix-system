/* Reads the inventory/service catalogue embedded by documents/form.php and
   hands it to app.js. Kept out of the page so the CSP can forbid inline JS.
   This file loads before app.js, so the global is ready when it initialises. */
(function () {
  var el = document.getElementById('catalog-data');
  if (!el) return;

  try {
    var data = JSON.parse(el.textContent);
    window.SHANFIX_CATALOG = data;
    /* What each KRA tax class costs. Sent from PHP rather than written
       here, because the rates are settings and the Finance Act moves
       them. */
    window.SHANFIX_TAX_RATES = data.rates || {};
  } catch (e) {
    window.SHANFIX_CATALOG = { inventory: [], service: [] };
    window.SHANFIX_TAX_RATES = {};
  }
})();
