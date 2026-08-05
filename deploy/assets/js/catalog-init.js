/* Reads the inventory/service catalogue embedded by documents/form.php and
   hands it to app.js. Kept out of the page so the CSP can forbid inline JS.
   This file loads before app.js, so the global is ready when it initialises. */
(function () {
  var el = document.getElementById('catalog-data');
  if (!el) return;

  try {
    window.SHANFIX_CATALOG = JSON.parse(el.textContent);
  } catch (e) {
    window.SHANFIX_CATALOG = { inventory: [], service: [] };
  }
})();
