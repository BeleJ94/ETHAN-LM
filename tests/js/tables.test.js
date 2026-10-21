'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const core = require('../../public/assets/js/elm-core.js');
const { definitions } = require('../../public/assets/js/elm-tables.js');
const { buildMatrix } = require('../../public/assets/js/elm-export.js');

const defs = definitions({ t: (k) => k, locale: 'fr', currency: 'USD' });
const repoDir = path.join(__dirname, '../../app/Repositories');

/** Lit la liste blanche SORTABLE d'un repository PHP. */
function phpSortable(file) {
    const src = fs.readFileSync(path.join(repoDir, file), 'utf8');
    const block = src.match(/SORTABLE = \[([\s\S]*?)\];/)[1];
    return [...block.matchAll(/'(\w+)' =>/g)].map((m) => m[1]);
}

test('escapeHtml neutralise les caractères dangereux', () => {
    assert.equal(core.escapeHtml('<img src=x onerror="a">&\''), '&lt;img src=x onerror=&quot;a&quot;&gt;&amp;&#39;');
    assert.equal(core.escapeHtml(null), '');
});

test('fillTemplate encode les valeurs', () => {
    assert.equal(core.fillTemplate('/items/{id}', { id: '1/../x' }), '/items/1%2F..%2Fx');
});

test('formatDate est indépendant du fuseau', () => {
    assert.equal(core.formatDate('2026-10-19 14:05:00', true), '19/10/2026 14:05');
    assert.equal(core.formatDate(''), '—');
});

for (const [table, file] of [['items', 'ItemRepository.php'], ['movements', 'MovementRepository.php'], ['orders', 'OrderRepository.php'], ['invoices', 'InvoiceRepository.php'], ['deliveries', 'DeliveryRepository.php']]) {
    test(`${table} : toute colonne triable est dans la liste blanche PHP`, () => {
        const allowed = phpSortable(file);
        defs[table].filter((c) => c.orderable).forEach((c) => {
            assert.ok(allowed.includes(c.data), `${c.data} absent de ${file}`);
        });
    });

    test(`${table} : tout rendu HTML échappe les données`, () => {
        const evil = '<script>alert(1)</script>';
        const numeric = ['quantity', 'min_stock', 'avg_cost', 'value', 'unit_cost', 'unit_cost_base', 'active', 'amount', 'progress', 'late', 'balance', 'overdue', 'shipped', 'received', 'gap', 'docs_done', 'docs_total', 'docs_to_validate'];
        const row = new Proxy({}, { get: (_, k) => (k === 'type' ? 'in' : (numeric.includes(k) ? 1 : evil)) });
        defs[table].forEach((c) => {
            assert.ok(!c.html(row).includes('<script>'), `${table}.${c.data} non échappé`);
        });
    });
}

test('buildMatrix produit en-têtes et valeurs typées', () => {
    const m = buildMatrix(defs.items, [{ sku: 'A1', name: 'X', category: null, quantity: '5', min_stock: '2', avg_cost: '1.5', value: '7.5', active: 1, unit: 'PCE' }]);
    assert.equal(m.headers.length, defs.items.length);
    assert.deepEqual(m.rows[0].slice(0, 4), ['A1', 'X', '', 5]);
});

test('order_deliveries : totaux, couleurs de statut et écarts en alerte pour l\'export exécutif', () => {
    const rows = [
        { reference: 'L1', status: 'delivered', status_label: 'Livré', shipped: 18000, received: 17950, gap: 50 },
        { reference: 'L2', status: 'in_transit', status_label: 'En route', shipped: 12000, received: null, gap: null },
    ];
    const m = buildMatrix(defs.order_deliveries, rows);
    const idx = (data) => defs.order_deliveries.findIndex((c) => c.data === data);
    assert.equal(m.totals[idx('shipped')], 30000);
    assert.equal(m.totals[idx('received')], 17950);
    assert.equal(m.totals[idx('gap')], 50);
    assert.equal(m.totals[idx('reference')], null);
    assert.equal(m.fills[0][idx('status')], 'E7F3EC');
    assert.equal(m.alerts[0][idx('gap')], true);
    assert.equal(m.alerts[1][idx('gap')], false);
});

test('elm-export transmet l\'objet global à la fabrique (ExcelJS / pdfMake chargés dessus)', () => {
    const src = fs.readFileSync(path.join(__dirname, '../../public/assets/js/elm-export.js'), 'utf8');
    assert.match(src, /factory\(root\)/);
    assert.match(src, /function \(root\) \{/);
});
