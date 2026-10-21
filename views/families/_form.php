<?php /** @var array|null $f */ use App\Repositories\FamilyRepository; ?>
<form method="post" action="<?= e(url($f ? '/families/' . (int) $f['id'] : '/families')) ?>" class="form modal-form">
    <?= csrf_field() ?>
    <div class="form-grid form-grid--modal">
        <?= \App\Core\View::partial('partials/ref_field', ['type' => 'family', 'name' => 'code', 'label' => t('families.col.code'), 'current' => $f['code'] ?? null]) ?>
        <div class="field"><label><?= e(t('families.col.order')) ?></label><input name="sort_order" type="number" min="0" step="1" value="<?= e((string) ($f['sort_order'] ?? 100)) ?>"></div>
    </div>
    <div class="field"><label class="required"><?= e(t('families.col.name')) ?></label><input name="name" maxlength="120" required value="<?= e($f['name'] ?? '') ?>" placeholder="<?= e(t('families.name_placeholder')) ?>"></div>
    <div class="field"><label><?= e(t('families.col.description')) ?></label><input name="description" maxlength="255" value="<?= e($f['description'] ?? '') ?>" placeholder="<?= e(t('families.description_placeholder')) ?>"></div>
    <div class="field">
        <span class="label required"><?= e(t('families.col.color')) ?></span>
        <div class="color-picker" role="radiogroup">
            <?php $cur = $f['color'] ?? 'blue'; foreach (FamilyRepository::COLORS as $c): ?>
                <label class="color-picker__item" title="<?= e(t('color.' . $c)) ?>">
                    <input type="radio" name="color" value="<?= e($c) ?>" <?= $cur === $c ? 'checked' : '' ?> required>
                    <span class="chip chip--c-<?= e($c) ?>"><?= e(t('color.' . $c)) ?></span>
                </label>
            <?php endforeach ?>
        </div>
    </div>
    <?php if ($f): ?>
        <label class="switch"><input type="checkbox" name="active" value="1" <?= $f['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label>
        <?php if ((int) $f['item_count'] > 0): ?><p class="hint"><?= e(t('families.deactivate_hint', ['n' => (int) $f['item_count']])) ?></p><?php endif ?>
    <?php endif ?>
</form>
