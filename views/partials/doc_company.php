<?php
/** En-tête société des documents imprimés : logo, raison sociale, coordonnées, identifiants légaux. @var array $company */
$legal = array_filter([
    'RCCM' => $company['rccm'], 'ID NAT' => $company['id_nat'], 'NIF' => $company['nif'],
    t('company.vat_short') => $company['vat_number'], t('company.importer_short') => $company['importer_number'],
]);
$contact = array_filter([$company['phone'], $company['email'], $company['website']]);
?>
<div class="doc__company">
    <?php if ($company['logo']): ?><img class="doc__logo" src="<?= e($company['logo']) ?>" alt=""><?php endif ?>
    <div>
        <strong><?= e($company['name']) ?></strong><?php if ($company['legal_form']): ?> <span class="doc__legal-form"><?= e($company['legal_form']) ?></span><?php endif ?>
        <?php if ($company['share_capital']): ?><p><?= e(t('company.share_capital')) ?> : <?= e($company['share_capital']) ?></p><?php endif ?>
        <?php if ($company['address'] || $company['city'] || $company['country']): ?><p><?= e(implode(', ', array_filter([$company['address'], $company['city'], $company['country']]))) ?></p><?php endif ?>
        <?php if ($contact): ?><p><?= e(implode(' · ', $contact)) ?></p><?php endif ?>
        <?php if ($legal): ?><p><?= e(implode(' · ', array_map(fn ($k, $v) => "$k $v", array_keys($legal), $legal))) ?></p><?php endif ?>
    </div>
</div>
