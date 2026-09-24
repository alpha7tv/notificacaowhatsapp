<div class="card narrow">
  <form method="post" action="/conta/senha" class="form">
    <?= csrf_field() ?>
    <label>Senha atual<input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>Nova senha (mín. 10 caracteres)<input type="password" name="new_password" minlength="10" required autocomplete="new-password"></label>
    <label>Confirme a nova senha<input type="password" name="confirm_password" minlength="10" required autocomplete="new-password"></label>
    <button class="btn btn-primary">Alterar senha</button>
  </form>
</div>
