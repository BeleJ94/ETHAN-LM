<?php
/** @var array|null $editUser @var array $sites @var list<\App\Security\Role> $roles */
use App\Core\Session;
use App\Core\View;

$u = $editUser;
$prefill ??= [];
$id = $u ? (int) $u['id'] : null;
$oldSites = Session::getFlash('_old')['site_ids'] ?? ($u['site_ids'] ?? []);
$oldSites = array_map('intval', (array) $oldSites);
echo View::partial('partials/page_header', ['icon' => 'user', 'heading' => $u ? t('users.edit') . ' — ' . $u['name'] : t('users.new')]);
?>
<form method="post" action="<?= e(url($id ? "/users/$id" : '/users')) ?>" class="card form form--sections">
    <?= csrf_field() ?>
    <fieldset class="form-section">
        <legend><?= e(t('users.section.identity')) ?></legend>
        <div class="form-grid">
            <div class="field">
                <label for="name" class="required"><?= e(t('users.col.name')) ?></label>
                <input id="name" name="name" maxlength="120" required value="<?= e(old('name', $u['name'] ?? '')) ?>"<?= error_attr('name') ?>>
                <?= error_msg('name') ?>
            </div>
            <div class="field">
                <label for="email" class="required"><?= e(t('auth.email')) ?></label>
                <input id="email" name="email" type="email" maxlength="190" required value="<?= e(old('email', $u['email'] ?? '')) ?>"<?= error_attr('email') ?>>
                <?= error_msg('email') ?>
            </div>
            <div class="field">
                <label for="password"<?= $u ? '' : ' class="required"' ?>><?= e(t('auth.password')) ?></label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" <?= $u ? '' : 'required' ?><?= error_attr('password') ?>>
                <p class="hint"><?= e(t($u ? 'users.password_keep' : 'users.password_hint')) ?></p>
                <?= error_msg('password') ?>
            </div>
            <div class="field">
                <label for="password_confirm"><?= e(t('users.password_confirm')) ?></label>
                <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password"<?= error_attr('password_confirm') ?>>
                <?= error_msg('password_confirm') ?>
            </div>
        </div>
    </fieldset>
    <fieldset class="form-section">
        <legend><?= e(t('users.section.access')) ?></legend>
        <div class="form-grid">
            <div class="field">
                <label for="role" class="required"><?= e(t('users.col.role')) ?></label>
                <select id="role" name="role" required<?= error_attr('role') ?>>
                    <?php $cur = old('role', $u['role'] ?? ($prefill['role'] ?? 'viewer')); foreach ($roles as $r): ?>
                        <option value="<?= e($r->value) ?>" <?= $cur === $r->value ? 'selected' : '' ?>><?= e(t('role.' . $r->value)) ?></option>
                    <?php endforeach ?>
                </select>
                <p class="hint"><?= e(t('users.role_hint')) ?></p>
                <?= error_msg('role') ?>
            </div>
            <div class="field">
                <label for="forwarder_id"><?= e(t('users.forwarder')) ?></label>
                <select id="forwarder_id" name="forwarder_id"<?= error_attr('forwarder_id') ?>>
                    <option value=""><?= e(t('common.none')) ?></option>
                    <?php $cur = old('forwarder_id', $u['forwarder_id'] ?? ($prefill['forwarder_id'] ?? '')); foreach ($forwarders as $fw): ?>
                        <option value="<?= (int) $fw['id'] ?>" <?= (string) $cur === (string) $fw['id'] ? 'selected' : '' ?>><?= e($fw['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <p class="hint"><?= e(t('users.forwarder_hint')) ?></p>
                <?= error_msg('forwarder_id') ?>
            </div>
            <div class="field">
                <span class="label"><?= e(t('users.col.sites')) ?></span>
                <div class="check-list">
                    <?php foreach ($sites as $s): ?>
                        <label class="check"><input type="checkbox" name="site_ids[]" value="<?= (int) $s['id'] ?>" <?= in_array((int) $s['id'], $oldSites, true) ? 'checked' : '' ?>> <?= e($s['code'] . ' — ' . $s['name']) ?></label>
                    <?php endforeach ?>
                    <?php if (!$sites): ?><p class="muted"><?= e(t('sites.none')) ?></p><?php endif ?>
                </div>
            </div>
            <?php if ($u): ?>
            <div class="field span-2">
                <label class="switch"><input type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label>
            </div>
            <?php endif ?>
        </div>
    </fieldset>
    <div class="form-actions">
        <a class="link-btn" href="<?= e(url('/users')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= e(t('common.save')) ?></button>
    </div>
</form>
