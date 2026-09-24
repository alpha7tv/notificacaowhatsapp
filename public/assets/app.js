(function () {
  'use strict';
  // Confirmação de ações (formulários com data-confirm)
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirm'))) { ev.preventDefault(); }
    });
  });
  // Menu lateral no celular
  document.querySelectorAll('[data-toggle="sidebar"]').forEach(function (b) {
    b.addEventListener('click', function () { document.getElementById('sidebar').classList.toggle('open'); });
  });
  // Botão "cancelar" dentro de <details>
  document.querySelectorAll('[data-close-details]').forEach(function (b) {
    b.addEventListener('click', function () { var d = b.closest('details'); if (d) { d.open = false; } });
  });
  // Evita envio duplo de formulários
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }
      var btn = f.querySelector('button:not([type="button"])');
      if (btn) { setTimeout(function () { btn.disabled = true; }, 0); }
    });
  });
  // Recarrega páginas com tarefa em andamento
  var auto = document.querySelector('[data-autorefresh]');
  if (auto) { setTimeout(function () { window.location.reload(); }, parseInt(auto.getAttribute('data-autorefresh'), 10) * 1000); }
})();
