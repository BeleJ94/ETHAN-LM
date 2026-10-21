<?php /** Ligne de marchandise éditable (import de licence). @var int|string $i @var array $l */ $st = $l['status'] ?? null; ?>
<tr class="xline<?= $st === 'uncertain' ? ' is-uncertain' : '' ?>" title="<?= e($l['source'] ?? '') ?>">
    <td><input name="lines[<?= $i ?>][description]" maxlength="255" value="<?= e((string) ($l['description'] ?? '')) ?>" aria-label="<?= e(t('licence_import.col.description')) ?>"></td>
    <td><input name="lines[<?= $i ?>][hs_code]" maxlength="20" class="mono" value="<?= e((string) ($l['hs_code'] ?? '')) ?>" aria-label="<?= e(t('licence_import.col.hs')) ?>"></td>
    <td><input name="lines[<?= $i ?>][quantity]" type="number" step="0.001" min="0" inputmode="decimal" value="<?= e((string) ($l['quantity'] ?? '')) ?>" data-xq aria-label="<?= e(t('movements.col.quantity')) ?>"></td>
    <td><input name="lines[<?= $i ?>][unit]" maxlength="20" value="<?= e((string) ($l['unit'] ?? '')) ?>" aria-label="<?= e(t('items.col.unit')) ?>"></td>
    <td><input name="lines[<?= $i ?>][unit_price]" type="number" step="0.000001" min="0" inputmode="decimal" value="<?= e((string) ($l['unit_price'] ?? '')) ?>" data-xp aria-label="<?= e(t('orders.col.unit_price')) ?>"></td>
    <td><input name="lines[<?= $i ?>][amount]" type="number" step="0.01" min="0" inputmode="decimal" value="<?= e((string) ($l['amount'] ?? '')) ?>" data-xa aria-label="<?= e(t('orders.col.amount')) ?>"></td>
    <td><button type="button" class="icon-btn icon-btn--danger" data-xline-remove title="<?= e(t('common.remove')) ?>" aria-label="<?= e(t('common.remove')) ?>"><?= icon('trash-2') ?></button></td>
</tr>
