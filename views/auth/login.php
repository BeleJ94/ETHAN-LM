<?php use App\Core\Session; ?>
<div class="login-card__head">
    <span class="login-card__icon"><?= icon('lock-keyhole') ?></span>
    <h1 class="login__title"><?= e(t('login.welcome')) ?></h1>
    <p class="muted"><?= e(t('auth.subtitle')) ?></p>
</div>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert--error" role="alert"><?= icon('circle-alert') ?> <span><?= e($msg) ?></span></div>
<?php endif ?>
<form method="post" action="<?= e(url('/login')) ?>" class="form login">
    <?= csrf_field() ?>
    <div class="field">
        <label for="email" class="required"><?= e(t('auth.email')) ?></label>
        <div class="input-lead">
            <?= icon('mail') ?>
            <input id="email" name="email" type="email" autocomplete="username" required autofocus value="<?= e(old('email')) ?>" placeholder="nom@entreprise.com">
        </div>
    </div>
    <div class="field">
        <label for="password" class="required"><?= e(t('auth.password')) ?></label>
        <div class="input-lead input-icon">
            <?= icon('key-round') ?>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button type="button" class="icon-btn" data-toggle-password="#password" title="<?= e(t('auth.show_password')) ?>" aria-label="<?= e(t('auth.show_password')) ?>"><?= icon('eye') ?></button>
        </div>
        <p class="hint login__caps" data-caps hidden><?= icon('arrow-big-up') ?> <?= e(t('login.caps')) ?></p>
    </div>
    <button type="submit" class="btn btn--primary btn--block btn--lg" data-login-submit><?= icon('log-in') ?> <?= e(t('auth.submit')) ?></button>
</form>
<p class="login__secure muted"><?= icon('shield-check') ?> <?= e(t('login.secure')) ?></p>
