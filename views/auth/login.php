<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · Fiber Link Notificações</title>
<link rel="stylesheet" href="/assets/app.css?v=<?= e(App\Core\App::version()) ?>">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body class="login-body">
<form class="login-card" method="post" action="/login" autocomplete="on">
  <?= csrf_field() ?>
  <div class="brand brand-lg"><span class="brand-dot"></span> Fiber Link<small>Notificações</small></div>
  <?php if (!empty($error)): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <label>E-mail<input type="email" name="email" value="<?= e($email ?? '') ?>" required autofocus autocomplete="username"></label>
  <label>Senha<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn btn-primary btn-block" type="submit">Entrar</button>
</form>
</body>
</html>
