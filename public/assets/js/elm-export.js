/* ETHAN LM — exports Excel / PDF (bibliothèques chargées à la demande). */
(function (root, factory) {
    const isNode = typeof module === 'object' && module.exports;
    // root est transmis : ExcelJS et pdfMake sont chargés à la demande sur l'objet global.
    const mod = factory(root);
    if (isNode) {
        module.exports = mod;
    } else {
        root.ELM = root.ELM || {};
        root.ELM.export = mod;
    }
})(typeof self !== 'undefined' ? self : this, function (root) {
    'use strict';

    const loaded = {};

    function loadScript(src) {
        if (!loaded[src]) {
            loaded[src] = new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = src;
                s.onload = resolve;
                s.onerror = () => reject(new Error('load ' + src));
                document.head.appendChild(s);
            });
        }
        return loaded[src];
    }

    /**
     * Matrice d'export pure : en-têtes + lignes typées à partir des définitions de colonnes.
     * Facultatif par colonne : fill(row) → couleur de fond, alert(row) → valeur en rouge, total → somme en pied.
     */
    function buildMatrix(columns, rows) {
        const matrix = {
            headers: columns.map((c) => c.title),
            types: columns.map((c) => c.type || 'text'),
            rows: rows.map((r) => columns.map((c) => {
                const v = c.text(r);
                return v === null || v === undefined ? '' : v;
            })),
            fills: rows.map((r) => columns.map((c) => (c.fill ? c.fill(r) || null : null))),
            alerts: rows.map((r) => columns.map((c) => (c.alert ? !!c.alert(r) : false))),
        };
        matrix.totals = columns.map((c, i) => (c.total ? matrix.rows.reduce((s, row) => s + (typeof row[i] === 'number' ? row[i] : 0), 0) : null));
        return matrix;
    }

    function safeName(title) {
        return String(title).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\w-]+/g, '_') + '_' + new Date().toISOString().slice(0, 10);
    }

    function download(blob, filename) {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }

    async function excel(opts) {
        await loadScript(opts.vendor + 'exceljs/exceljs.min.js');
        const m = buildMatrix(opts.columns, opts.rows);
        const accent = 'FF' + opts.accent.replace('#', '');
        const muted = { argb: 'FF5B6573' };
        const thin = { style: 'thin', color: { argb: 'FFDDE1E6' } };
        const wb = new root.ExcelJS.Workbook();
        wb.creator = 'ETHAN LM';
        const ws = wb.addWorksheet(String(opts.title).replace(/[\\/*?:[\]]/g, ' ').slice(0, 31));
        const toCell = (v, type) => ((type === 'date' || type === 'day') && v ? new Date(String(v).replace(' ', 'T') + (type === 'day' ? 'T00:00:00' : '')) : v);
        const fmtCell = (cell, type) => {
            if (type === 'money') cell.numFmt = '#,##0.00';
            if (type === 'number') cell.numFmt = '#,##0.###';
            if (type === 'date') cell.numFmt = 'dd/mm/yyyy hh:mm';
            if (type === 'day') cell.numFmt = 'dd/mm/yyyy';
        };

        // En-tête du rapport
        const title = ws.addRow([opts.title]);
        title.font = { bold: true, size: 16, color: { argb: accent } };
        title.height = 24;
        if (opts.context) ws.addRow([opts.context]).font = { bold: true, size: 11 };
        ws.addRow([opts.subtitle]).font = { italic: true, color: muted };
        ws.addRow([]);

        // Bloc de synthèse (version « exécutive ») : libellé / valeur, encadré
        if (opts.summary && opts.summary.length) {
            const hdr = ws.addRow([opts.summaryTitle || '']);
            hdr.font = { bold: true, color: { argb: accent } };
            opts.summary.forEach(([label, value, format]) => {
                const row = ws.addRow([label, value]);
                row.getCell(1).font = { color: muted };
                const cell = row.getCell(2);
                cell.font = { bold: true, size: 12 };
                cell.alignment = { horizontal: 'right' };
                if (typeof value === 'number') cell.numFmt = format === 'pct' ? '0%' : '#,##0.###';
                [1, 2].forEach((c) => { row.getCell(c).border = { bottom: thin }; row.getCell(c).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF9FAFB' } }; });
            });
            ws.addRow([]);
        }

        // Tableau détaillé
        const headRow = ws.rowCount + 1;
        const head = ws.addRow(m.headers);
        head.height = 20;
        head.eachCell((cell) => {
            cell.font = { bold: true, color: { argb: 'FFFFFFFF' } };
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: accent } };
            cell.alignment = { vertical: 'middle', wrapText: true };
        });
        m.rows.forEach((r, ri) => {
            const row = ws.addRow(r.map((v, i) => toCell(v, m.types[i])));
            m.types.forEach((type, i) => {
                const cell = row.getCell(i + 1);
                fmtCell(cell, type);
                cell.border = { bottom: thin };
                if (m.fills[ri][i]) cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + m.fills[ri][i] } };
                if (m.alerts[ri][i]) cell.font = { bold: true, color: { argb: 'FFB42318' } };
            });
        });
        // Ligne de totaux pour les colonnes marquées « total »
        if (m.totals.some((v) => v !== null)) {
            const totalRow = ws.addRow(m.totals.map((v, i) => (i === 0 ? (opts.totalLabel || 'Total') : (v === null ? '' : v))));
            totalRow.eachCell({ includeEmpty: true }, (cell, col) => {
                cell.font = { bold: true };
                cell.border = { top: { style: 'medium', color: { argb: accent } } };
                fmtCell(cell, m.types[col - 1]);
            });
        }
        ws.autoFilter = { from: { row: headRow, column: 1 }, to: { row: headRow, column: m.headers.length } };
        ws.views = [{ state: 'frozen', ySplit: headRow }];
        ws.columns.forEach((col, i) => {
            const width = Math.max(String(m.headers[i]).length, ...m.rows.slice(0, 500).map((r) => String(r[i]).length));
            col.width = Math.min(Math.max(width + 2, 10), 60);
        });
        if (opts.summary && opts.summary.length) {
            ws.getColumn(1).width = Math.max(ws.getColumn(1).width, ...opts.summary.map(([l]) => String(l).length + 2));
        }
        ws.pageSetup = { orientation: 'landscape', paperSize: 9, fitToPage: true, fitToWidth: 1, fitToHeight: 0, printTitlesRow: headRow + ':' + headRow };
        ws.headerFooter = { oddFooter: '&LETHAN LM&R&P / &N' };
        const buf = await wb.xlsx.writeBuffer();
        download(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), safeName(opts.title) + '.xlsx');
    }

    async function pdf(opts) {
        await loadScript(opts.vendor + 'pdfmake/pdfmake.min.js');
        await loadScript(opts.vendor + 'pdfmake/vfs_fonts.js');
        const m = buildMatrix(opts.columns, opts.rows);
        const fmt = (v, type) => (type === 'money' || type === 'number') && v !== '' ? opts.formatNumber(v, type === 'money' ? 2 : undefined) : (type === 'date' ? opts.formatDate(v) : String(v));
        const body = [m.headers.map((h) => ({ text: h, style: 'th' }))].concat(
            m.rows.map((r) => r.map((v, i) => ({ text: fmt(v, m.types[i]), alignment: m.types[i] === 'money' || m.types[i] === 'number' ? 'right' : 'left' })))
        );
        root.pdfMake.createPdf({
            pageOrientation: 'landscape',
            pageSize: 'A4',
            pageMargins: [28, 40, 28, 36],
            content: [
                { text: opts.title, style: 'title' },
                { text: opts.subtitle, style: 'sub' },
                { table: { headerRows: 1, body }, layout: 'lightHorizontalLines' },
            ],
            footer: (page, count) => ({ columns: [{ text: 'ETHAN LM', margin: [28, 0] }, { text: page + ' / ' + count, alignment: 'right', margin: [28, 0] }], fontSize: 8, color: '#5B6573' }),
            styles: {
                title: { fontSize: 14, bold: true, color: opts.accent },
                sub: { fontSize: 9, color: '#5B6573', margin: [0, 2, 0, 10] },
                th: { bold: true, color: '#FFFFFF', fillColor: opts.accent },
            },
            defaultStyle: { fontSize: 8 },
        }).download(safeName(opts.title) + '.pdf');
    }

    return { buildMatrix, excel, pdf };
});
