<?php
/**
 * Résultat du simulateur : check-list qu'obtiendrait une expédition, groupée selon le flux.
 * @var array{mode:string,family_id:?int,transport:?string,items:list<array<string,mixed>>} $sim
 */
$groups = [];
foreach ($sim['items'] as $it) {
    $key = $it['blocking_stage'] ?: ($it['required'] ? 'required' : 'optional');
    $groups[$key][] = $it;
}
$order = ['departure', 'reception', 'invoicing', 'closure', 'required', 'optional'];
$blocking = count(array_filter($sim['items'], fn ($i) => $i['blocking_stage']));
?>
<div class="sim-result" data-sim-result aria-live="polite">
<?php if (!$sim['items']): ?>
    <p class="sim-empty"><?= icon('circle-check') ?> <?= e(t('documents.sim.empty')) ?></p>
<?php else: ?>
    <p class="sim-summary"><?= e(t('documents.sim.summary', ['n' => count($sim['items']), 'b' => $blocking])) ?></p>
    <ol class="sim-flow">
    <?php foreach ($order as $g): if (empty($groups[$g])) { continue; } ?>
        <li class="sim-step sim-step--<?= in_array($g, ['required', 'optional'], true) ? $g : 'stage' ?>">
            <p class="sim-step__title">
                <?= icon(in_array($g, ['required', 'optional'], true) ? ($g === 'required' ? 'file-check' : 'file') : 'lock') ?>
                <?= e(t('documents.sim.group.' . $g)) ?> <span class="muted">(<?= count($groups[$g]) ?>)</span>
            </p>
            <ul>
            <?php foreach ($groups[$g] as $it): $tp = $it['type']; ?>
                <li class="sim-doc">
                    <span class="sim-doc__code mono"><?= e($tp['code']) ?></span>
                    <span class="sim-doc__name"><?= e($tp['name']) ?>
                        <span class="muted"> · <?= e(t('documents.provider.' . $tp['provider'])) ?> · <?= e(t('documents.level.' . $tp['level'])) ?></span></span>
                    <span class="sim-doc__rules">
                        <?php foreach ($it['rule_ids'] as $rid): ?>
                            <button type="button" class="chip chip--link" data-open="#tpl-rule-<?= (int) $rid ?>" data-title="<?= e(t('common.edit')) ?>" title="<?= e(t('documents.sim.edit_rule')) ?>"><?= e(t('documents.sim.rule', ['id' => (int) $rid])) ?></button>
                        <?php endforeach ?>
                    </span>
                </li>
            <?php endforeach ?>
            </ul>
        </li>
    <?php endforeach ?>
    </ol>
<?php endif ?>
</div>
