(function () {
 'use strict';
 const config = window.AnarBalance || {};
 document.querySelectorAll('.anar-balance-refresh').forEach((form) => {
  form.addEventListener('submit', () => {
   document.querySelectorAll('button[form="' + form.id + '"]').forEach((button) => {
    button.disabled = true; button.textContent = config.checking; button.setAttribute('aria-busy', 'true');
   });
  });
 });
 document.querySelectorAll('[data-balance-copy]').forEach((button) => {
  button.addEventListener('click', async () => {
   const original = button.textContent;
   try { await navigator.clipboard.writeText(button.dataset.balanceCopy); button.textContent = config.copied; }
   catch (error) { button.textContent = config.failed; }
   window.setTimeout(() => { button.textContent = original; }, 1500);
  });
 });
})();
