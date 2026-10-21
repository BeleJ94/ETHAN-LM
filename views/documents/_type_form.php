<?php
/** @var array|null $tp */
use App\Repositories\DocumentRepository;
?>
<form method="post" action="<?= e(url($tp ? '/documents/types/' . (int) $tp['id'] : '/documents/types')) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <div class="form-grid form-grid--modal">
        <div class="field"><label class="required"><?= e(t('families.col.code')) ?></label><input name="code" maxlength="20" required class="mono" value="<?= e($tp['code'] ?? '') ?>" placeholder="IM4"></div>
        <div class="field"><label><?= e(t('families.col.order')) ?></label><input name="sort_order" type="number" min="0" step="1" value="<?= e((string) ($tp['sort_order'] ?? 100)) ?>"></div>
    </div>
    <div class="field"><label class="required"><?= e(t('families.col.name')) ?></label><input name="name" maxlength="160" required value="<?= e($tp['name'] ?? '') ?>"></div>
    <div class="field"><label><?= e(t('families.col.description')) ?></label><input name="description" maxlength="255" value="<?= e($tp['description'] ?? '') ?>"></div>
    <div class="form-grid form-grid--modal">
        <div class="field"><label class="required"><?= e(t('documents.col.provider')) ?></label>
            <select name="provider" required>
                <?php foreach (DocumentRepository::PROVIDERS as $p): ?><option value="<?= e($p) ?>" <?= ($tp['provider'] ?? 'supplier') === $p ? 'selected' : '' ?>><?= e(t('documents.provider.' . $p)) ?></option><?php endforeach ?>
            </select></div>
        <div class="field"><label class="required"><?= e(t('documents.col.level')) ?></label>
            <select name="level" required>
                <?php foreach (DocumentRepository::LEVELS as $l): ?><option value="<?= e($l) ?>" <?= ($tp['level'] ?? 'delivery') === $l ? 'selected' : '' ?>><?= e(t('documents.level.' . $l)) ?></option><?php endforeach ?>
            </select>
            <p class="hint"><?= e(t('documents.level_hint')) ?></p></div>
    </div>
    <label class="switch"><input type="checkbox" name="requires_number" value="1" <?= ($tp['requires_number'] ?? 1) ? 'checked' : '' ?>><span><?= e(t('documents.requires_number')) ?></span></label>
    <label class="switch"><input type="checkbox" name="requires_file" value="1" <?= ($tp['requires_file'] ?? 1) ? 'checked' : '' ?>><span><?= e(t('documents.requires_file')) ?></span></label>
    <label class="switch switch--hint"><input type="checkbox" name="requires_issue_date" value="1" <?= ($tp['requires_issue_date'] ?? 0) ? 'checked' : '' ?>><span><?= e(t('documents.requires_issue_date')) ?><small><?= e(t('documents.requires_issue_date_hint')) ?></small></span></label>
    <label class="switch switch--hint"><input type="checkbox" name="has_expiry" value="1" <?= ($tp['has_expiry'] ?? 0) ? 'checked' : '' ?>><span><?= e(t('documents.has_expiry')) ?><small><?= e(t('documents.has_expiry_hint')) ?></small></span></label>
    <?php if ($tp): ?><label class="switch"><input type="checkbox" name="active" value="1" <?= $tp['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label><?php endif ?>
</form>
