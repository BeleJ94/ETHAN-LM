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
        // Dates : en UTC (Excel n'a pas de fuseau : sinon le jour affiché recule d'un jour à l'est de Greenwich) ;
        // texte non daté ou nombre non représentable : laissé tel quel / vide pour ne pas corrompre le classeur.
        const toCell = (v, type) => {
            if ((type === 'date' || type === 'day') && v) {
                const m = String(v).match(/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}(?::\d{2})?))?/);
                if (!m) return v;
                const d = new Date(m[1] + 'T' + (type === 'day' || !m[2] ? '00:00:00' : m[2].padEnd(8, ':00')) + 'Z');
                return Number.isNaN(d.getTime()) ? v : d;
            }
            return typeof v === 'number' && !Number.isFinite(v) ? null : v;
        };
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
        ws.pageSetup = { orientation: opts.orientation || 'landscape', paperSize: 9, fitToPage: true, fitToWidth: 1, fitToHeight: 0, printTitlesRow: headRow + ':' + headRow };
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
            pageOrientation: opts.orientation || 'landscape',
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

    /**
     * Rapport Excel en sections (ex. relevé de tiers) : une feuille par élément de opts.sheets,
     * chaque section = intitulé, synthèse (libellé / montant), tableau typé, ligne de pied.
     * Mise en page : orientation choisie, ajustement à la largeur, en-tête / pied et n° de page sur chaque page imprimée.
     * @param {{vendor:string, accent:string, title:string, subtitle:string, generated:string, orientation?:string, totalLabel?:string,
     *          sheets:Array<{name:string, title:string, party:?string, sections:Array<{heading:string, summary?:Array, columns:Array, rows:Array, footer?:Array, total?:boolean}>}>}} opts
     */
    async function report(opts) {
        await loadScript(opts.vendor + 'exceljs/exceljs.min.js');
        const accent = 'FF' + opts.accent.replace('#', '');
        const muted = { argb: 'FF5B6573' };
        const thin = { style: 'thin', color: { argb: 'FFDDE1E6' } };
        const wb = new root.ExcelJS.Workbook();
        wb.creator = 'ETHAN LM';
        const used = new Set();
        const sheetName = (name) => {
            let base = String(name).replace(/[\\/*?:[\]]/g, ' ').slice(0, 28) || 'Relevé';
            let n = base;
            for (let i = 2; used.has(n.toLowerCase()); i++) n = base + ' ' + i;
            used.add(n.toLowerCase());
            return n;
        };
        const fmt = { money: '#,##0.00', number: '#,##0', day: 'dd/mm/yyyy', pct: '0.0%' };
        // Seules les vraies dates ISO deviennent des dates Excel (un libellé dans une colonne date reste du texte) ;
        // une valeur non représentable (NaN, Infinity) est laissée vide : Excel refuserait le classeur.
        // Largeur d'affichage : montant formaté (séparateurs de milliers, 2 décimales), date jj/mm/aaaa, sinon le texte
        const cellWidth = (v, type) => {
            if (v === null || v === undefined || v === '') return 0;
            if (typeof v === 'number') {
                const digits = String(Math.trunc(Math.abs(v))).length;
                return digits + Math.floor((digits - 1) / 3) + (type === 'money' ? 3 : type === 'pct' ? 4 : 0) + 3;
            }
            return type === 'day' ? 12 : String(v).length + 2;
        };
        const value = (v, type) => {
            if (v === null || v === undefined) return null;
            if (type === 'day' && /^\d{4}-\d{2}-\d{2}$/.test(String(v))) return new Date(String(v) + 'T00:00:00Z');
            if (typeof v === 'number' && !Number.isFinite(v)) return null;
            return v;
        };

        // ---------- Tableau de bord exécutif (feuille « Résumé ») ----------
        const TONES = { accent: { fill: accent, font: 'FFFFFFFF', label: 'FFDCE6F0' }, bad: { fill: 'FFFDECEA', font: 'FFB42318', label: 'FF8A1C12' },
            good: { fill: 'FFE9F5EE', font: 'FF1E6B3A', label: 'FF1E6B3A' }, null: { fill: 'FFF5F7FA', font: 'FF1B2430', label: 'FF5B6573' } };
        const COLS = 8; // grille A:H, tuiles de 2 colonnes
        const solid = (argb) => ({ type: 'pattern', pattern: 'solid', fgColor: { argb } });
        const band = (ws, text) => {
            const r = ws.addRow([text]);
            ws.mergeCells(r.number, 1, r.number, COLS);
            r.height = 22;
            r.getCell(1).font = { bold: true, size: 12, color: { argb: 'FFFFFFFF' } };
            r.getCell(1).fill = solid(accent);
            r.getCell(1).alignment = { vertical: 'middle', indent: 1 };
            return r;
        };
        const subhead = (ws, text) => {
            ws.addRow([]);
            const r = ws.addRow([text]);
            ws.mergeCells(r.number, 1, r.number, COLS);
            r.getCell(1).font = { bold: true, size: 10, color: { argb: accent } };
            r.getCell(1).border = { bottom: { style: 'medium', color: { argb: accent } } };
        };
        const kpiFmt = (type) => ({ money: '#,##0.00', pct: '0.0%', number: '#,##0', days: '#,##0" j"' }[type] || 'General');
        function dashboard(ws, d) {
            // Informations : libellé / valeur sur deux colonnes de blocs
            if (d.info && d.info.length) {
                subhead(ws, opts.infoTitle || 'Informations');
                d.info.forEach(([label, v]) => {
                    const r = ws.addRow([label, null, v]);
                    ws.mergeCells(r.number, 1, r.number, 2);
                    ws.mergeCells(r.number, 3, r.number, COLS);
                    r.getCell(1).font = { color: muted };
                    r.getCell(3).font = { bold: true };
                    r.getCell(3).alignment = { wrapText: true };
                });
            }
            d.currencies.forEach((c) => {
                ws.addRow([]);
                band(ws, (opts.currencyTitle || 'Compte en :cur').replace(':cur', c.currency));
                // Tuiles : 4 par ligne (libellé au-dessus, valeur en grand)
                for (let i = 0; i < c.kpis.length; i += 4) {
                    ws.addRow([]).height = 6;
                    const lab = ws.addRow([]);
                    const val = ws.addRow([]);
                    lab.height = 18;
                    val.height = 28;
                    c.kpis.slice(i, i + 4).forEach((k, j) => {
                        const col = j * 2 + 1;
                        const tone = TONES[k.tone] || TONES.null;
                        ws.mergeCells(lab.number, col, lab.number, col + 1);
                        ws.mergeCells(val.number, col, val.number, col + 1);
                        const lc = lab.getCell(col);
                        const vc = val.getCell(col);
                        lc.value = String(k.label).toUpperCase();
                        lc.font = { size: 8, bold: true, color: { argb: tone.label } };
                        vc.value = typeof k.value === 'number' && Number.isFinite(k.value) ? k.value : null;
                        vc.numFmt = kpiFmt(k.type);
                        vc.font = { size: 15, bold: true, color: { argb: tone.font } };
                        vc.alignment = { horizontal: 'left', vertical: 'middle' };
                        lc.alignment = { vertical: 'bottom', indent: 1 };
                        vc.alignment = { vertical: 'middle', indent: 1 };
                        const edge = { style: 'thin', color: { argb: 'FFFFFFFF' } };
                        [lc, vc].forEach((cell) => { cell.fill = solid(tone.fill); cell.border = { left: edge, right: edge }; });
                    });
                }
                // Ancienneté : tranche (pastille de couleur), montant, part, barre proportionnelle
                subhead(ws, opts.agingTitle || 'Ancienneté');
                const th = ws.addRow([opts.bucketLabel || 'Tranche', null, opts.amountLabel || 'Montant', opts.shareLabel || 'Part', opts.barLabel || 'Répartition']);
                ws.mergeCells(th.number, 1, th.number, 2);
                ws.mergeCells(th.number, 5, th.number, COLS);
                th.eachCell((cell) => { cell.font = { bold: true, color: { argb: 'FFFFFFFF' } }; cell.fill = solid(accent); });
                const first = ws.rowCount + 1;
                c.buckets.forEach((b) => {
                    const r = ws.addRow(['■ ' + b.label, null, b.amount, b.share, b.share]);
                    ws.mergeCells(r.number, 1, r.number, 2);
                    ws.mergeCells(r.number, 5, r.number, COLS);
                    r.getCell(1).font = { bold: true, color: { argb: 'FF' + b.color } };
                    r.getCell(3).numFmt = '#,##0.00';
                    r.getCell(4).numFmt = '0.0%';
                    r.getCell(5).numFmt = ';;;'; // valeur masquée : seule la barre est visible
                    for (let col = 1; col <= COLS; col++) r.getCell(col).border = { bottom: thin };
                    if (b.amount > 0 && b !== c.buckets[0]) r.getCell(3).font = { bold: true, color: { argb: 'FF' + b.color } };
                });
                const last = ws.rowCount;
                ws.addConditionalFormatting({
                    ref: 'E' + first + ':E' + last,
                    rules: [{ type: 'dataBar', cfvo: [{ type: 'num', value: 0 }, { type: 'num', value: 1 }], color: { argb: accent }, gradient: false, showValue: false }],
                });
                const tot = ws.addRow([opts.totalLabel || 'Total', null, c.buckets.reduce((s, b) => s + b.amount, 0), c.buckets.reduce((s, b) => s + b.share, 0)]);
                ws.mergeCells(tot.number, 1, tot.number, 2);
                tot.getCell(3).numFmt = '#,##0.00';
                tot.getCell(4).numFmt = '0.0%';
                for (let col = 1; col <= COLS; col++) {
                    tot.getCell(col).font = { bold: true };
                    tot.getCell(col).border = { top: { style: 'medium', color: { argb: accent } } };
                }
                // Les plus exposés (factures en retard ou tiers)
                if (c.top && c.top.rows.length) {
                    subhead(ws, c.top.title);
                    const hr = ws.addRow(c.top.columns.map((x) => x[0]));
                    hr.eachCell((cell) => { cell.font = { bold: true, color: { argb: 'FFFFFFFF' } }; cell.fill = solid(accent); cell.alignment = { wrapText: true, vertical: 'middle' }; });
                    c.top.rows.forEach((row, ri) => {
                        const r = ws.addRow(row.map((v, i) => value(v, c.top.columns[i][1])));
                        c.top.columns.forEach((col, i) => {
                            const cell = r.getCell(i + 1);
                            if (fmt[col[1]]) cell.numFmt = fmt[col[1]];
                            cell.border = { bottom: thin };
                            if (ri % 2) cell.fill = solid('FFF7F9FB');
                            if (col[1] === 'text') cell.alignment = { wrapText: true, vertical: 'top' };
                        });
                    });
                }
            });
        }

        opts.sheets.forEach((sh) => {
            const ws = wb.addWorksheet(sheetName(sh.name), sh.dashboard ? { views: [{ showGridLines: false }] } : {});
            const width = Math.max(sh.dashboard ? COLS : 2, ...sh.sections.map((s) => s.columns.length));
            const head = ws.addRow([sh.title]);
            head.font = { bold: true, size: 16, color: { argb: accent } };
            head.height = 24;
            if (sh.party) ws.addRow([sh.party]).font = { bold: true, size: 12 };
            ws.addRow([opts.subtitle]).font = { color: muted };
            ws.addRow([opts.generated]).font = { italic: true, color: muted };
            if (sh.dashboard) dashboard(ws, sh.dashboard);
            const widths = new Array(width).fill(sh.dashboard ? 16 : 10);
            sh.sections.forEach((sec) => {
                ws.addRow([]);
                const h = ws.addRow([sec.heading]);
                h.font = { bold: true, size: 12, color: { argb: accent } };
                (sec.summary || []).forEach(([label, v]) => {
                    const r = ws.addRow([label, v]);
                    r.getCell(1).font = { color: muted };
                    r.getCell(2).numFmt = fmt.money;
                    r.getCell(2).font = { bold: true };
                    widths[0] = Math.max(widths[0], String(label).length + 2);
                });
                if (sec.summary && sec.summary.length) ws.addRow([]);
                const th = ws.addRow(sec.columns.map((c) => c[0]));
                th.eachCell((cell) => {
                    cell.font = { bold: true, color: { argb: 'FFFFFFFF' } };
                    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: accent } };
                    cell.alignment = { vertical: 'middle', wrapText: true };
                });
                const totals = sec.columns.map(() => 0);
                sec.rows.forEach((row) => {
                    const r = ws.addRow(row.map((v, i) => value(v, sec.columns[i][1])));
                    sec.columns.forEach((c, i) => {
                        const cell = r.getCell(i + 1);
                        if (fmt[c[1]]) cell.numFmt = fmt[c[1]];
                        cell.border = { bottom: thin };
                        if (c[1] === 'money' && typeof row[i] === 'number') totals[i] += row[i];
                        widths[i] = Math.max(widths[i], cellWidth(row[i], c[1]));
                    });
                });
                const foot = sec.footer || (sec.total ? sec.columns.map((c, i) => (i === 0 ? (opts.totalLabel || 'Total') : (c[1] === 'money' ? Math.round(totals[i] * 100) / 100 : ''))) : null);
                if (foot) {
                    const r = ws.addRow(foot.map((v, i) => value(v, sec.columns[i] ? sec.columns[i][1] : 'text')));
                    r.eachCell({ includeEmpty: true }, (cell, col) => {
                        const c = sec.columns[col - 1];
                        if (c && fmt[c[1]]) cell.numFmt = fmt[c[1]];
                        cell.font = { bold: true };
                        cell.border = { top: { style: 'medium', color: { argb: accent } } };
                    });
                }
                sec.columns.forEach((c, i) => { widths[i] = Math.max(widths[i], String(c[0]).length + 2); });
            });
            widths.forEach((w, i) => { ws.getColumn(i + 1).width = Math.min(w, sh.dashboard ? 26 : 48); });
            ws.pageSetup = { orientation: opts.orientation === 'landscape' ? 'landscape' : 'portrait', paperSize: 9, fitToPage: true, fitToWidth: 1, fitToHeight: 0,
                margins: { left: 0.4, right: 0.4, top: 0.6, bottom: 0.6, header: 0.3, footer: 0.3 } };
            ws.headerFooter = {
                oddHeader: '&L&"-,Bold"' + String(sh.title).replace(/&/g, '&&') + (sh.party ? ' — ' + String(sh.party).replace(/&/g, '&&') : '') + '&R' + String(opts.subtitle).replace(/&/g, '&&'),
                oddFooter: '&LETHAN LM&C' + String(opts.generated).replace(/&/g, '&&') + '&R&P / &N',
            };
        });
        const buf = await wb.xlsx.writeBuffer();
        download(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), safeName(opts.title) + '.xlsx');
    }

    return { buildMatrix, excel, pdf, report };
});
