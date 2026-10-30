/*
 * ETHAN LM — définition centralisée des colonnes des tableaux serveur.
 * Chaque colonne : data (nom serveur, sert au tri), title, html(row) (affichage, ÉCHAPPÉ), text(row) (export),
 * orderable, className. Toute colonne triable doit figurer dans la liste blanche PHP (testé).
 */
(function (root, factory) {
    const isNode = typeof module === 'object' && module.exports;
    const core = isNode ? require('./elm-core.js') : root.ELM.core;
    const mod = factory(core);
    if (isNode) {
        module.exports = mod;
    } else {
        root.ELM.tables = mod;
    }
})(typeof self !== 'undefined' ? self : this, function (core) {
    'use strict';

    const esc = core.escapeHtml;
    const IN_TYPES = ['in', 'transfer_in', 'adjust_in'];

    function stockState(row) {
        const q = Number(row.quantity);
        const min = Number(row.min_stock);
        if (Number(row.active) === 0) return 'inactive';
        if (q <= 0) return 'out';
        if (min > 0 && q <= min) return 'low';
        return 'ok';
    }
    const STATE_BADGE = { ok: 'available', low: 'waiting_part', out: 'breakdown', inactive: 'immobilized' };

    /** Même code couleur que partials/status_badge.php */
    const STATUS_BADGE = {
        delivered: 'available', paid: 'available', closed: 'available',
        sourcing: 'maintenance', ordered: 'maintenance', sent: 'maintenance', in_delivery: 'maintenance',
        received: 'waiting_part', partial: 'waiting_part', open: 'waiting_part', awaiting_licence: 'waiting_part', inspection: 'maintenance', blocked: 'breakdown',
        overdue: 'breakdown', cancelled: 'immobilized', draft: 'waiting_part',
    };
    const badge = (status, label) => '<span class="badge badge--' + (STATUS_BADGE[status] || 'neutral') + '">' + esc(label) + '</span>';

    /** @param {{t:Function, locale:string, currency:string}} ctx */
    function definitions(ctx) {
        const t = ctx.t;
        const qty = (v) => core.formatQty(v, ctx.locale);
        const money = (v) => core.formatNumber(v, ctx.locale, 2);
        const pct = (v) => Math.max(0, Math.min(100, Math.floor(Number(v) || 0)));

        // Couleurs de statut pour l'export Excel (mêmes teintes que les pastilles)
        const DELIVERY_FILL = { planned: 'FCEFE3', in_transit: 'E8EEF4', delivered: 'E7F3EC', cancelled: 'EEEFF0' };

        /** Colonnes communes aux factures clients et fournisseurs. */
        const invoiceCol = {
            // Fournisseur : notre référence interne + n° de la facture du fournisseur en évidence
            reference: (supplier) => ({
                data: 'reference', title: t(supplier ? 'js.col.supplier_invoice' : 'orders.col.reference'), orderable: true, locked: true,
                html: (r) => (supplier && r.external_number
                    ? '<span class="nowrap"><strong>' + esc(r.external_number) + '</strong></span><br><span class="muted">' + esc(r.reference) + '</span>'
                    : '<span class="nowrap"><strong>' + esc(r.reference) + '</strong></span>'),
                text: (r) => (supplier && r.external_number ? r.external_number + ' (' + r.reference + ')' : r.reference),
            }),
            party: (title) => ({
                data: 'party', title, orderable: true,
                html: (r) => '<span class="nowrap">' + esc(r.party_name) + '</span>',
                text: (r) => r.party_name,
            }),
            order: () => ({
                data: 'order', title: t('invoice.col.order'), orderable: true,
                html: (r) => '<span class="nowrap">' + esc(r.order_reference) + '</span><br><span class="muted">' + esc(r.customer_po_number) + '</span>',
                text: (r) => r.order_reference,
            }),
            site: () => ({
                data: 'site', title: t('orders.col.site'), orderable: true, visible: false,
                html: (r) => '<span class="mono">' + esc(r.site) + '</span>',
                text: (r) => r.site,
            }),
            date: () => ({
                data: 'invoice_date', title: t('invoice.col.date'), orderable: true,
                html: (r) => '<span class="nowrap">' + esc(core.formatDate(r.invoice_date)) + '</span>',
                text: (r) => r.invoice_date, type: 'date',
            }),
            // Échéance : compte à rebours tant que la facture est à régler
            due: () => ({
                data: 'due_date', title: t('invoice.col.due'), orderable: true,
                html: (r) => {
                    const date = '<span class="muted">' + esc(core.formatDate(r.due_date)) + '</span>';
                    if (r.days_left === null) return '<span class="nowrap">' + date + '</span>';
                    const d = Number(r.days_left);
                    const tone = d < 0 ? 'late' : d <= 7 ? 'soon' : 'ok';
                    const label = d < 0 ? t('js.due.late', { n: -d }) : d === 0 ? t('js.due.today') : t('js.due.left', { n: d });
                    return '<span class="nowrap"><span class="due due--' + tone + '">' + esc(label) + '</span> ' + date + '</span>';
                },
                text: (r) => r.due_date, type: 'date',
            }),
            amount: (title) => ({
                data: 'amount', title, orderable: true, className: 'num',
                html: (r) => '<span class="nowrap">' + esc(money(r.amount)) + ' <span class="muted">' + esc(r.currency) + '</span></span>',
                text: (r) => Number(r.amount), type: 'money',
            }),
            paid: (title) => ({
                data: 'paid', title, orderable: true, className: 'num', visible: false,
                html: (r) => (Number(r.paid) > 0 ? '<span class="nowrap">' + esc(money(r.paid)) + '</span>' : '<span class="muted">—</span>'),
                text: (r) => Number(r.paid), type: 'money',
            }),
            balance: () => ({
                data: 'balance', title: t('invoice.col.balance'), orderable: true, className: 'num',
                html: (r) => {
                    if (r.status === 'cancelled') return '<span class="muted">—</span>';
                    const paidPct = Number(r.amount) > 0 ? Math.min(100, Math.floor(Number(r.paid) / Number(r.amount) * 100)) : 0;
                    return '<span class="nowrap"><strong>' + esc(money(r.balance)) + '</strong></span>'
                        + (paidPct > 0 && paidPct < 100 ? '<br><span class="muted">' + esc(t('js.paid_pct', { n: paidPct })) + '</span>' : '');
                },
                text: (r) => (r.status === 'cancelled' ? 0 : Number(r.balance)), type: 'money',
            }),
            status: () => ({
                data: 'status', title: t('common.status'), orderable: true,
                html: (r) => (Number(r.overdue) ? badge('overdue', t('invoice.status.overdue')) : badge(r.status, t('invoice.status.' + r.status))),
                text: (r) => t(Number(r.overdue) ? 'invoice.status.overdue' : 'invoice.status.' + r.status),
            }),
            people: (title) => ({
                data: 'created_by', title, orderable: true, visible: false,
                html: (r) => '<span class="nowrap">' + esc(r.created_by_name) + '</span>'
                    + (r.approved_by_name ? '<br><span class="muted">' + esc(t('js.approved_by', { name: r.approved_by_name })) + '</span>' : ''),
                text: (r) => r.created_by_name + (r.approved_by_name ? ' / ' + r.approved_by_name : ''),
            }),
        };

        return {
            /* Liste des expéditions (tableau serveur). visible: false = colonne proposée dans « Colonnes » mais masquée par défaut. */
            deliveries: [
                {
                    data: 'reference', title: t('orders.col.reference'), orderable: true, locked: true,
                    html: (r) => '<strong>' + esc(r.reference) + '</strong>' + (r.products ? '<br><span class="muted">' + esc(r.products) + '</span>' : ''),
                    text: (r) => r.reference,
                },
                {
                    data: 'po', title: t('js.col.po'), orderable: true, visible: false,
                    html: (r) => '<span class="mono">' + esc(r.po_reference) + '</span>',
                    text: (r) => r.po_reference,
                },
                {
                    data: 'customer_po', title: t('js.col.customer_po'), orderable: true,
                    html: (r) => '<span class="mono">' + esc(r.customer_po_number) + '</span><br><span class="muted">' + esc(r.order_reference) + '</span>',
                    text: (r) => r.customer_po_number,
                },
                {
                    data: 'order', title: t('js.col.order'), orderable: true, visible: false,
                    html: (r) => esc(r.order_reference),
                    text: (r) => r.order_reference,
                },
                {
                    data: 'customer', title: t('orders.col.customer'), orderable: true,
                    html: (r) => esc(r.customer_name) + (r.delivery_site_name ? '<br><span class="muted">' + esc(r.delivery_site_name) + '</span>' : ''),
                    text: (r) => r.customer_name,
                },
                {
                    data: 'supplier', title: t('js.col.supplier'), orderable: true, visible: false,
                    html: (r) => esc(r.supplier_name),
                    text: (r) => r.supplier_name,
                },
                {
                    data: 'forwarder', title: t('js.col.forwarder'), orderable: true, visible: false,
                    html: (r) => (r.forwarder_name ? esc(r.forwarder_name) + (r.forwarder_ref ? '<br><span class="muted mono">' + esc(r.forwarder_ref) + '</span>' : '') : '<span class="muted">—</span>'),
                    text: (r) => (r.forwarder_name || '') + (r.forwarder_ref ? ' · ' + r.forwarder_ref : ''),
                },
                {
                    data: 'transport', title: t('js.col.transport'), orderable: true, visible: false,
                    html: (r) => esc(r.transport_label) + (r.transporter ? '<br><span class="muted">' + esc(r.transporter) + '</span>' : ''),
                    text: (r) => r.transport_label,
                },
                {
                    data: 'vehicle', title: t('js.col.vehicle'), orderable: false,
                    html: (r) => '<span class="mono">' + esc(r.vehicle_plate || '—') + '</span>' + (r.trailer_plate ? ' <span class="muted mono">/ ' + esc(r.trailer_plate) + '</span>' : ''),
                    text: (r) => [r.vehicle_plate, r.trailer_plate].filter(Boolean).join(' / '),
                },
                {
                    data: 'driver', title: t('js.col.driver'), orderable: false, visible: false,
                    html: (r) => esc(r.driver_name || '—') + (r.driver_phone ? '<br><span class="muted">' + esc(r.driver_phone) + '</span>' : ''),
                    text: (r) => [r.driver_name, r.driver_phone].filter(Boolean).join(' · '),
                },
                {
                    data: 'waybill', title: t('js.col.waybill'), orderable: false, visible: false,
                    html: (r) => '<span class="mono">' + esc(r.supplier_waybill || '—') + '</span>',
                    text: (r) => r.supplier_waybill || '',
                },
                {
                    data: 'shipped_at', title: t('js.col.shipped_at'), orderable: true,
                    html: (r) => esc(core.formatDate(r.shipped_at)),
                    text: (r) => r.shipped_at, type: 'day',
                },
                {
                    data: 'delivered_at', title: t('js.col.delivered_at'), orderable: true, visible: false,
                    html: (r) => esc(core.formatDate(r.delivered_at)),
                    text: (r) => r.delivered_at || '', type: 'day',
                },
                {
                    data: 'shipped', title: t('js.col.loaded'), orderable: true, className: 'num',
                    html: (r) => esc(qty(r.shipped)) + ' <span class="muted">' + esc(r.unit || '') + '</span>',
                    text: (r) => Number(r.shipped), type: 'number', total: true,
                },
                {
                    data: 'received', title: t('js.col.received'), orderable: true, className: 'num',
                    html: (r) => (r.received === null ? '<span class="muted">—</span>' : '<strong>' + esc(qty(r.received)) + '</strong>'),
                    text: (r) => (r.received === null ? '' : Number(r.received)), type: 'number', total: true,
                },
                {
                    data: 'gap', title: t('js.col.gap'), orderable: true, className: 'num',
                    html: (r) => (Number(r.gap) > 0 ? '<span class="text-bad">−' + esc(qty(r.gap)) + '</span>' : '<span class="muted">—</span>'),
                    text: (r) => Number(r.gap) || '', type: 'number', total: true, alert: (r) => Number(r.gap) > 0,
                },
                {
                    data: 'received_by', title: t('js.col.received_by'), orderable: false, visible: false,
                    html: (r) => esc(r.received_by || '—'),
                    text: (r) => r.received_by || '',
                },
                {
                    data: 'docs', title: t('js.col.documents'), orderable: true,
                    html: (r) => (Number(r.docs_total) > 0
                        ? '<span class="chip' + (Number(r.docs_done) < Number(r.docs_total) ? ' chip--warn' : ' chip--c-green') + '">' + esc(r.docs_done + '/' + r.docs_total) + '</span>'
                            + (Number(r.docs_to_validate) > 0 ? '<br><span class="muted">' + esc(t('js.to_validate', { n: r.docs_to_validate })) + '</span>' : '')
                        : '<span class="muted">—</span>'),
                    text: (r) => (Number(r.docs_total) > 0 ? r.docs_done + '/' + r.docs_total : ''),
                },
                {
                    data: 'status', title: t('common.status'), orderable: true, locked: true,
                    html: (r) => badge({ planned: 'received', in_transit: 'in_delivery', delivered: 'delivered' }[r.status] || 'cancelled', r.status_label),
                    text: (r) => r.status_label, fill: (r) => DELIVERY_FILL[r.status],
                },
            ],

            /* Livraisons d'un PO client (tableau client + export exécutif) */
            order_deliveries: [
                { data: 'reference', title: t('orders.col.reference'), html: (r) => '<strong>' + esc(r.reference) + '</strong>', text: (r) => r.reference },
                { data: 'status', title: t('common.status'), html: (r) => esc(r.status_label), text: (r) => r.status_label, fill: (r) => DELIVERY_FILL[r.status] },
                { data: 'products', title: t('orders.col.product'), html: (r) => esc(r.products), text: (r) => r.products },
                { data: 'po_reference', title: t('js.col.po'), html: (r) => esc(r.po_reference), text: (r) => r.po_reference },
                { data: 'supplier_name', title: t('js.col.supplier'), html: (r) => esc(r.supplier_name), text: (r) => r.supplier_name },
                { data: 'transport_mode', title: t('js.col.transport'), html: (r) => esc(r.transport_mode), text: (r) => r.transport_mode },
                { data: 'transporter', title: t('js.col.transporter'), html: (r) => esc(r.transporter), text: (r) => r.transporter || '' },
                { data: 'vehicle_plate', title: t('js.col.vehicle'), html: (r) => esc(r.vehicle_plate), text: (r) => [r.vehicle_plate, r.trailer_plate].filter(Boolean).join(' / ') },
                { data: 'driver_name', title: t('js.col.driver'), html: (r) => esc(r.driver_name), text: (r) => [r.driver_name, r.driver_phone].filter(Boolean).join(' · ') },
                { data: 'supplier_waybill', title: t('js.col.waybill'), html: (r) => esc(r.supplier_waybill), text: (r) => r.supplier_waybill || '' },
                { data: 'shipped_at', title: t('js.col.shipped_at'), html: (r) => esc(core.formatDate(r.shipped_at)), text: (r) => r.shipped_at, type: 'day' },
                { data: 'delivered_at', title: t('js.col.delivered_at'), html: (r) => esc(core.formatDate(r.delivered_at)), text: (r) => r.delivered_at || '', type: 'day' },
                { data: 'shipped', title: t('js.col.loaded'), html: (r) => esc(qty(r.shipped)), text: (r) => Number(r.shipped), type: 'number', total: true },
                { data: 'received', title: t('js.col.received'), html: (r) => esc(qty(r.received)), text: (r) => (r.received === null ? '' : Number(r.received)), type: 'number', total: true },
                { data: 'gap', title: t('js.col.gap'), html: (r) => esc(qty(r.gap)), text: (r) => (r.gap === null ? '' : Number(r.gap)), type: 'number', total: true, alert: (r) => Number(r.gap) > 0 },
                { data: 'unit', title: t('js.col.unit'), html: (r) => esc(r.unit), text: (r) => r.unit },
                { data: 'received_by', title: t('js.col.received_by'), html: (r) => esc(r.received_by), text: (r) => r.received_by || '' },
                { data: 'docs', title: t('js.col.documents'), html: (r) => esc(r.docs), text: (r) => r.docs || '' },
            ],

            /* Liste des PO clients : une ligne par dossier ; visible: false = proposée dans « Colonnes », masquée par défaut. */
            orders: [
                {
                    data: 'reference', title: t('orders.col.reference'), orderable: true, locked: true,
                    html: (r) => '<span class="nowrap"><strong>' + esc(r.reference) + '</strong> <span class="muted">· ' + esc(r.customer_po_number) + '</span></span>',
                    text: (r) => r.reference,
                },
                {
                    data: 'customer_po', title: t('js.col.customer_po'), orderable: false, visible: false,
                    html: (r) => esc(r.customer_po_number),
                    text: (r) => r.customer_po_number,
                },
                {
                    data: 'customer', title: t('orders.col.customer'), orderable: true,
                    html: (r) => '<span class="nowrap">' + esc(r.customer) + ' <span class="mode-tag mode-tag--' + esc(r.fulfillment_mode) + '" title="' + esc(t('order.mode.' + r.fulfillment_mode)) + '">'
                        + esc(t('order.mode_short.' + r.fulfillment_mode)) + '</span></span>',
                    text: (r) => r.customer + ' (' + t('order.mode.' + r.fulfillment_mode) + ')',
                },
                {
                    data: 'site', title: t('orders.col.site'), orderable: true, visible: false,
                    html: (r) => '<span class="mono">' + esc(r.site) + '</span>',
                    text: (r) => r.site,
                },
                {
                    data: 'po_date', title: t('orders.col.po_date'), orderable: true, visible: false,
                    html: (r) => esc(core.formatDate(r.po_date)),
                    text: (r) => r.po_date, type: 'date',
                },
                {
                    // Échéance : compte à rebours tant que le dossier n'est pas livré / terminé
                    data: 'required_date', title: t('orders.col.due'), orderable: true,
                    html: (r) => {
                        if (!r.required_date) return '<span class="muted">—</span>';
                        const date = '<span class="muted">' + esc(core.formatDate(r.required_date)) + '</span>';
                        if (['delivered', 'closed', 'cancelled'].includes(r.status) || r.days_left === null) return date;
                        const d = Number(r.days_left);
                        const tone = d < 0 ? 'late' : d <= 7 ? 'soon' : 'ok';
                        const label = d < 0 ? t('js.due.late', { n: -d }) : d === 0 ? t('js.due.today') : t('js.due.left', { n: d });
                        return '<span class="nowrap"><span class="due due--' + tone + '">' + esc(label) + '</span> ' + date + '</span>';
                    },
                    text: (r) => r.required_date || '', type: 'date',
                },
                {
                    data: 'amount', title: t('orders.col.amount'), orderable: true, className: 'num',
                    html: (r) => '<span class="nowrap">' + esc(money(r.amount)) + ' <span class="muted">' + esc(r.currency) + '</span></span>',
                    text: (r) => Number(r.amount), type: 'money',
                },
                {
                    data: 'progress', title: t('orders.vital.delivered'), orderable: true,
                    html: (r) => '<span class="nowrap"><span class="meter meter--inline"><span class="meter__bar" style="width:' + pct(r.progress) + '%"></span></span> <span class="muted">' + pct(r.progress) + ' %</span></span>',
                    text: (r) => pct(r.progress) / 100, type: 'number',
                },
                {
                    data: 'next', title: t('orders.col.next'), orderable: false,
                    html: (r) => (r.next ? '<span class="next next--' + esc(r.next_tone) + '">' + esc(t('orders.next.' + r.next)) + '</span>' : '<span class="muted">—</span>'),
                    text: (r) => (r.next ? t('orders.next.' + r.next) : ''),
                },
                {
                    data: 'status', title: t('common.status'), orderable: true,
                    html: (r) => badge(r.status, t('order.status.' + r.status)),
                    text: (r) => t('order.status.' + r.status),
                },
            ],

            // Factures clients (à encaisser) : expédition facturée, HT / TVA / TTC, approbation
            invoices_receivable: [
                invoiceCol.reference(false),
                invoiceCol.party(t('orders.col.customer')),
                invoiceCol.order(),
                {
                    data: 'delivery', title: t('js.col.shipment'), orderable: true,
                    html: (r) => (r.delivery_reference ? '<span class="nowrap">' + esc(r.delivery_reference) + '</span>' : '<span class="muted">—</span>'),
                    text: (r) => r.delivery_reference || '',
                },
                invoiceCol.site(),
                invoiceCol.date(),
                invoiceCol.due(),
                {
                    data: 'subtotal', title: t('js.col.subtotal'), orderable: true, className: 'num',
                    html: (r) => '<span class="nowrap">' + esc(money(r.subtotal)) + '</span>',
                    text: (r) => Number(r.subtotal), type: 'money',
                },
                {
                    data: 'vat', title: t('js.col.vat'), orderable: true, className: 'num',
                    html: (r) => (r.vat_rate === null ? '<span class="muted">—</span>'
                        : '<span class="nowrap">' + esc(money(r.vat_amount)) + '</span><br><span class="muted">' + esc(money(r.vat_rate)) + ' %</span>'),
                    text: (r) => Number(r.vat_amount), type: 'money',
                },
                invoiceCol.amount(t('js.col.amount_ttc')),
                invoiceCol.paid(t('js.col.collected')),
                invoiceCol.balance(),
                invoiceCol.status(),
                invoiceCol.people(t('js.col.issued_by')),
            ],

            // Factures fournisseurs (à payer) : n° de la facture du fournisseur, PO fournisseur
            invoices_payable: [
                invoiceCol.reference(true),
                invoiceCol.party(t('js.col.supplier')),
                {
                    data: 'po', title: t('js.col.supplier_po'), orderable: true,
                    html: (r) => (r.po_reference ? '<span class="nowrap">' + esc(r.po_reference) + '</span>' : '<span class="muted">—</span>'),
                    text: (r) => r.po_reference || '',
                },
                invoiceCol.order(),
                invoiceCol.site(),
                invoiceCol.date(),
                invoiceCol.due(),
                invoiceCol.amount(t('orders.col.amount')),
                invoiceCol.paid(t('js.col.paid_out')),
                invoiceCol.balance(),
                invoiceCol.status(),
                invoiceCol.people(t('js.col.recorded_by')),
            ],

            items: [
                {
                    data: 'sku', title: t('items.col.sku'), orderable: true,
                    html: (r) => '<strong>' + esc(r.sku) + '</strong>' + (r.hs_code ? '<br><span class="muted">HS ' + esc(r.hs_code) + '</span>' : ''),
                    text: (r) => r.sku,
                },
                {
                    data: 'name', title: t('items.col.name'), orderable: true,
                    html: (r) => esc(r.name),
                    text: (r) => r.name,
                },
                {
                    data: 'category', title: t('items.col.category'), orderable: true,
                    html: (r) => (r.category ? esc(r.category) : '<span class="muted">—</span>'),
                    text: (r) => r.category || '',
                },
                {
                    data: 'quantity', title: t('items.col.quantity'), orderable: true, className: 'num',
                    html: (r) => '<strong>' + esc(qty(r.quantity)) + '</strong> <span class="muted">' + esc(r.unit) + '</span>',
                    text: (r) => Number(r.quantity), type: 'number',
                },
                {
                    data: 'min_stock', title: t('items.col.min_stock'), orderable: true, className: 'num',
                    html: (r) => esc(qty(r.min_stock)),
                    text: (r) => Number(r.min_stock), type: 'number',
                },
                {
                    data: 'avg_cost', title: t('items.col.avg_cost') + ' (' + ctx.currency + ')', orderable: true, className: 'num',
                    html: (r) => esc(money(r.avg_cost)),
                    text: (r) => Number(r.avg_cost), type: 'money',
                },
                {
                    data: 'value', title: t('items.col.value') + ' (' + ctx.currency + ')', orderable: true, className: 'num',
                    html: (r) => esc(money(r.value)),
                    text: (r) => Number(r.value), type: 'money',
                },
                {
                    data: 'status', title: t('items.col.status'), orderable: false,
                    html: (r) => {
                        const s = stockState(r);
                        return '<span class="badge badge--' + STATE_BADGE[s] + '">' + esc(t('items.state.' + s)) + '</span>';
                    },
                    text: (r) => t('items.state.' + stockState(r)),
                },
            ],

            movements: [
                {
                    data: 'moved_at', title: t('movements.col.date'), orderable: true,
                    html: (r) => esc(core.formatDate(r.moved_at, true)),
                    text: (r) => r.moved_at, type: 'date',
                },
                {
                    data: 'reference', title: t('movements.col.reference'), orderable: true,
                    html: (r) => '<strong>' + esc(r.reference) + '</strong>' + (r.note ? '<br><span class="muted">' + esc(r.note) + '</span>' : ''),
                    text: (r) => r.reference,
                },
                {
                    data: 'type', title: t('movements.col.type'), orderable: true,
                    html: (r) => '<span class="badge badge--mv-' + esc(r.type) + '">' + esc(t('mv.type.' + r.type)) + '</span>',
                    text: (r) => t('mv.type.' + r.type),
                },
                {
                    data: 'item', title: t('movements.col.item'), orderable: true,
                    html: (r) => '<strong>' + esc(r.item_sku) + '</strong><br><span class="muted">' + esc(r.item_name) + '</span>',
                    text: (r) => r.item_sku + ' — ' + r.item_name,
                },
                {
                    data: 'warehouse', title: t('movements.col.warehouse'), orderable: true,
                    html: (r) => esc(r.warehouse_code),
                    text: (r) => r.warehouse_code,
                },
                {
                    data: 'quantity', title: t('movements.col.quantity'), orderable: true, className: 'num',
                    html: (r) => {
                        const isIn = IN_TYPES.indexOf(r.type) !== -1;
                        return '<span class="qty qty--' + (isIn ? 'in' : 'out') + '">' + (isIn ? '+' : '−') + esc(qty(r.quantity)) + '</span> <span class="muted">' + esc(r.unit) + '</span>';
                    },
                    text: (r) => (IN_TYPES.indexOf(r.type) !== -1 ? 1 : -1) * Number(r.quantity), type: 'number',
                },
                {
                    data: 'unit_cost_base', title: t('movements.col.cost') + ' (' + ctx.currency + ')', orderable: true, className: 'num',
                    html: (r) => esc(money(r.unit_cost_base)) + (r.currency_code && r.currency_code !== ctx.currency
                        ? '<br><span class="muted">' + esc(money(r.unit_cost)) + ' ' + esc(r.currency_code) + '</span>' : ''),
                    text: (r) => Number(r.unit_cost_base), type: 'money',
                },
                {
                    data: 'user', title: t('movements.col.user'), orderable: true,
                    html: (r) => esc(r.user_name),
                    text: (r) => r.user_name,
                },
            ],
        };
    }

    return { definitions, stockState };
});
