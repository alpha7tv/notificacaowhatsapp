<?php
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

$user = Auth::user();
$path = Request::path();
$homolog = true;
try {
    $homolog = Settings::isHomologation();
} catch (\Throwable) {
}
$flash = Response::takeFlash();
$nav = [
    ['/', 'Painel', 'grid'],
    ['/clientes', 'Clientes', 'users'],
    ['/faturas', 'Faturas', 'file'],
    ['/mensagens', 'Mensagens', 'msg'],
    ['/regras', 'Regras de envio', 'rules'],
    ['/templates', 'Modelos', 'tpl'],
    ['/integracoes', 'Integrações', 'plug'],
    ['/homologacao', 'Homologação', 'check'],
];
$sys = [
    ['/sistema/servidor', 'Servidor'],
    ['/sistema/atualizacoes', 'Atualizações'],
    ['/sistema/logs', 'Logs'],
    ['/sistema/webhooks', 'Webhooks'],
    ['/sistema/configuracoes', 'Configurações'],
    ['/sistema/usuarios', 'Usuários'],
    ['/sistema/auditoria', 'Auditoria'],
];
$isActive = static fn (string $href) => $href === '/' ? $path === '/' : str_starts_with($path, $href);
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Painel') ?> · Fiber Link Notificações</title>
<link rel="stylesheet" href="/assets/app.css?v=<?= e(App\Core\App::version()) ?>">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body>
<?php if ($homolog): ?>
<div class="homolog-banner">⚠ SISTEMA EM MODO HOMOLOGAÇÃO — nenhuma mensagem é enviada a clientes reais. <a href="/homologacao">Ver checklist de liberação</a></div>
<?php endif; ?>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand"><span class="brand-dot"></span> Fiber Link<small>Notificações</small></div>
    <nav>
      <?php foreach ($nav as [$href, $label]): ?>
        <a href="<?= e($href) ?>" class="<?= $isActive($href) ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (($user['role'] ?? '') === 'admin'): ?>
        <div class="nav-section">Sistema</div>
        <?php foreach ($sys as [$href, $label]): ?>
          <a href="<?= e($href) ?>" class="<?= $isActive($href) ? 'active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
    <div class="sidebar-foot">v<?= e(App\Core\App::version()) ?></div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="menu-btn" type="button" data-toggle="sidebar" aria-label="Menu">☰</button>
      <h1><?= e($title ?? '') ?></h1>
      <div class="topbar-user">
        <span class="mode-pill <?= $homolog ? 'mode-homolog' : 'mode-prod' ?>"><?= $homolog ? 'Homologação' : 'Produção' ?></span>
        <a href="/conta/senha" class="muted"><?= e($user['name'] ?? '') ?></a>
        <form method="post" action="/logout" class="inline"><?= csrf_field() ?><button class="btn btn-sm btn-ghost">Sair</button></form>
      </div>
    </header>
    <main class="content">
      <?php foreach ($flash as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<script src="/assets/app.js?v=<?= e(App\Core\App::version()) ?>"></script>
</body>
</html>
