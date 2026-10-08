<?php /** @var array $rows */ include __DIR__ . '/_nav.php'; ?>
<div>
  <p class="muted" style="margin:0 0 14px;max-width:760px">Avisos e publicidade que aparecem no aplicativo do cliente: na tela inicial, numa janela ao abrir o app ou na tela de ativação. Você escolhe o período e o público (todos, só testes ou só clientes pagantes).</p>
  <p style="margin:0 0 16px"><a class="btn btn-primary" href="/admin/meu-app/avisos/novo">+ Novo aviso ou anúncio</a></p>
  <div style="<?= $box ?>overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;min-width:860px">
      <thead><tr>
        <th style="<?= $th ?>">Aviso</th><th style="<?= $th ?>">Tipo</th><th style="<?= $th ?>">Onde aparece</th><th style="<?= $th ?>">Público</th>
        <th style="<?= $th ?>">Período</th><th style="<?= $th ?>">Exibições / cliques</th><th style="<?= $th ?>"></th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted" style="<?= $td ?>text-align:center;padding:30px">Nenhum aviso cadastrado.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr style="<?= $r['is_active'] ? '' : 'opacity:.55' ?>">
          <td style="<?= $td ?>">
            <?php if ($r['image_path']): ?><img src="/uploads/app/<?= e(rawurlencode(basename((string) $r['image_path']))) ?>" alt="" style="width:64px;height:40px;object-fit:cover;border-radius:6px;float:left;margin:0 10px 0 0"><?php endif; ?>
            <strong><?= e($r['title']) ?></strong><br><span class="muted" style="font-size:.85rem"><?= e(mb_substr((string) $r['body'], 0, 70)) ?></span>
          </td>
          <td style="<?= $td ?>"><?= e(\App\Services\AppService::NOTICE_KINDS[$r['kind']] ?? $r['kind']) ?></td>
          <td style="<?= $td ?>"><?= e(\App\Services\AppService::PLACEMENTS[$r['placement']] ?? $r['placement']) ?></td>
          <td style="<?= $td ?>"><?= e(\App\Services\AppService::AUDIENCES[$r['audience']] ?? $r['audience']) ?></td>
          <td style="<?= $td ?>white-space:nowrap;font-size:.85rem"><?= $r['starts_at'] ? e(dt($r['starts_at'])) : 'já' ?> → <?= $r['ends_at'] ? e(dt($r['ends_at'])) : 'sem fim' ?></td>
          <td style="<?= $td ?>"><?= (int) $r['views'] ?> / <?= (int) $r['clicks'] ?></td>
          <td style="<?= $td ?>"><div style="display:flex;gap:6px;flex-wrap:wrap">
            <a style="<?= $mini ?>" href="/admin/meu-app/avisos/<?= (int) $r['id'] ?>">Editar</a>
            <form method="post" action="/admin/meu-app/avisos/<?= (int) $r['id'] ?>/alternar"><?= csrf_field() ?><button type="submit" style="<?= $mini ?>"><?= $r['is_active'] ? 'Pausar' : 'Ativar' ?></button></form>
            <form method="post" action="/admin/meu-app/avisos/<?= (int) $r['id'] ?>/excluir" onsubmit="return confirm('Excluir este aviso?');"><?= csrf_field() ?><button type="submit" style="<?= $mini ?>color:#FF4D6D;border-color:rgba(255,77,109,.5)">Excluir</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
