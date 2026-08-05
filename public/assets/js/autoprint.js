/* Opens the browser print dialog once the document view has rendered.
   Kept in its own file so the CSP can forbid inline scripts. */
window.addEventListener('load', function () {
  setTimeout(function () { window.print(); }, 250);
});
