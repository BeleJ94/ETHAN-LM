<?php
/**
 * Choix banque → agence (référentiel), pour les licences. L'agence est filtrée selon la banque choisie.
 * Sans banque choisie, le texte saisi ou lu auparavant (« bank ») est conservé tel quel : rien n'est inventé.
 * @var array $banks banques actives (+ banque actuelle si désactivée) @var array $branches agences actives (+ actuelle)
 * @var ?int $bankId @var ?int $branchId @var ?string $legacy texte libre existant @var ?string $chip pastille d'état (HTML) @var ?string $source extrait du document (HTML)
 */
$chip ??= '';
$source ??= '';
$legacy ??= null;
?>
<div class="field xfield" data-xfield="bank_id">
    <label for="bank_id"><?= e(t('imports.bank')) ?> <?= $chip ?></label>
    <select id="bank_id" name="bank_id" data-bank-select<?= error_attr('bank_id') ?>>
        <option value=""><?= e(t('banks.select.none')) ?></option>
        <?php foreach ($banks as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= (int) $bankId === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name'] . ($b['short_name'] ? ' (' . $b['short_name'] . ')' : '')) ?></option>
        <?php endforeach ?>
    </select>
    <input type="hidden" name="bank" value="<?= e((string) $legacy) ?>">
    <?= $source ?>
    <?php if ($legacy !== null && $legacy !== '' && !$bankId): ?><p class="hint"><?= e(t('banks.select.legacy', ['text' => $legacy])) ?></p><?php endif ?>
    <?php if (!$banks): ?><p class="hint"><a href="<?= e(url('/banks')) ?>"><?= e(t('banks.select.empty')) ?></a></p><?php endif ?>
    <?= error_msg('bank_id') ?>
</div>
<div class="field">
    <label for="branch_id"><?= e(t('banks.branch.label')) ?></label>
    <select id="branch_id" name="branch_id" data-branch-select<?= error_attr('branch_id') ?>>
        <option value=""><?= e(t('banks.select.no_branch')) ?></option>
        <?php foreach ($branches as $br): ?>
            <option value="<?= (int) $br['id'] ?>" data-bank="<?= (int) $br['bank_id'] ?>" <?= (int) $branchId === (int) $br['id'] ? 'selected' : '' ?>><?= e(($br['city'] ? $br['city'] . ' · ' : '') . $br['name']) ?></option>
        <?php endforeach ?>
    </select>
    <?= error_msg('branch_id') ?>
</div>
