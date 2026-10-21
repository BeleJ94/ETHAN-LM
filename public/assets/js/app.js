/* ETHAN LM — branchement déclaratif des interactions (attributs data-*). Aucun script inline. */
(function ($) {
    'use strict';

    const config = JSON.parse(document.getElementById('app-config').textContent);
    const core = window.ELM.core;
    const t = core.translator(config.i18n);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const vendor = config.base.replace(/\/$/, '') + '/assets/vendor/';
    const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#1F4E79';
    const tables = {};

    $.ajaxSetup({ headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' } });

    const refreshIcons = () => window.lucide && window.lucide.createIcons({ attrs: { 'stroke-width': 2 } });

    /* ---------- Sidebar ---------- */
    $(document).on('click', '[data-toggle-sidebar]', () => document.body.classList.toggle('sidebar-open'));
    try {
        if (localStorage.getItem('elm.sidebarMini') === '1') document.body.classList.add('sidebar-mini');
    } catch (e) { /* stockage indisponible */ }
    $(document).on('click', '[data-sidebar-mini]', () => {
        const mini = document.body.classList.toggle('sidebar-mini');
        try { localStorage.setItem('elm.sidebarMini', mini ? '1' : '0'); } catch (e) { /* ignore */ }
    });

    /* ==========================================================================
     * Aperçu des fichiers en modale : a[data-preview] (PDF, image, document imprimable HTML).
     * Barre d'outils : précédent / suivant (fichiers de la même liste), imprimer, télécharger, ouvrir dans un onglet.
     * Sans JavaScript, le lien s'ouvre dans un nouvel onglet (href + target).
     * ========================================================================== */
    function previewGroup(link) {
        const scope = link.closest('[data-preview-group], .file-list, .checklist-docs, table, .hero__actions') || document.body;
        const seen = new Set();
        return Array.from(scope.querySelectorAll('a[data-preview]')).filter((a) => !seen.has(a.href) && seen.add(a.href));
    }

    function openPreview(items, start) {
        let index = start;
        let zoomed = false;
        const shell = document.createElement('div');
        shell.className = 'preview';
        shell.innerHTML = '<header class="preview__bar">'
            + '<div class="preview__nav"><button type="button" class="icon-btn" data-pv="prev" aria-label="' + core.escapeHtml(t('js.preview.prev')) + '" title="' + core.escapeHtml(t('js.preview.prev')) + '"><i data-lucide="chevron-left"></i></button>'
            + '<span class="preview__count" data-pv="count"></span>'
            + '<button type="button" class="icon-btn" data-pv="next" aria-label="' + core.escapeHtml(t('js.preview.next')) + '" title="' + core.escapeHtml(t('js.preview.next')) + '"><i data-lucide="chevron-right"></i></button></div>'
            + '<strong class="preview__title" data-pv="title"></strong>'
            + '<div class="preview__tools">'
            + '<button type="button" class="btn btn--sm" data-pv="print"><i data-lucide="printer"></i> ' + core.escapeHtml(t('js.preview.print')) + '</button>'
            + '<a class="btn btn--sm" data-pv="download"><i data-lucide="download"></i> ' + core.escapeHtml(t('js.preview.download')) + '</a>'
            + '<a class="icon-btn" data-pv="open" target="_blank" rel="noopener" title="' + core.escapeHtml(t('js.preview.open')) + '" aria-label="' + core.escapeHtml(t('js.preview.open')) + '"><i data-lucide="external-link"></i></a>'
            + '<button type="button" class="icon-btn" data-pv="close" title="' + core.escapeHtml(t('js.preview.close')) + '" aria-label="' + core.escapeHtml(t('js.preview.close')) + '"><i data-lucide="x"></i></button>'
            + '</div></header>'
            + '<div class="preview__body" data-pv="body"></div>';
        const $ = (k) => shell.querySelector('[data-pv="' + k + '"]');

        function render() {
            const link = items[index];
            const type = link.dataset.previewType;
            const url = link.href;
            const body = $('body');
            zoomed = false;
            $('title').textContent = link.dataset.previewTitle || link.textContent.trim();
            $('count').textContent = (index + 1) + ' / ' + items.length;
            $('prev').hidden = $('next').hidden = $('count').hidden = items.length < 2;
            $('open').href = url;
            const dl = link.dataset.previewDownload;
            $('download').hidden = !dl;
            if (dl) $('download').href = dl;
            $('print').hidden = !(type === 'pdf' || type === 'html' || type === 'image');
            body.className = 'preview__body preview__body--' + type + ' is-loading';
            body.innerHTML = '<span class="preview__spinner" aria-hidden="true"></span>';
            if (type === 'image') {
                const img = new Image();
                img.alt = $('title').textContent;
                img.onload = () => body.classList.remove('is-loading');
                img.onerror = () => fail(body, dl || url);
                img.addEventListener('click', () => { zoomed = !zoomed; body.classList.toggle('is-zoomed', zoomed); });
                img.src = url;
                body.appendChild(img);
            } else if (type === 'pdf' || type === 'html') {
                const frame = document.createElement('iframe');
                frame.title = $('title').textContent;
                frame.onload = () => body.classList.remove('is-loading');
                frame.src = url;
                body.appendChild(frame);
            } else {
                fail(body, dl || url, true);
            }
        }
        function fail(body, href, unsupported) {
            body.classList.remove('is-loading');
            body.innerHTML = '<div class="empty-state"><i data-lucide="' + (unsupported ? 'file' : 'file-x') + '"></i><p>'
                + core.escapeHtml(t(unsupported ? 'js.preview.unsupported' : 'js.preview.error')) + '</p>'
                + '<a class="btn btn--primary" href="' + core.escapeHtml(href) + '"><i data-lucide="download"></i> ' + core.escapeHtml(t('js.preview.download')) + '</a></div>';
            refreshIcons();
        }
        function move(step) {
            if (items.length < 2) return;
            index = (index + step + items.length) % items.length;
            render();
        }
        function printCurrent() {
            const frame = shell.querySelector('iframe');
            if (frame) {
                try { frame.contentWindow.focus(); frame.contentWindow.print(); return; } catch (e) { /* visionneuse PDF non scriptable */ }
            }
            window.open(items[index].href, '_blank', 'noopener');
        }
        const onKey = (ev) => {
            if (ev.key === 'ArrowLeft') move(-1);
            if (ev.key === 'ArrowRight') move(1);
        };

        Swal.fire({
            html: shell,
            showConfirmButton: false,
            width: 'min(1360px, 96vw)',
            padding: 0,
            customClass: { popup: 'swal-preview' },
            didOpen: () => {
                $('prev').addEventListener('click', () => move(-1));
                $('next').addEventListener('click', () => move(1));
                $('print').addEventListener('click', printCurrent);
                $('close').addEventListener('click', () => Swal.close());
                document.addEventListener('keydown', onKey);
                render();
                refreshIcons();
            },
            willClose: () => document.removeEventListener('keydown', onKey),
        });
    }

    $(document).on('click', 'a[data-preview]', function (ev) {
        // Ctrl / Cmd / clic du milieu : comportement natif (nouvel onglet)
        if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button === 1) return;
        ev.preventDefault();
        ev.stopPropagation(); // ne pas déclencher le clic de ligne de tableau
        const items = previewGroup(this);
        openPreview(items, Math.max(0, items.findIndex((a) => a.href === this.href)));
    });

    /* ---------- Décision d'inspection : n° / date si AV ou ARA, motif si ARA ---------- */
    function applyDecision(form) {
        const checked = form.querySelector('[data-decision-cards] input:checked');
        if (!checked) return;
        const decided = checked.value !== 'pending';
        const fields = form.querySelector('[data-decision-fields]');
        const reason = form.querySelector('[data-ara-reason]');
        if (fields) { fields.hidden = !decided; fields.querySelectorAll('input').forEach((i) => { i.required = decided; }); }
        if (reason) { reason.hidden = checked.value !== 'ara'; reason.querySelector('textarea').required = checked.value === 'ara'; }
    }
    $(document).on('change', '[data-decision-cards] input', function () { applyDecision(this.form); });
    document.addEventListener('elm:init', (ev) => { if (ev.target.querySelector && ev.target.querySelector('[data-decision-cards]')) applyDecision(ev.target); });
    document.querySelectorAll('form').forEach((f) => { if (f.querySelector('[data-decision-cards]')) applyDecision(f); });
    // Quantité conforme : ne peut dépasser l'inspecté
    $(document).on('input', '[data-conform]', function () {
        const ins = this.closest('tr').querySelector('[data-inspected]');
        if (ins && ins.value !== '') this.max = ins.value;
    });

    /* ---------- Menu latéral : sections repliables mémorisées, entrée active visible ---------- */
    document.querySelectorAll('details[data-nav-section]').forEach((section) => {
        const key = 'elm.nav.' + section.dataset.navSection;
        // La section qui contient la page courante reste ouverte ; les autres retrouvent leur dernier état.
        if (!section.hasAttribute('data-nav-force-open')) {
            try {
                const saved = localStorage.getItem(key);
                if (saved !== null) section.open = saved === '1';
            } catch (e) { /* stockage indisponible */ }
        }
        section.addEventListener('toggle', () => {
            try { localStorage.setItem(key, section.open ? '1' : '0'); } catch (e) { /* ignore */ }
        });
    });
    const activeLink = document.querySelector('.sidebar .nav__link[aria-current="page"]');
    if (activeLink) activeLink.scrollIntoView({ block: 'nearest' });

    /* ---------- Menus déroulants ---------- */
    $(document).on('click', '[data-menu-toggle]', function (ev) {
        ev.stopPropagation();
        const menu = this.parentElement.querySelector('[data-menu]');
        const open = menu.hidden;
        closeMenus();
        menu.hidden = !open;
        this.setAttribute('aria-expanded', String(open));
    });
    function closeMenus() {
        document.querySelectorAll('[data-menu]').forEach((m) => { m.hidden = true; });
        document.querySelectorAll('[data-menu-toggle]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
    }
    $(document).on('click', closeMenus);
    $(document).on('keydown', (ev) => { if (ev.key === 'Escape') closeMenus(); });

    /* ---------- Petits comportements ---------- */
    $(document).on('change', '[data-autosubmit]', function () { this.form.submit(); });
    $(document).on('input', 'textarea[data-autogrow]', function () {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
    });
    $(document).on('click', '[data-toggle-password]', function () {
        const input = document.querySelector(this.dataset.togglePassword);
        input.type = input.type === 'password' ? 'text' : 'password';
    });

    /* ==========================================================================
     * Soumission des formulaires : confirmation sobre (SweetAlert2) puis envoi AJAX.
     * Le serveur répond { ok, message, errors, redirect } (cf. public/index.php).
     * Exclusions : form[data-no-ajax] (connexion, langue, site de travail, déconnexion).
     * ========================================================================== */
    const swalBase = {
        reverseButtons: true,
        confirmButtonColor: accent,
        cancelButtonText: t('js.cancel'),
        customClass: { popup: 'swal-sober' },
        buttonsStyling: true,
    };

    /** Envoi AJAX d'un formulaire (fichiers inclus). Rejette avec { message, errors } en cas d'échec. */
    function sendForm(form) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: form.action,
                method: 'POST',
                data: new FormData(form),
                processData: false,
                contentType: false,
                dataType: 'json',
            }).done((res) => (res && res.ok ? resolve(res) : reject(res || {})))
                .fail((xhr) => {
                    const res = xhr.responseJSON || {};
                    if (xhr.status === 403 && res.error === 419) res.message = t('js.session_expired');
                    else if (xhr.status === 401) res.message = t('js.session_expired');
                    else if (!res.message) res.message = xhr.status ? t('js.error') : t('js.network');
                    reject(res);
                });
        });
    }

    /** Erreurs par champ : aria-invalid + message sous le contrôle. Renvoie les messages non placés. */
    function showFieldErrors(form, errors) {
        form.querySelectorAll('.error[data-ajax-error]').forEach((el) => el.remove());
        form.querySelectorAll('[aria-invalid="true"]').forEach((el) => el.removeAttribute('aria-invalid'));
        const unplaced = [];
        Object.keys(errors || {}).forEach((name) => {
            const control = form.querySelector('[name="' + name + '"], [name^="' + name + '["]');
            if (!control || control.type === 'hidden') { unplaced.push(errors[name]); return; }
            control.setAttribute('aria-invalid', 'true');
            const p = document.createElement('p');
            p.className = 'error';
            p.dataset.ajaxError = '1';
            p.textContent = errors[name];
            (control.closest('.field') || control.parentElement).appendChild(p);
        });
        const first = form.querySelector('[aria-invalid="true"]');
        if (first) {
            // Assistant par étapes : revenir à l'étape qui contient le champ en erreur
            const panel = first.closest('[data-step]');
            if (panel) form.dispatchEvent(new CustomEvent('wizard:goto', { detail: { step: Array.from(form.querySelectorAll('[data-step]')).indexOf(panel) } }));
            first.focus();
        }
        return unplaced;
    }

    function errorHtml(res, unplaced) {
        const list = (unplaced || []).filter((m) => m && m !== res.message);
        return core.escapeHtml(res.message || t('js.error'))
            + (list.length ? '<ul class="swal-errors">' + list.map((m) => '<li>' + core.escapeHtml(m) + '</li>').join('') + '</ul>' : '');
    }

    /** Confirmation discrète (toast sobre), sans bloquer l'écran. */
    function toast(message, icon) {
        Swal.fire({
            toast: true, position: 'top-end', icon: icon || 'success', title: message || t('js.saved'),
            showConfirmButton: false, timer: 2400, timerProgressBar: true, customClass: { popup: 'swal-sober swal-toast' },
        });
    }

    /**
     * Pages avec [data-live-region] : après succès, le contenu est rechargé en arrière-plan et remplacé sur place
     * (pas de rechargement, défilement et contexte conservés). Renvoie une promesse, ou null si non applicable.
     */
    function liveRefresh(res) {
        const region = document.querySelector('[data-live-region]');
        if (!region || !window.fetch || !window.DOMParser) return null;
        const target = new URL(res.redirect || window.location.href, window.location.href);
        if (target.pathname !== window.location.pathname) return null;
        // Même page : on garde le contexte courant (onglet, simulateur) sauf si le serveur en impose un
        const url = target.search ? target.href : window.location.href;
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
            .then((r) => (r.ok ? r.text() : Promise.reject(r)))
            .then((html) => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const fresh = doc.querySelector('[data-live-region="' + region.dataset.liveRegion + '"]');
                if (!fresh) return Promise.reject(new Error('region'));
                region.innerHTML = fresh.innerHTML;
                if (url !== window.location.href) { try { history.replaceState(null, '', url); } catch (e) { /* sans importance */ } }
                refreshIcons();
                initStaticTables(region);
                region.dispatchEvent(new CustomEvent('elm:live', { bubbles: true }));
            });
    }

    /** Succès : sur une page « live », mise à jour sur place + toast ; sinon bref message puis navigation. */
    function onSuccess(res) {
        if (res.next && res.next.href) { offerNext(res); return; }
        const live = liveRefresh(res);
        if (live) {
            live.then(() => toast(res.message), () => window.location.reload());
            return;
        }
        const go = () => {
            if (!res.redirect) { window.location.reload(); return; }
            const target = new URL(res.redirect, window.location.href);
            if (target.pathname + target.search === window.location.pathname + window.location.search) {
                if (target.hash) window.location.hash = target.hash;
                window.location.reload();
            } else {
                window.location.assign(target.href);
            }
        };
        Swal.fire({
            icon: 'success', title: res.message || t('js.saved'), showConfirmButton: false,
            timer: 1100, timerProgressBar: true, customClass: { popup: 'swal-sober' },
        }).then(go);
    }

    /** Succès avec étape suivante du dossier : on la propose directement (sinon retour à la page d'origine). */
    function offerNext(res) {
        const back = () => {
            const target = new URL(res.redirect || window.location.href, window.location.href);
            if (target.pathname + target.search === window.location.pathname + window.location.search) {
                if (target.hash) window.location.hash = target.hash;
                window.location.reload();
            } else {
                window.location.assign(target.href);
            }
        };
        Swal.fire({
            icon: 'success', title: res.message || t('js.saved'),
            text: t('js.next_step') + ' ' + res.next.label,
            showCancelButton: true, confirmButtonText: res.next.label, cancelButtonText: t('js.later'),
            reverseButtons: true, customClass: { popup: 'swal-sober' },
        }).then((r) => {
            if (r.isConfirmed) window.location.assign(new URL(res.next.href, window.location.href).href);
            else back();
        });
    }

    /** Texte de confirmation : data-confirm si présent, sinon message générique. */
    function confirmOptions(form) {
        const custom = form.dataset.confirm;
        const danger = form.dataset.confirmVariant === 'danger';
        return Object.assign({}, swalBase, {
            icon: custom ? 'warning' : 'question',
            title: custom || t('js.confirm_title'),
            text: custom ? '' : t('js.confirm_text'),
            showCancelButton: true,
            confirmButtonText: t('js.confirm'),
            confirmButtonColor: danger ? getComputedStyle(document.documentElement).getPropertyValue('--st-breakdown').trim() : accent,
            focusCancel: danger,
            showLoaderOnConfirm: true,
            allowOutsideClick: () => !Swal.isLoading(),
            preConfirm: () => sendForm(form).catch((res) => {
                const unplaced = showFieldErrors(form, res.errors);
                Swal.showValidationMessage(errorHtml(res, unplaced));
                return false;
            }),
        });
    }

    // Formulaires de page
    $(document).on('submit', 'form[method="post"]:not([data-no-ajax])', function (ev) {
        const form = this;
        if (form.closest('.swal2-container')) return; // géré par la modale de saisie
        ev.preventDefault();
        if (!form.reportValidity()) return;
        Swal.fire(confirmOptions(form)).then((res) => {
            if (res.isConfirmed && res.value) onSuccess(res.value);
        });
    });

    // Modale de saisie à partir d'un <template> : « Enregistrer » vaut confirmation.
    $(document).on('click', '[data-open]', function () {
        const tpl = document.querySelector(this.dataset.open);
        if (!tpl) return;
        const holder = document.createElement('div');
        holder.appendChild(tpl.content.cloneNode(true));
        const form = holder.querySelector('form');
        const danger = form.dataset.confirmVariant === 'danger';
        Swal.fire(Object.assign({}, swalBase, {
            title: this.dataset.title || '',
            html: holder,
            showCancelButton: true,
            confirmButtonText: danger ? t('js.confirm') : t('js.save'),
            confirmButtonColor: danger ? getComputedStyle(document.documentElement).getPropertyValue('--st-breakdown').trim() : accent,
            focusConfirm: false,
            showLoaderOnConfirm: true,
            allowOutsideClick: () => !Swal.isLoading(),
            customClass: { popup: 'swal-form swal-sober' },
            didOpen: (popup) => {
                refreshIcons();
                form.dispatchEvent(new Event('elm:init', { bubbles: true }));
                const first = popup.querySelector('input:not([type=hidden]), select, textarea');
                if (first) first.focus();
                // Entrée dans un champ = Enregistrer (et non soumission native)
                form.addEventListener('submit', (e) => { e.preventDefault(); Swal.clickConfirm(); });
            },
            preConfirm: () => {
                if (!form.reportValidity()) return false;
                return sendForm(form).catch((res) => {
                    const unplaced = showFieldErrors(form, res.errors);
                    Swal.showValidationMessage(errorHtml(res, unplaced));
                    return false;
                });
            },
        })).then((res) => {
            if (res.isConfirmed && res.value) onSuccess(res.value);
        });
    });

    /* ---------- Colonnes sélectionnables (préférence mémorisée par tableau, dans ce navigateur) ---------- */
    const COLS_KEY = (name) => 'elm.cols.' + name;
    function readColumnPrefs(name) {
        try { return JSON.parse(localStorage.getItem(COLS_KEY(name)) || 'null'); } catch (e) { return null; }
    }
    function columnVisible(name, col, i) {
        if (col.locked) return true;
        const prefs = readColumnPrefs(name);
        return prefs && Object.prototype.hasOwnProperty.call(prefs, col.data) ? !!prefs[col.data] : col.visible !== false;
    }
    function buildColumnPicker(name) {
        const holder = document.querySelector('[data-columns-for="' + name + '"]');
        const table = tables[name];
        if (!holder || !table) return;
        const menu = holder.querySelector('[data-menu]');
        const count = holder.querySelector('[data-columns-count]');
        const render = () => {
            const visible = table.cols.filter((c, i) => table.dt.column(i).visible()).length;
            count.textContent = visible + '/' + table.cols.length;
        };
        menu.innerHTML = '<p class="menu__head">' + core.escapeHtml(t('js.columns_hint')) + '</p>';
        table.cols.forEach((c, i) => {
            const label = document.createElement('label');
            label.className = 'menu__check' + (c.locked ? ' is-locked' : '');
            label.innerHTML = '<input type="checkbox"' + (table.dt.column(i).visible() ? ' checked' : '') + (c.locked ? ' disabled' : '') + '> <span>' + core.escapeHtml(c.title) + '</span>';
            label.querySelector('input').addEventListener('change', (ev) => {
                table.dt.column(i).visible(ev.target.checked);
                const prefs = readColumnPrefs(name) || {};
                prefs[c.data] = ev.target.checked;
                try { localStorage.setItem(COLS_KEY(name), JSON.stringify(prefs)); } catch (e) { /* stockage indisponible */ }
                render();
            });
            menu.appendChild(label);
        });
        const reset = document.createElement('button');
        reset.type = 'button';
        reset.className = 'menu__item menu__item--reset';
        reset.textContent = t('js.columns_reset');
        reset.addEventListener('click', () => {
            try { localStorage.removeItem(COLS_KEY(name)); } catch (e) { /* ignore */ }
            table.cols.forEach((c, i) => table.dt.column(i).visible(c.locked || c.visible !== false));
            menu.querySelectorAll('input').forEach((inp, i) => { inp.checked = table.dt.column(i).visible(); });
            render();
        });
        menu.appendChild(reset);
        // Les clics dans le menu ne le referment pas
        menu.addEventListener('click', (ev) => ev.stopPropagation());
        render();
    }

    /* ---------- Tableaux serveur + filtres ---------- */
    const definitions = window.ELM.tables.definitions({ t, locale: config.locale, currency: config.currency });

    function filterValues(name) {
        const out = {};
        document.querySelectorAll('[data-filters-for="' + name + '"] [data-filter]').forEach((el) => {
            if (el.value !== '') out[el.dataset.filter] = el.value;
        });
        return out;
    }

    function updateFilterMeta(name) {
        const form = document.querySelector('[data-filters-for="' + name + '"]');
        if (!form) return;
        const visible = Array.from(form.querySelectorAll('select[data-filter], input[data-filter]:not([type=hidden])'));
        const n = visible.filter((s) => s.value !== '').length;
        const adv = visible.filter((s) => s.hasAttribute('data-advanced') && s.value !== '').length;
        const advChip = form.querySelector('[data-advanced-count]');
        if (advChip) { advChip.hidden = adv === 0; advChip.textContent = String(adv); }
        const count = form.querySelector('[data-filters-count]');
        const reset = form.querySelector('[data-filters-reset]');
        if (count) { count.hidden = n === 0; count.textContent = t('js.filters_active', { n }); }
        if (reset) reset.hidden = n === 0;
    }

    document.querySelectorAll('table[data-table]').forEach((el) => {
        const name = el.dataset.table;
        const cols = definitions[name];
        if (!cols) return;
        const rowLink = el.dataset.rowLink;
        const dt = new DataTable(el, {
            serverSide: true,
            processing: true,
            ajax: {
                url: el.dataset.source,
                data: (d) => Object.assign(d, filterValues(name)),
            },
            columns: cols.map((c, i) => ({
                data: c.data,
                title: c.title,
                orderable: !!c.orderable,
                visible: columnVisible(name, c, i),
                className: c.className || '',
                render: (data, type, row) => (type === 'display' ? c.html(row) : c.text(row)),
            })),
            order: [],
            pageLength: Number(el.dataset.pageLength) || 15,
            lengthMenu: [15, 25, 50, 100],
            searchDelay: 350,
            autoWidth: false,
            language: {
                search: '', searchPlaceholder: t('dt.search_placeholder'), lengthMenu: t('dt.length'),
                info: t('dt.info'), infoEmpty: t('dt.info_empty'), infoFiltered: t('dt.info_filtered'),
                emptyTable: t('dt.empty'), zeroRecords: t('dt.zero'), processing: t('dt.processing'),
            },
            createdRow: (tr, row) => {
                if (rowLink) {
                    tr.classList.add('row-link');
                    tr.tabIndex = 0;
                    tr.dataset.href = core.fillTemplate(rowLink, row);
                }
            },
        });
        el.classList.add('table--compact');
        tables[name] = { dt, cols, source: el.dataset.source };
        buildColumnPicker(name);
        updateFilterMeta(name);
    });

    $(document).on('click', 'tr.row-link', function (ev) {
        if (ev.target.closest('a, button, input, select, label')) return;
        window.location.href = this.dataset.href;
    });
    $(document).on('keydown', 'tr.row-link', function (ev) {
        if (ev.key === 'Enter') window.location.href = this.dataset.href;
    });

    $(document).on('change', '[data-filters-for] [data-filter]', function () {
        const name = this.closest('[data-filters-for]').dataset.filtersFor;
        if (tables[name]) tables[name].dt.ajax.reload();
        updateFilterMeta(name);
    });
    $(document).on('submit', 'form[data-filters-for]', (ev) => ev.preventDefault());
    $(document).on('click', '[data-toggle-advanced]', function () {
        const panel = document.getElementById(this.getAttribute('aria-controls'));
        panel.hidden = !panel.hidden;
        this.setAttribute('aria-expanded', String(!panel.hidden));
        if (!panel.hidden) { const first = panel.querySelector('select, input'); if (first) first.focus(); }
    });
    $(document).on('click', '[data-filters-reset]', function () {
        const form = this.closest('[data-filters-for]');
        form.querySelectorAll('select[data-filter], input[data-filter]:not([type=hidden])').forEach((s) => { s.value = ''; });
        const name = form.dataset.filtersFor;
        if (tables[name]) tables[name].dt.ajax.reload();
        updateFilterMeta(name);
    });

    /* KPI qui positionne un filtre et fait défiler vers le tableau */
    $(document).on('click', '[data-set-filter]', function () {
        const field = this.dataset.setFilter;
        const select = document.querySelector('[data-filters-for] [data-filter="' + field + '"]');
        if (!select) return;
        select.value = this.dataset.value || '';
        // Onglets de statut : un seul actif
        const tabs = this.closest('[data-status-tabs]');
        if (tabs) tabs.querySelectorAll('[data-set-filter]').forEach((b) => b.setAttribute('aria-pressed', String(b === this)));
        select.dispatchEvent(new Event('change', { bubbles: true }));
        if (!this.hasAttribute('data-no-scroll')) select.closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    /* Filtres reflétés dans l'adresse (table[data-sync-url]) : lien partageable, retour arrière, rechargement */
    function syncFilterUrl(name) {
        const el = document.querySelector('table[data-table="' + name + '"][data-sync-url]');
        if (!el) return;
        const qs = new URLSearchParams(filterValues(name)).toString();
        try { history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '')); } catch (e) { /* sans importance */ }
    }
    $(document).on('change', '[data-filters-for] [data-filter]', function () { syncFilterUrl(this.closest('[data-filters-for]').dataset.filtersFor); });
    $(document).on('click', '[data-filters-reset]', function () { syncFilterUrl(this.closest('[data-filters-for]').dataset.filtersFor); });

    /* ---------- Exports (tous les enregistrements filtrés et triés) ---------- */
    $(document).on('click', '[data-export][data-export-json]', async function (ev) {
        ev.stopImmediatePropagation();
        const btn = this;
        const src = JSON.parse(document.getElementById(btn.dataset.exportJson).textContent);
        const cols = definitions[btn.dataset.exportTable];
        if (!cols) return;
        btn.disabled = true;
        try {
            const now = new Date();
            await window.ELM.export.excel({
                vendor, accent, columns: cols, rows: src.rows,
                title: src.meta.title || btn.dataset.exportTitle,
                context: src.meta.context,
                subtitle: t('js.export_generated', { date: now.toLocaleString(config.locale === 'en' ? 'en-GB' : 'fr-FR') }) + ' · ' + t('js.export_rows', { n: src.rows.length }),
                summary: src.meta.summary, summaryTitle: t('js.export_summary'), totalLabel: t('js.export_total'),
            });
        } catch (e) {
            Swal.fire({ icon: 'error', text: t('js.error') });
        } finally {
            btn.disabled = false;
        }
    });

    $(document).on('click', '[data-export]:not([data-export-json])', async function () {
        const table = tables[this.dataset.exportTable];
        if (!table) return;
        const btn = this;
        btn.disabled = true;
        try {
            const params = Object.assign({}, table.dt.ajax.params(), { start: 0, length: -1, draw: 0 });
            // L'export reprend les colonnes affichées (choix de l'utilisateur), dans le même ordre.
            const exportCols = table.cols.filter((c, i) => table.dt.column(i).visible());
            const res = await $.getJSON(table.source, params);
            const now = new Date();
            let subtitle = t('js.export_generated', { date: now.toLocaleString(config.locale === 'en' ? 'en-GB' : 'fr-FR') })
                + ' · ' + t('js.export_rows', { n: res.data.length });
            if (res.truncated) subtitle += ' · ' + t('js.export_truncated', { n: res.data.length });
            const opts = {
                vendor, accent, subtitle,
                title: btn.dataset.exportTitle || this.dataset.exportTable,
                columns: exportCols, rows: res.data,
                formatNumber: (v, d) => core.formatNumber(v, config.locale, d),
                formatDate: (v) => core.formatDate(v, true),
            };
            await (btn.dataset.export === 'pdf' ? window.ELM.export.pdf(opts) : window.ELM.export.excel(opts));
        } catch (e) {
            Swal.fire({ icon: 'error', text: t('js.error') });
        } finally {
            btn.disabled = false;
        }
    });

    /* ---------- Sélecteur d'article avec recherche ---------- */
    document.querySelectorAll('[data-equipment-picker]').forEach((root) => {
        const input = root.querySelector('[data-picker-input]');
        const results = root.querySelector('[data-picker-results]');
        const valueEl = root.querySelector('[data-picker-value]');
        const labelInput = root.querySelector('[data-picker-label-input]');
        const selected = root.querySelector('[data-picker-selected]');
        const search = root.querySelector('[data-picker-search]');
        let timer = null;
        let xhr = null;

        function choose(item) {
            valueEl.value = item.id;
            const label = item.sku + ' — ' + item.name;
            labelInput.value = label;
            root.querySelector('[data-picker-label]').textContent = label;
            selected.hidden = false;
            search.hidden = true;
            results.innerHTML = '';
        }

        input.addEventListener('input', () => {
            clearTimeout(timer);
            const q = input.value.trim();
            if (q.length < 2) { results.innerHTML = ''; return; }
            timer = setTimeout(() => {
                if (xhr) xhr.abort();
                results.innerHTML = '<li class="picker__empty">' + core.escapeHtml(t('js.searching')) + '</li>';
                xhr = $.getJSON(root.dataset.pickerSource, { q }).done((items) => {
                    if (!items.length) {
                        results.innerHTML = '<li class="picker__empty">' + core.escapeHtml(t('js.no_result')) + '</li>';
                        return;
                    }
                    results.innerHTML = '';
                    items.forEach((item) => {
                        const li = document.createElement('li');
                        li.innerHTML = '<button type="button" class="picker__option" role="option"><strong>' + core.escapeHtml(item.sku)
                            + '</strong> <span class="muted">' + core.escapeHtml(item.name) + ' · ' + core.escapeHtml(item.unit) + '</span></button>';
                        li.firstChild.addEventListener('click', () => choose(item));
                        results.appendChild(li);
                    });
                });
            }, 250);
        });
        root.querySelector('[data-picker-change]').addEventListener('click', () => {
            selected.hidden = true;
            search.hidden = false;
            valueEl.value = '';
            input.value = '';
            input.focus();
        });
    });

    /* ---------- Devise → taux par défaut + aperçu du coût en devise de base ---------- */
    document.querySelectorAll('[data-rate-target]').forEach((select) => {
        const rate = document.querySelector(select.dataset.rateTarget);
        const cost = document.getElementById('unit_cost');
        const preview = document.querySelector('[data-cost-preview]');
        const update = () => {
            if (!preview) return;
            const v = Number(cost.value) * Number(rate.value);
            preview.textContent = cost.value && rate.value && select.value !== preview.dataset.base
                ? t('js.cost_preview', { amount: core.formatNumber(v, config.locale, 4), base: preview.dataset.base }) : '';
        };
        select.addEventListener('change', () => {
            const r = select.selectedOptions[0].dataset.rate;
            if (r) rate.value = r;
            update();
        });
        rate.addEventListener('input', update);
        cost.addEventListener('input', update);
        update();
    });

    /* ---------- Tableau de bord : graphique des flux ---------- */
    const flows = document.getElementById('flows-data');
    const canvas = document.getElementById('flows-chart');
    if (flows && canvas && window.Chart) {
        const data = JSON.parse(flows.textContent);
        const css = getComputedStyle(document.documentElement);
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.map((d) => core.formatDate(d.day).slice(0, 5)),
                datasets: [
                    { label: t('js.chart_in'), data: data.map((d) => d.in), backgroundColor: css.getPropertyValue('--st-available').trim() },
                    { label: t('js.chart_out'), data: data.map((d) => d.out), backgroundColor: css.getPropertyValue('--st-waiting-part').trim() },
                ],
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12 } },
                    tooltip: { callbacks: { label: (c) => c.dataset.label + ' : ' + core.formatNumber(c.raw, config.locale, 2) + ' ' + config.currency } },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                    y: { beginAtZero: true, ticks: { callback: (v) => core.formatNumber(v, config.locale, 0) } },
                },
            },
        });
    }

    /* ---------- Cockpit : détail d'un indicateur en modale (tableau compact + export Excel / PDF) ---------- */
    const drillFmt = (v, type) => {
        if (v === null || v === undefined || v === '') return '';
        if (type === 'money') return core.formatNumber(v, config.locale, 2);
        if (type === 'number') return core.formatNumber(v, config.locale, 0);
        if (type === 'pct') return core.formatNumber(v, config.locale, 1) + ' %';
        if (type === 'date') return core.formatDate(v);
        if (type === 'datetime') return core.formatDate(v, true);
        return String(v);
    };
    // Valeur de tri : dates ISO et nombres bruts
    const drillOrder = (v, type) => (v === null || v === undefined ? '' : (['money', 'number', 'pct'].includes(type) ? Number(v) : String(v)));
    async function openDrill(key, param, title) {
        Swal.fire(Object.assign({}, swalBase, { title, html: '<p class="muted">' + core.escapeHtml(t('js.loading')) + '</p>', showConfirmButton: false, showCloseButton: true,
            width: 'min(1360px, 96vw)', customClass: { popup: 'swal-sober swal-drill' } }));
        Swal.showLoading();
        let data;
        try {
            data = await $.getJSON(config.base.replace(/\/$/, '') + '/cockpit/drill', { key, param: param || '' });
        } catch (e) {
            Swal.fire({ icon: 'error', text: t('js.error'), customClass: { popup: 'swal-sober' } });
            return;
        }
        const cols = data.columns;
        const esc = core.escapeHtml;
        const holder = document.createElement('div');
        holder.className = 'drill';
        const num = (c) => ['money', 'number', 'pct'].includes(c.type);
        holder.innerHTML = '<div class="drill__bar"><span class="muted">' + esc(t('js.export_rows', { n: data.rows.length })) + '</span>'
            + '<span class="drill__actions"><button type="button" class="btn btn--sm" data-drill-export="xlsx">' + '<i data-lucide="file-spreadsheet"></i> Excel</button>'
            + '<button type="button" class="btn btn--sm" data-drill-export="pdf"><i data-lucide="file-text"></i> PDF</button></span></div>'
            + '<div class="table-wrap"><table class="table table--compact" data-dt-length="10"><thead><tr>'
            + cols.map((c) => '<th' + (num(c) ? ' class="num"' : '') + '>' + esc(c.title) + '</th>').join('') + '</tr></thead><tbody>'
            + (data.rows.length ? data.rows.map((r) => '<tr>' + cols.map((c, i) => {
                const txt = esc(drillFmt(r[c.key], c.type));
                const cell = i === 0 && r.href ? '<a href="' + esc(r.href) + '"><strong>' + txt + '</strong></a>' : txt;
                return '<td' + (num(c) ? ' class="num"' : '') + ' data-order="' + esc(drillOrder(r[c.key], c.type)) + '">' + cell + '</td>';
            }).join('') + '</tr>').join('') : '') + '</tbody></table></div>'
            + (data.rows.length ? '' : '<p class="empty">' + esc(t('dt.empty')) + '</p>');
        Swal.fire(Object.assign({}, swalBase, {
            title: data.title || title, html: holder, showConfirmButton: false, showCloseButton: true,
            width: 'min(1360px, 96vw)', customClass: { popup: 'swal-sober swal-drill' },
            didOpen: () => { initStaticTables(holder); refreshIcons(); },
        }));
        holder.querySelectorAll('[data-drill-export]').forEach((btn) => btn.addEventListener('click', async () => {
            const pdf = btn.dataset.drillExport === 'pdf';
            const now = new Date();
            // Excel : valeurs typées (dates réelles, nombres) ; PDF : textes déjà formatés
            const columns = cols.map((c) => ({
                title: c.title,
                type: pdf ? (num(c) ? (c.type === 'money' ? 'money' : 'number') : 'text') : ({ date: 'day', datetime: 'date', pct: 'number' }[c.type] || c.type),
                text: (r) => (pdf && !num(c) ? drillFmt(r[c.key], c.type) : (num(c) ? (r[c.key] === null || r[c.key] === '' ? '' : Number(r[c.key])) : r[c.key])),
                total: c.type === 'money',
            }));
            btn.disabled = true;
            try {
                const opts = {
                    vendor, accent, columns, rows: data.rows, title: data.title || title,
                    subtitle: t('js.export_generated', { date: now.toLocaleString(config.locale === 'en' ? 'en-GB' : 'fr-FR') }) + ' · ' + t('js.export_rows', { n: data.rows.length }),
                    totalLabel: t('js.export_total'),
                    formatNumber: (v, d) => core.formatNumber(v, config.locale, d),
                    formatDate: (v) => core.formatDate(v, true),
                };
                await (pdf ? window.ELM.export.pdf(opts) : window.ELM.export.excel(opts));
            } catch (e) {
                Swal.showValidationMessage(t('js.error'));
            } finally {
                btn.disabled = false;
            }
        }));
    }
    // Pastille du menu (dans un lien) : Entrée / Espace ouvrent le détail sans suivre le lien
    $(document).on('keydown', '.nav__badge[data-drill]', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); ev.stopPropagation(); $(this).trigger('click'); }
    });
    $(document).on('click', '[data-drill]', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        openDrill(this.dataset.drill, this.dataset.drillParam || '', this.dataset.drillTitle || this.textContent.trim());
    });

    /* ---------- Cockpit : graphiques compacts (clic = détail) ---------- */
    const ckData = document.getElementById('cockpit-charts');
    if (ckData && window.Chart) {
        const cd = JSON.parse(ckData.textContent);
        const css = getComputedStyle(document.documentElement);
        const col = (name, fb) => css.getPropertyValue(name).trim() || fb;
        const c1 = col('--accent', '#1F4E79');
        const c2 = col('--st-available', '#1E7A4C');
        const cMuted = col('--border', '#DDE1E6');
        const money = (v) => core.formatNumber(v, config.locale, 0) + ' ' + cd.currency;
        const short = (v) => (Math.abs(v) >= 1e6 ? core.formatNumber(v / 1e6, config.locale, 1) + ' M' : Math.abs(v) >= 1e3 ? core.formatNumber(v / 1e3, config.locale, 0) + ' k' : core.formatNumber(v, config.locale, 0));
        Chart.defaults.font.family = css.getPropertyValue('--font').trim() || 'system-ui';
        Chart.defaults.font.size = 11;
        Chart.defaults.color = col('--text-muted', '#5B6573');
        const base = {
            responsive: true, maintainAspectRatio: false, animation: { duration: 400 },
            plugins: { legend: { display: false } },
            onHover: (e, el) => { e.native.target.style.cursor = el.length ? 'pointer' : 'default'; },
        };
        const empty = (canvas) => { canvas.parentElement.innerHTML = '<p class="empty">' + core.escapeHtml(cd.i18n.empty) + '</p>'; };

        const cDel = document.getElementById('ck-chart-deliveries');
        if (cDel) {
            new Chart(cDel, {
                data: {
                    labels: cd.months.map((m) => m.label),
                    datasets: [
                        { type: 'bar', label: cd.i18n.value, data: cd.months.map((m) => m.value), backgroundColor: c1, borderRadius: 4, maxBarThickness: 28, yAxisID: 'y' },
                        { type: 'line', label: cd.i18n.count, data: cd.months.map((m) => m.count), borderColor: c2, backgroundColor: c2, tension: .3, pointRadius: 3, yAxisID: 'y1' },
                    ],
                },
                options: Object.assign({}, base, {
                    plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } },
                        tooltip: { callbacks: { label: (c) => c.dataset.label + ' : ' + (c.dataset.yAxisID === 'y' ? money(c.raw) : c.raw) } } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { color: cMuted }, ticks: { callback: short, maxTicksLimit: 4 } },
                        y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { precision: 0, maxTicksLimit: 4 } },
                    },
                    onClick: (e, el) => { if (el.length) { const m = cd.months[el[0].index]; openDrill('deliveries_month', m.key, cd.i18n.month_title + m.label); } },
                }),
            });
        }
        const cSup = document.getElementById('ck-chart-suppliers');
        if (cSup) {
            if (!cd.suppliers.length) empty(cSup); else new Chart(cSup, {
                type: 'bar',
                data: { labels: cd.suppliers.map((s) => (s.label.length > 22 ? s.label.slice(0, 21) + '…' : s.label)),
                    datasets: [{ data: cd.suppliers.map((s) => s.value), backgroundColor: '#6B21A8', borderRadius: 4, maxBarThickness: 18 }] },
                options: Object.assign({}, base, {
                    indexAxis: 'y',
                    plugins: { legend: { display: false }, tooltip: { callbacks: { title: (c) => cd.suppliers[c[0].dataIndex].label, label: (c) => money(c.raw) } } },
                    scales: { x: { beginAtZero: true, grid: { color: cMuted }, ticks: { callback: short, maxTicksLimit: 4 } }, y: { grid: { display: false } } },
                    onClick: (e, el) => { if (el.length) { const s = cd.suppliers[el[0].index]; openDrill('pos_supplier', String(s.id), cd.i18n.supplier_title + ' — ' + s.label); } },
                }),
            });
        }
        const cLic = document.getElementById('ck-chart-licences');
        if (cLic) {
            if (!cd.licences.length) empty(cLic); else new Chart(cLic, {
                type: 'bar',
                data: { labels: cd.licences.map((l) => l.label),
                    datasets: [
                        { label: cd.i18n.used, data: cd.licences.map((l) => l.used), backgroundColor: c2, borderRadius: 4, maxBarThickness: 18, stack: 's' },
                        { label: cd.i18n.remaining, data: cd.licences.map((l) => l.remaining), backgroundColor: cMuted, borderRadius: 4, maxBarThickness: 18, stack: 's' },
                    ] },
                options: Object.assign({}, base, {
                    indexAxis: 'y',
                    plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } }, tooltip: { callbacks: { label: (c) => c.dataset.label + ' : ' + money(c.raw) } } },
                    scales: { x: { stacked: true, beginAtZero: true, grid: { color: cMuted }, ticks: { callback: short, maxTicksLimit: 4 } }, y: { stacked: true, grid: { display: false } } },
                    onClick: (e, el) => { if (el.length) openDrill('licences_active', '', cd.i18n.licences_title); },
                }),
            });
        }
    }

    /* ---------- KPI qui positionne plusieurs filtres à la fois ---------- */
    $(document).on('click', '[data-set-filters]', function () {
        const values = JSON.parse(this.dataset.setFilters);
        let form = null;
        Object.keys(values).forEach((field) => {
            const select = document.querySelector('[data-filters-for] [data-filter="' + field + '"]');
            if (select) { select.value = values[field]; form = select.closest('[data-filters-for]'); }
        });
        if (!form) return;
        const name = form.dataset.filtersFor;
        if (tables[name]) tables[name].dt.ajax.reload();
        updateFilterMeta(name);
        form.closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    /* ---------- Lignes éditables : ajout / suppression / totaux ---------- */
    function refreshLines(table) {
        let total = 0;
        table.querySelectorAll('tbody tr').forEach((tr) => {
            const q = tr.querySelector('[data-line-qty]');
            const p = tr.querySelector('[data-line-price]');
            const cell = tr.querySelector('[data-line-total]');
            if (!q || !p || !cell) return;
            const v = Number(q.value) * Number(p.value);
            cell.textContent = q.value && p.value ? core.formatNumber(v, config.locale, 2) : '—';
            if (Number.isFinite(v)) total += v;
        });
        const foot = table.querySelector('[data-lines-total]');
        if (foot) foot.textContent = total ? core.formatNumber(total, config.locale, 2) : '—';
    }
    document.querySelectorAll('[data-lines]').forEach(refreshLines);
    $(document).on('input', '[data-lines] [data-line-qty], [data-lines] [data-line-price]', function () {
        refreshLines(this.closest('[data-lines]'));
    });
    let lineSeq = 1000;
    $(document).on('click', '[data-line-add]', function () {
        const section = this.closest('fieldset, form');
        const tpl = section.querySelector('[data-line-template]');
        const body = section.querySelector('[data-lines-body]');
        const html = tpl.innerHTML.split('__i__').join(String(lineSeq++));
        body.insertAdjacentHTML('beforeend', html);
        refreshIcons();
        body.lastElementChild.querySelector('select, input').focus();
    });
    $(document).on('click', '[data-line-remove]', function () {
        const body = this.closest('tbody');
        if (body.children.length > 1) this.closest('tr').remove();
        refreshLines(body.closest('[data-lines]'));
    });

    /* ---------- Références automatiques modifiables ----------
     * Une saisie manuelle marque le champ « modifié » : il n'est plus remplacé automatiquement
     * (changement de famille / site), seul le bouton ↻ le régénère. */
    function fetchReference(input) {
        const form = input.form;
        const params = { type: input.dataset.refType };
        const dep = input.dataset.refDepends;
        if (dep && form && form.elements[dep]) params[dep] = form.elements[dep].value;
        return $.getJSON(input.dataset.refUrl, params).done((res) => {
            input.value = res.value;
            const hidden = input.closest('.field').querySelector('[data-ref-suggested]');
            if (hidden) hidden.value = res.value;
            delete input.dataset.refDirty;
            input.removeAttribute('aria-invalid');
        });
    }
    $(document).on('input', '[data-ref-auto]', function () { this.dataset.refDirty = '1'; });
    $(document).on('blur', '[data-ref-auto]', function () { this.value = this.value.toUpperCase().replace(/\s+/g, ''); });
    $(document).on('click', '[data-ref-regenerate]', function () {
        const input = this.closest('.ref-input').querySelector('[data-ref-auto]');
        this.disabled = true;
        fetchReference(input).always(() => { this.disabled = false; });
    });
    $(document).on('change', 'select', function () {
        if (!this.form || !this.name) return;
        this.form.querySelectorAll('[data-ref-auto][data-ref-depends="' + this.name + '"]').forEach((input) => {
            if (!input.dataset.refDirty) fetchReference(input);
        });
    });

    /* ==========================================================================
     * Assistant par étapes : form[data-wizard] > [data-step] (+ [data-wizard-tab], prev / next / submit).
     * Chaque étape est validée (HTML5 + règle propre via l'événement « wizard:validate ») avant de passer à la suivante.
     * Sans JavaScript, toutes les étapes restent visibles.
     * ========================================================================== */
    document.querySelectorAll('form[data-wizard]').forEach((form) => {
        const panels = Array.from(form.querySelectorAll('[data-step]'));
        const tabs = Array.from(form.querySelectorAll('[data-wizard-tab]'));
        const prev = form.querySelector('[data-wizard-prev]');
        const next = form.querySelector('[data-wizard-next]');
        const submit = form.querySelector('[data-wizard-submit]');
        let current = 0;
        let reached = 0;
        form.classList.add('is-wizard');

        function validStep(i) {
            const fields = panels[i].querySelectorAll('input, select, textarea');
            for (const f of fields) {
                if (!f.disabled && !f.closest('[hidden]') && !f.checkValidity()) { f.reportValidity(); return false; }
            }
            const ev = new CustomEvent('wizard:validate', { detail: { step: i, ok: true }, cancelable: true });
            form.dispatchEvent(ev);
            return ev.detail.ok;
        }
        function show(i) {
            current = Math.max(0, Math.min(i, panels.length - 1));
            reached = Math.max(reached, current);
            panels.forEach((p, k) => { p.hidden = k !== current; });
            tabs.forEach((t, k) => {
                t.classList.toggle('is-current', k === current);
                t.classList.toggle('is-done', k < current || (k <= reached && k !== current));
                t.disabled = k > reached;
                if (k === current) t.setAttribute('aria-current', 'step'); else t.removeAttribute('aria-current');
            });
            prev.hidden = current === 0;
            next.hidden = current === panels.length - 1;
            submit.hidden = current !== panels.length - 1;
            form.dispatchEvent(new CustomEvent('wizard:show', { detail: { step: current } }));
            const first = panels[current].querySelector('input:not([type=hidden]):not([readonly]), select, textarea');
            if (first && current > 0) first.focus({ preventScroll: true });
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        function goTo(i) {
            // On n'avance qu'en validant chaque étape franchie ; on recule librement.
            for (let k = current; k < i; k++) { if (!validStep(k)) { show(k); return; } }
            show(i);
        }
        next.addEventListener('click', () => goTo(current + 1));
        prev.addEventListener('click', () => show(current - 1));
        tabs.forEach((t, k) => t.addEventListener('click', () => goTo(k)));
        form.querySelectorAll('[data-wizard-goto]').forEach((b) => b.addEventListener('click', () => show(Number(b.dataset.wizardGoto))));
        form.addEventListener('wizard:goto', (ev) => show(ev.detail.step));
        // Entrée dans un champ : étape suivante (et non envoi prématuré)
        form.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter' && ev.target.tagName === 'INPUT' && current < panels.length - 1) { ev.preventDefault(); goTo(current + 1); }
        });
        // Erreur serveur sur un champ : revenir à l'étape qui le contient
        const invalid = form.querySelector('[aria-invalid="true"], .error:not([hidden])');
        const startAt = invalid ? panels.findIndex((p) => p.contains(invalid)) : 0;
        reached = Math.max(0, startAt);
        show(Math.max(0, startAt));
    });

    /* ---------- Expédition : quantités, progression en direct, réception, récapitulatif ---------- */
    document.querySelectorAll('form[data-wizard] [data-ship-line]').length && (function () {
        const form = document.querySelector('form[data-wizard]');
        const i18n = JSON.parse(document.getElementById('ship-i18n').textContent);
        const unit = form.dataset.unit;
        const lines = Array.from(form.querySelectorAll('[data-ship-line]'));
        const fmt = (v) => core.formatQty(v, config.locale);
        const qtyOf = (line) => { const i = line.querySelector('[data-ship-qty]'); return i ? Math.max(0, Number(i.value) || 0) : 0; };
        const receptionMode = () => form.querySelector('[data-reception-mode]:checked').value;
        const receptionNow = () => receptionMode() === 'delivered';

        function refresh() {
            let total = 0;
            let ordered = 0;
            let done = 0;
            lines.forEach((line) => {
                const q = Number(line.dataset.qty);
                const now = Math.min(qtyOf(line), Number(line.dataset.open));
                ordered += q;
                done += Number(line.dataset.done);
                total += now;
                line.querySelector('[data-seg-now]').style.width = (q > 0 ? now / q * 100 : 0) + '%';
                line.classList.toggle('is-filled', now > 0);
                // Ligne correspondante de l'étape réception
                const input = line.querySelector('[data-ship-qty]');
                if (!input) return;
                const lid = input.name.match(/\[(\d+)\]/)[1];
                const row = form.querySelector('[data-receive-row="' + lid + '"]');
                row.hidden = now <= 0;
                row.querySelector('[data-receive-shipped]').textContent = fmt(now) + ' ' + line.dataset.unit;
                const rec = row.querySelector('[data-receive-qty]');
                rec.max = String(now);
                const received = rec.value === '' ? now : Number(rec.value);
                const gap = now - received;
                const gapCell = row.querySelector('[data-receive-gap]');
                gapCell.textContent = gap > 0 ? '−' + fmt(gap) : '—';
                gapCell.classList.toggle('text-bad', gap > 0);
            });
            lines.forEach(syncAv);
            const live = document.querySelector('[data-live-qty]');
            live.textContent = total > 0 ? (unit ? fmt(total) + ' ' + unit : lines.filter((l) => qtyOf(l) > 0).length + ' ×') : '—';
            const pct = ordered > 0 ? Math.min(100, Math.floor((done + total) / ordered * 100)) : 0;
            document.querySelector('[data-live-bar]').style.width = pct + '%';
            document.querySelector('[data-live-after]').textContent = i18n.after.replace('__pct__', pct);
            const plate = form.elements.vehicle_plate.value.trim();
            document.querySelector('[data-live-vehicle]').textContent = [plate, form.elements.driver_name.value.trim()].filter(Boolean).join(' · ');
        }

        // Importation : répartition de la quantité chargée sur les AV (AV la plus ancienne d'abord, modifiable)
        function syncAv(line) {
            const box = line.querySelector('[data-av-alloc]');
            if (!box) return true;
            const qty = qtyOf(line);
            const inputs = Array.from(box.querySelectorAll('[data-av-input]'));
            if (box.dataset.manual !== '1') {
                let left = qty;
                inputs.forEach((i) => {
                    const take = Math.min(left, Number(i.max) || 0);
                    i.value = take > 0 ? String(Math.round(take * 1000) / 1000) : '';
                    left -= take;
                });
            }
            const sum = inputs.reduce((s, i) => s + (Number(i.value) || 0), 0);
            const over = inputs.some((i) => (Number(i.value) || 0) > (Number(i.max) || 0) + 1e-9);
            const diff = Math.round((qty - sum) * 1000) / 1000;
            const status = box.querySelector('[data-av-status]');
            const ok = !over && Math.abs(diff) < 0.0005;
            status.className = 'ship-av__status ' + (ok ? 'is-ok' : 'is-bad');
            status.textContent = qty <= 0 ? '' : (over ? i18n.av_invalid : (ok ? i18n.av_ok.replace('__q__', fmt(sum) + ' ' + line.dataset.unit)
                : (diff > 0 ? i18n.av_missing : i18n.av_over).replace('__q__', fmt(Math.abs(diff)) + ' ' + line.dataset.unit)));
            line.dataset.avSummary = inputs.filter((i) => Number(i.value) > 0).map((i) => i.dataset.avLabel + ' : ' + fmt(Number(i.value))).join(' + ');
            return qty <= 0 || ok;
        }
        form.addEventListener('input', (ev) => { if (ev.target.matches('[data-av-input]')) ev.target.closest('[data-av-alloc]').dataset.manual = '1'; }, true);
        form.querySelectorAll('[data-av-auto]').forEach((b) => b.addEventListener('click', () => {
            b.closest('[data-av-alloc]').dataset.manual = '0';
            refresh();
        }));

        function review() {
            const list = form.querySelector('[data-review-lines]');
            list.innerHTML = '';
            lines.forEach((line) => {
                const q = qtyOf(line);
                if (q <= 0) return;
                const li = document.createElement('li');
                li.innerHTML = '<strong>' + core.escapeHtml(line.dataset.sku) + '</strong> <span>' + core.escapeHtml(fmt(q) + ' ' + line.dataset.unit) + '</span>'
                    + (line.dataset.avSummary ? ' <span class="muted">· AV ' + core.escapeHtml(line.dataset.avSummary) + '</span>' : '');
                list.appendChild(li);
            });
            const dl = form.querySelector('[data-review-transport]');
            dl.innerHTML = '';
            Object.keys(i18n.labels).forEach((name) => {
                const el = form.elements[name];
                if (!el) return;
                const v = name === 'shipped_at' ? core.formatDate(el.value) : el.value.trim();
                dl.insertAdjacentHTML('beforeend', '<dt>' + core.escapeHtml(i18n.labels[name]) + '</dt><dd>' + core.escapeHtml(v || i18n.none) + '</dd>');
            });
            const rec = form.querySelector('[data-review-reception]');
            rec.textContent = receptionNow()
                ? i18n.delivered.replace('__date__', core.formatDate(form.elements.delivered_at.value)).replace('__by__', form.elements.received_by.value.trim())
                : (receptionMode() === 'planned' ? i18n.planned : i18n.transit);
        }

        function setReception() {
            const now = receptionNow();
            const block = form.querySelector('[data-reception-fields]');
            block.hidden = !now;
            form.elements.delivered_at.required = now;
            form.elements.received_by.required = now;
        }

        form.addEventListener('input', refresh);
        form.addEventListener('change', (ev) => { if (ev.target.matches('[data-reception-mode]')) setReception(); refresh(); });
        form.querySelectorAll('[data-ship-max]').forEach((b) => b.addEventListener('click', () => {
            const line = b.closest('[data-ship-line]');
            line.querySelector('[data-ship-qty]').value = line.dataset.open;
            refresh();
        }));
        form.querySelector('[data-ship-all]').addEventListener('click', () => {
            lines.forEach((line) => { const i = line.querySelector('[data-ship-qty]'); if (i) i.value = line.dataset.open; });
            refresh();
        });
        form.querySelectorAll('[data-uppercase]').forEach((i) => i.addEventListener('blur', () => { i.value = i.value.toUpperCase(); refresh(); }));
        // Étape 1 : au moins une quantité
        form.addEventListener('wizard:validate', (ev) => {
            if (ev.detail.step !== 0) return;
            const ok = lines.some((l) => qtyOf(l) > 0);
            form.querySelector('[data-ship-error]').hidden = ok;
            // Importation : chaque quantité doit être entièrement répartie sur des AV
            const avOk = lines.every((l) => syncAv(l));
            if (!avOk) { const bad = form.querySelector('.ship-av__status.is-bad'); if (bad) bad.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            ev.detail.ok = ok && avOk;
        });
        form.addEventListener('wizard:show', (ev) => { if (ev.detail.step === 3) review(); });
        setReception();
        refresh();
    })();

    /* ---------- Saisie de devis : totaux et marges en direct ---------- */
    function refreshQuote(table) {
        const form = table.closest('form');
        const currency = form.querySelector('[data-quote-currency]').value;
        const sameCurrency = currency === table.dataset.saleCurrency;
        let cost = 0;
        let sale = 0;
        table.querySelectorAll('tbody tr').forEach((tr) => {
            const input = tr.querySelector('[data-quote-price]');
            const qtyInput = tr.querySelector('[data-quote-qty]');
            // Quantité proposée par le fournisseur, plafonnée à la quantité commandée pour le calcul de marge
            const ordered = Number(tr.dataset.qty);
            const qty = qtyInput && qtyInput.value !== '' ? Math.min(Number(qtyInput.value), ordered) : ordered;
            tr.classList.toggle('is-short', qty + 1e-9 < ordered);
            const salePrice = Number(tr.dataset.sale);
            const totalCell = tr.querySelector('[data-quote-total]');
            const marginCell = tr.querySelector('[data-quote-margin]');
            marginCell.className = 'num';
            if (input.value === '') {
                totalCell.textContent = '—';
                marginCell.textContent = '—';
                return;
            }
            const price = Number(input.value);
            cost += qty * price;
            sale += qty * salePrice;
            totalCell.textContent = core.formatNumber(qty * price, config.locale, 2);
            if (sameCurrency && salePrice > 0) {
                const pct = (salePrice - price) / salePrice * 100;
                marginCell.textContent = core.formatNumber(pct, config.locale, 1) + ' %';
                marginCell.classList.add(pct < 0 ? 'text-bad' : 'text-good');
            } else {
                marginCell.textContent = '—';
            }
        });
        table.querySelector('[data-quote-cur]').textContent = currency;
        table.querySelector('[data-quote-sum]').textContent = cost ? core.formatNumber(cost, config.locale, 2) : '—';
        const sumMargin = table.querySelector('[data-quote-sum-margin]');
        sumMargin.className = 'num';
        if (cost && sameCurrency && sale > 0) {
            const pct = (sale - cost) / sale * 100;
            sumMargin.textContent = core.formatNumber(pct, config.locale, 1) + ' %';
            sumMargin.classList.add(pct < 0 ? 'text-bad' : 'text-good');
        } else {
            sumMargin.textContent = '—';
        }
    }
    $(document).on('input change', '.quote-form [data-quote-price], .quote-form [data-quote-qty], .quote-form [data-quote-currency]', function () {
        refreshQuote(this.closest('form').querySelector('[data-quote-table]'));
    });

    /* ---------- Champs pré-remplis depuis l'option choisie : data-fill-from="nomDuChamp:attribut" ---------- */
    $(document).on('change', 'select', function () {
        if (!this.form || !this.name) return;
        const opt = this.selectedOptions[0];
        this.form.querySelectorAll('[data-fill-from^="' + this.name + ':"]').forEach((target) => {
            const attr = target.dataset.fillFrom.split(':')[1];
            const value = opt ? opt.dataset[attr] : undefined;
            if (value !== undefined && value !== '') {
                target.value = value;
                target.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    });

    /* ---------- Liste dépendante : options filtrées par data-parent ---------- */
    document.querySelectorAll('select[data-depends-on]').forEach((child) => {
        const parent = document.querySelector(child.dataset.dependsOn);
        if (!parent) return;
        const apply = () => {
            child.querySelectorAll('option[data-parent]').forEach((o) => {
                const show = o.dataset.parent === parent.value;
                o.hidden = !show;
                o.disabled = !show;
                if (!show && o.selected) child.value = '';
            });
        };
        parent.addEventListener('change', apply);
        apply();
    });

    /* ---------- Lieu de livraison → adresse figée sur le PO fournisseur ---------- */
    const addresses = document.getElementById('site-addresses');
    document.querySelectorAll('[data-address-source]').forEach((select) => {
        const map = addresses ? JSON.parse(addresses.textContent) : {};
        select.addEventListener('change', () => {
            const site = map[select.value];
            if (!site) return;
            const form = select.form;
            const addr = form.querySelector('[data-address-target]');
            const contact = form.querySelector('[data-contact-target]');
            if (addr) addr.value = site.address;
            if (contact && site.contact) contact.value = site.contact;
        });
    });

    /* ---------- Section affichée selon une case à cocher (réception immédiate) ---------- */
    document.querySelectorAll('[data-toggle-section]').forEach((box) => {
        const section = document.querySelector(box.dataset.toggleSection);
        const apply = () => {
            section.hidden = !box.checked;
            section.querySelectorAll('input').forEach((i) => { i.required = box.checked; });
            box.form.querySelectorAll('[data-received-col]').forEach((c) => { c.hidden = !box.checked; });
        };
        box.addEventListener('change', apply);
        apply();
    });

    /* ---------- Import de licence depuis un document : téléversement (glisser-déposer + progression) ---------- */
    document.querySelectorAll('[data-pdf-upload]').forEach((form) => {
        const i18n = JSON.parse(document.getElementById('pdf-upload-i18n').textContent);
        const input = form.querySelector('[data-pdf-input]');
        const zone = form.querySelector('[data-dropzone]');
        const box = form.querySelector('[data-progress]');
        const bar = form.querySelector('[data-progress-bar]');
        const label = form.querySelector('[data-progress-label]');
        const err = form.querySelector('[data-upload-error]');
        const max = Number(form.dataset.max) || 0;
        const fail = (msg) => { err.textContent = msg; err.hidden = false; box.hidden = true; zone.classList.remove('is-busy'); input.value = ''; };
        const send = (file) => {
            err.hidden = true;
            if (!file) return;
            if (!/\.pdf$/i.test(file.name) && file.type !== 'application/pdf') return fail(i18n.not_pdf);
            if (max && file.size > max) return fail(i18n.too_big);
            const fd = new FormData(form);
            fd.set('file', file);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action);
            xhr.setRequestHeader('X-CSRF-Token', csrf);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            zone.classList.add('is-busy');
            box.hidden = false; bar.style.width = '0%';
            label.textContent = i18n.selected.replace(':name', file.name) + ' — ' + i18n.uploading;
            xhr.upload.onprogress = (e) => {
                if (!e.lengthComputable) return;
                const pct = Math.round((e.loaded / e.total) * 100);
                bar.style.width = pct + '%';
                if (pct >= 100) label.textContent = i18n.extracting;
            };
            xhr.onload = () => {
                let res = null;
                try { res = JSON.parse(xhr.responseText); } catch (e) { /* réponse non JSON */ }
                if (res && res.ok && res.redirect) { bar.style.width = '100%'; label.textContent = i18n.extracting; window.location.href = res.redirect; return; }
                fail((res && (res.message || Object.values(res.errors || {})[0])) || i18n.network);
            };
            xhr.onerror = () => fail(i18n.network);
            xhr.send(fd);
        };
        input.addEventListener('change', () => send(input.files[0]));
        ['dragenter', 'dragover'].forEach((n) => zone.addEventListener(n, (e) => { e.preventDefault(); zone.classList.add('is-over'); }));
        ['dragleave', 'drop'].forEach((n) => zone.addEventListener(n, (e) => { e.preventDefault(); zone.classList.remove('is-over'); }));
        zone.addEventListener('drop', (e) => send(e.dataTransfer.files[0]));
    });

    /* ---------- Import de licence : vérification (lignes, filtre fournisseur, sauvegarde auto, contrôles en direct) ---------- */
    document.querySelectorAll('[data-licence-review]').forEach((form) => {
        const body = form.querySelector('[data-xlines-body]');
        const tpl = form.querySelector('[data-xline-template]');
        const total = form.querySelector('[data-xlines-total]');
        const status = form.querySelector('[data-xsave]');
        const issues = form.querySelector('[data-xissues]');
        const po = form.querySelector('[data-po-select]');
        const sup = form.querySelector('[data-supplier-filter]');
        let seq = body.rows.length + 1000;
        const recalc = () => {
            let sum = 0, any = false;
            body.querySelectorAll('tr').forEach((tr) => {
                const a = tr.querySelector('[data-xa]');
                if (a && a.value !== '') { sum += Number(a.value); any = true; }
            });
            total.textContent = any ? sum.toLocaleString(document.documentElement.lang || 'fr', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—';
        };
        const filterPo = () => {
            if (!sup || !po) return;
            const s = sup.value;
            Array.from(po.options).forEach((o) => { if (o.value) o.hidden = s !== '' && o.dataset.supplier !== s; });
            if (po.selectedOptions[0] && po.selectedOptions[0].hidden) po.value = '';
            const visible = Array.from(po.options).filter((o) => o.value && !o.hidden);
            if (!po.value && visible.length === 1) po.value = visible[0].value;
        };
        // Pastille « Corrigé » dès qu'une valeur s'écarte de l'extraction (réversible)
        const markCorrected = (el) => {
            const wrap = el.closest('[data-xfield]');
            const chip = wrap && wrap.querySelector('[data-xstate]');
            if (!chip || el.dataset.extracted === undefined) return;
            if (!chip.dataset.orig) { chip.dataset.orig = chip.className + '|' + chip.textContent; }
            if (el.value !== el.dataset.extracted) { chip.className = 'xstate xstate--corrected'; chip.textContent = t('js.import.corrected'); }
            else { const [c, txt] = chip.dataset.orig.split('|'); chip.className = c; chip.textContent = txt; }
        };
        const renderIssues = (list) => {
            issues.textContent = '';
            if (!list.length) {
                const p = document.createElement('p'); p.className = 'xissue xissue--ok'; p.textContent = '✓ ' + t('js.import.no_issue'); issues.appendChild(p); return;
            }
            const h = document.createElement('p'); h.className = 'xissues__title'; h.textContent = t('js.import.issues_title'); issues.appendChild(h);
            const ul = document.createElement('ul');
            list.forEach((i) => {
                const li = document.createElement('li'); li.className = 'xissue xissue--' + i.level;
                li.textContent = (i.level === 'error' ? '✕ ' : '⚠ ') + i.message; ul.appendChild(li);
            });
            issues.appendChild(ul);
        };
        // Sauvegarde automatique : aucune correction perdue (rechargement, erreur, retour arrière)
        let timer = null, pending = null;
        const save = () => {
            if (pending) pending.abort();
            status.textContent = t('js.import.saving');
            pending = $.ajax({ url: form.dataset.saveUrl, method: 'POST', data: $(form).serialize(), dataType: 'json' })
                .done((res) => { status.textContent = t('js.import.saved'); if (res.issues) renderIssues(res.issues); })
                .fail((x) => { if (x.statusText !== 'abort') status.textContent = t('js.import.save_failed'); });
        };
        const schedule = () => { clearTimeout(timer); status.textContent = ''; timer = setTimeout(save, 800); };
        form.addEventListener('input', (e) => {
            const tr = e.target.closest('tr.xline');
            if (tr && (e.target.matches('[data-xq]') || e.target.matches('[data-xp]'))) {
                const q = tr.querySelector('[data-xq]').value, p = tr.querySelector('[data-xp]').value;
                if (q !== '' && p !== '') tr.querySelector('[data-xa]').value = (Number(q) * Number(p)).toFixed(2);
            }
            if (e.target.name === 'confirm_ack') return;
            markCorrected(e.target); recalc(); schedule();
        });
        form.addEventListener('change', (e) => {
            if (e.target.name === 'confirm_ack') return;
            if (e.target === sup) filterPo();
            markCorrected(e.target); schedule();
        });
        form.addEventListener('click', (e) => {
            const rm = e.target.closest('[data-xline-remove]');
            if (rm) { rm.closest('tr').remove(); recalc(); schedule(); return; }
            if (e.target.closest('[data-xline-add]')) {
                body.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, String(seq++)));
                refreshIcons();
                body.lastElementChild.querySelector('input').focus();
            }
        });
        filterPo(); recalc();
    });

    /* ---------- Paramétrage documentaire : simulateur de check-list (rafraîchi en direct) ---------- */
    let simPending = null;
    $(document).on('change', '[data-sim-form]', function () {
        const form = this;
        const target = document.querySelector('[data-sim-target]');
        {
            const qs = new URLSearchParams(new FormData(form)).toString();
            let pending = simPending;
            if (pending) pending.abort();
            target.setAttribute('aria-busy', 'true');
            pending = simPending = $.get(form.dataset.url + '?' + qs)
                .done((html) => {
                    target.innerHTML = html;
                    refreshIcons();
                    // L'URL garde le contexte : rechargement / partage du lien
                    try { history.replaceState(null, '', form.action + '?' + qs); } catch (e) { /* sans importance */ }
                })
                .always(() => target.removeAttribute('aria-busy'));
        }
    });

    /* ---------- Sélection en lot (cases à cocher) : barre d'action, compteur, « tout cocher » ---------- */
    function syncBulk(box) {
        const items = Array.from(box.querySelectorAll('[data-bulk-item]'));
        const checked = items.filter((i) => i.checked);
        const all = box.querySelector('[data-bulk-all]');
        const bar = box.querySelector('[data-bulk-bar]');
        if (all) { all.checked = checked.length > 0 && checked.length === items.length; all.indeterminate = checked.length > 0 && checked.length < items.length; }
        if (bar) {
            bar.hidden = checked.length === 0;
            bar.querySelector('[data-bulk-count]').textContent = t('js.bulk.selected').replace(':n', checked.length);
            bar.dataset.confirm = (bar.dataset.confirmTemplate || '').replace(':n', checked.length);
        }
        items.forEach((i) => i.closest('tr') && i.closest('tr').classList.toggle('is-selected', i.checked));
    }
    $(document).on('change', '[data-bulk] [data-bulk-item]', function () { syncBulk(this.closest('[data-bulk]')); });
    $(document).on('change', '[data-bulk] [data-bulk-all]', function () {
        const box = this.closest('[data-bulk]');
        box.querySelectorAll('[data-bulk-item]').forEach((i) => { i.checked = this.checked; });
        syncBulk(box);
    });
    $(document).on('click', '[data-bulk] [data-bulk-clear]', function () {
        const box = this.closest('[data-bulk]');
        box.querySelectorAll('[data-bulk-item], [data-bulk-all]').forEach((i) => { i.checked = false; });
        syncBulk(box);
    });

    /* ---------- Règle de check-list : exigence, phrase en clair, doublons / chevauchements en direct ---------- */
    function initRuleForm(form) {
        const rulesEl = document.getElementById('doc-rules');
        const i18nEl = document.getElementById('rule-form-i18n');
        if (!rulesEl || !i18nEl) return;
        const rules = JSON.parse(rulesEl.textContent);
        const tr = JSON.parse(i18nEl.textContent);
        const selfId = form.dataset.ruleId ? Number(form.dataset.ruleId) : null;
        const sel = (name) => form.querySelector('[name="' + name + '"]');
        const label = (s) => (s.value ? s.options[s.selectedIndex].text : '');
        const stageBox = form.querySelector('[data-rule-stage]');
        const summary = form.querySelector('[data-rule-summary]');
        const alertBox = form.querySelector('[data-rule-overlaps]');
        const fill = (tpl, v) => tpl.replace(':v', v);

        const update = () => {
            const req = (form.querySelector('[data-rule-req]:checked') || {}).value || 'required';
            const stage = sel('blocking_stage');
            stageBox.hidden = req !== 'blocking';
            stage.required = req === 'blocking';

            const doc = sel('document_type_id'), mode = sel('fulfillment_mode'), fam = sel('family_id'), trn = sel('transport_mode');
            const docName = doc.value ? label(doc).replace(/^[^—]*—\s*/, '') : tr.doc_none;
            summary.textContent = tr.sentence
                .replace(':mode', mode.value ? fill(tr.mode, label(mode)) : tr.mode_any)
                .replace(':family', fam.value ? fill(tr.family, label(fam)) : tr.family_any)
                .replace(':transport', trn.value ? fill(tr.transport, label(trn)) : tr.transport_any)
                .replace(':doc', docName)
                .replace(':req', req === 'blocking' ? fill(tr.req_blocking, label(stage)) : tr['req_' + req]);

            // Même logique que Checklist::overlaps (le serveur refuse de toute façon les doublons exacts)
            const active = !form.querySelector('[name="active"]') || form.querySelector('[name="active"]').checked;
            const cur = { mode: mode.value || null, family: fam.value || null, transport: trn.value || null };
            const hits = !doc.value || !active ? [] : rules.filter((r) => r.active && r.type === Number(doc.value) && r.id !== selfId).map((r) => {
                let same = true, compatible = true;
                ['mode', 'family', 'transport'].forEach((k) => {
                    if ((cur[k] || null) !== (r[k] || null)) { same = false; if (cur[k] && r[k]) compatible = false; }
                });
                return compatible ? { r, same } : null;
            }).filter(Boolean);
            alertBox.textContent = '';
            alertBox.hidden = !hits.length;
            alertBox.className = 'rule-overlaps' + (hits.some((h) => h.same) ? ' is-duplicate' : '');
            const dup = hits.find((h) => h.same);
            if (dup) {
                const p = document.createElement('p'); p.textContent = '✕ ' + tr.duplicate.replace(':rule', '#' + dup.r.id); alertBox.appendChild(p);
            } else if (hits.length) {
                const p = document.createElement('p'); p.textContent = '⚠ ' + tr.overlap; alertBox.appendChild(p);
                const ul = document.createElement('ul');
                hits.forEach((h) => { const li = document.createElement('li'); li.textContent = h.r.label; ul.appendChild(li); });
                alertBox.appendChild(ul);
                const hint = document.createElement('p'); hint.className = 'hint'; hint.textContent = tr.overlap_hint; alertBox.appendChild(hint);
            }
        };
        form.addEventListener('change', update);
        update();
    }
    document.addEventListener('elm:init', (ev) => { if (ev.target.matches && ev.target.matches('[data-rule-form]')) initRuleForm(ev.target); });

    /* ---------- Sections repliables (état par défaut serveur, choix de l'utilisateur mémorisé) ---------- */
    const COLLAPSE_KEY = (k) => 'elm.collapse.' + k;
    function setCollapsed(sec, collapsed, remember) {
        sec.classList.toggle('is-collapsed', collapsed);
        const btn = sec.querySelector('[data-collapse-toggle]');
        if (btn) btn.setAttribute('aria-expanded', String(!collapsed));
        if (remember && sec.dataset.key) { try { localStorage.setItem(COLLAPSE_KEY(sec.dataset.key), collapsed ? '0' : '1'); } catch (e) { /* stockage indisponible */ } }
    }
    function initCollapsibles(root) {
        root.querySelectorAll('[data-collapsible]').forEach((sec) => {
            let stored = null;
            try { stored = localStorage.getItem(COLLAPSE_KEY(sec.dataset.key)); } catch (e) { /* stockage indisponible */ }
            if (stored !== null) setCollapsed(sec, stored === '0', false);
        });
        // Arrivée sur une ancre (#finance, #closure…) : la section visée s'ouvre
        const target = window.location.hash && root.querySelector(window.location.hash.replace(/[^#\w-]/g, ''));
        if (target && target.matches('[data-collapsible]')) setCollapsed(target, false, false);
    }
    $(document).on('click', '[data-collapse-toggle]', function () {
        const sec = this.closest('[data-collapsible]');
        setCollapsed(sec, !sec.classList.contains('is-collapsed'), true);
    });
    // Sommaire et alertes : ouvrir la section visée avant d'y défiler
    $(document).on('click', '[data-nav-link], [data-nav-jump]', function () {
        const id = (this.getAttribute('href') || '').replace(/[^#\w-]/g, '');
        const sec = id.length > 1 && document.querySelector(id);
        if (sec && sec.matches('[data-collapsible]') && sec.classList.contains('is-collapsed')) setCollapsed(sec, false, false);
    });

    /* ---------- Sommaire collant : section visible mise en évidence ---------- */
    let navObserver = null;
    function initSectionNav(root) {
        const nav = root.querySelector('[data-section-nav]');
        if (navObserver) { navObserver.disconnect(); navObserver = null; }
        if (!nav || !('IntersectionObserver' in window)) return;
        const links = Array.from(nav.querySelectorAll('[data-nav-link]'));
        const byId = {};
        links.forEach((a) => { byId[a.getAttribute('href').slice(1)] = a; });
        const visible = new Set();
        navObserver = new IntersectionObserver((entries) => {
            entries.forEach((en) => { if (en.isIntersecting) visible.add(en.target.id); else visible.delete(en.target.id); });
            // La première section visible dans l'ordre du dossier
            const current = links.map((a) => a.getAttribute('href').slice(1)).find((id) => visible.has(id));
            links.forEach((a) => a.removeAttribute('aria-current'));
            if (current && byId[current]) {
                byId[current].setAttribute('aria-current', 'true');
                byId[current].scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }, { rootMargin: '-90px 0px -55% 0px' });
        Object.keys(byId).forEach((id) => { const sec = root.querySelector('#' + CSS.escape(id)); if (sec) navObserver.observe(sec); });
    }
    initCollapsibles(document);
    initSectionNav(document);
    document.addEventListener('elm:live', (ev) => { initCollapsibles(ev.target); initSectionNav(ev.target); });

    /* ---------- Onglets ([data-tabs]) : ARIA, clavier, onglet dans l'adresse (#finance), ancres internes ---------- */
    function activateTab(tabs, name, focus) {
        const btn = tabs.querySelector('[role="tab"][data-tab="' + name + '"]');
        if (!btn) return false;
        tabs.querySelectorAll('[role="tab"]').forEach((b) => {
            const on = b === btn;
            b.setAttribute('aria-selected', String(on));
            b.tabIndex = on ? 0 : -1;
        });
        tabs.querySelectorAll('[data-tab-panel]').forEach((p) => { p.hidden = p.dataset.tabPanel !== name; });
        if (focus) btn.focus();
        btn.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        return true;
    }
    /** Onglet correspondant à une ancre : nom d'onglet (#finance) ou élément situé dans un panneau (#sourcing, #closure…). */
    function tabForAnchor(tabs, hash) {
        const id = (hash || '').replace(/^#/, '');
        if (!id || !/^[\w-]+$/.test(id)) return null;
        if (tabs.querySelector('[role="tab"][data-tab="' + id + '"]')) return { name: id, el: null };
        const el = document.getElementById(id);
        const panel = el && el.closest('[data-tab-panel]');
        return panel && tabs.contains(panel) ? { name: panel.dataset.tabPanel, el } : null;
    }
    function rememberTab(name) { try { history.replaceState(null, '', window.location.pathname + window.location.search + '#' + name); } catch (e) { /* sans importance */ } }
    function initTabs(root) {
        const tabs = root.querySelector('[data-tabs]');
        if (!tabs) return;
        const hit = tabForAnchor(tabs, window.location.hash);
        if (hit) {
            activateTab(tabs, hit.name, false);
            if (hit.el) hit.el.scrollIntoView({ block: 'start' });
        }
    }
    $(document).on('click', '[data-tabs] [role="tab"]', function () {
        const tabs = this.closest('[data-tabs]');
        activateTab(tabs, this.dataset.tab, false);
        rememberTab(this.dataset.tab);
    });
    $(document).on('keydown', '[data-tabs] [role="tablist"]', function (ev) {
        const list = Array.from(this.querySelectorAll('[role="tab"]'));
        const i = list.indexOf(document.activeElement);
        if (i < 0) return;
        const next = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: list.length - 1 }[ev.key];
        if (next === undefined) return;
        ev.preventDefault();
        const btn = list[(next + list.length) % list.length];
        activateTab(this.closest('[data-tabs]'), btn.dataset.tab, true);
        rememberTab(btn.dataset.tab);
    });
    // Liens internes (alertes, bandeau d'action, « Voir les points de clôture »…) : ouvrir l'onglet qui contient la cible
    $(document).on('click', 'a[href^="#"]', function () {
        const tabs = document.querySelector('[data-tabs]');
        if (!tabs) return;
        const hit = tabForAnchor(tabs, this.getAttribute('href'));
        if (hit) activateTab(tabs, hit.name, false); // le défilement natif vers l'ancre suit, la cible étant désormais visible
    });
    window.addEventListener('hashchange', () => initTabs(document));
    initTabs(document);
    document.addEventListener('elm:live', (ev) => initTabs(ev.target));

    /* ---------- Banque → agence : seules les agences de la banque choisie sont proposées ---------- */
    function filterBranches(form) {
        const bank = form.querySelector('[data-bank-select]');
        const branch = form.querySelector('[data-branch-select]');
        if (!bank || !branch) return;
        Array.from(branch.options).forEach((o) => { if (o.value) o.hidden = !bank.value || o.dataset.bank !== bank.value; });
        if (branch.selectedOptions[0] && branch.selectedOptions[0].hidden) branch.value = '';
        branch.disabled = !bank.value;
    }
    $(document).on('change', '[data-bank-select]', function () { filterBranches(this.form || this.closest('form')); });
    document.querySelectorAll('[data-bank-select]').forEach((s) => filterBranches(s.form || s.closest('form')));

    /* ---------- Aperçu d'un fichier joint ([data-file-drop] + [data-file-preview]) : local avant envoi, ou pièce existante ---------- */
    function initFilePreview(form, i18n) {
        const preview = form.querySelector('[data-file-preview]');
        const input = form.querySelector('[data-file-preview-input]');
        const nameEl = form.querySelector('[data-file-name]');
        if (!preview) return;
        let objectUrl = null;
        const render = (src, mime) => {
            preview.querySelectorAll('iframe, img').forEach((n) => n.remove());
            preview.querySelector('[data-file-preview-empty]').hidden = !!src;
            if (!src) return;
            const el = document.createElement(mime && mime.startsWith('image/') ? 'img' : 'iframe');
            el.src = src;
            el.className = 'file-preview__frame';
            if (el.tagName === 'IFRAME') el.title = nameEl ? nameEl.textContent : '';
            else el.alt = nameEl ? nameEl.textContent : '';
            preview.appendChild(el);
        };
        const pick = (file) => {
            if (!file) return;
            const okType = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'].includes(file.type);
            if (!okType || file.size > 10 * 1024 * 1024) {
                window.Swal.fire({ icon: 'warning', title: okType ? i18n.too_big : i18n.bad_type, customClass: { popup: 'swal-sober' } });
                input.value = '';
                return;
            }
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = URL.createObjectURL(file);
            if (nameEl) nameEl.textContent = file.name;
            render(objectUrl, file.type);
        };
        if (input) {
            input.addEventListener('change', () => pick(input.files[0]));
            const drop = form.querySelector('[data-file-drop]');
            if (drop) {
                ['dragenter', 'dragover'].forEach((n) => drop.addEventListener(n, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
                ['dragleave', 'drop'].forEach((n) => drop.addEventListener(n, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
                drop.addEventListener('drop', (e) => {
                    const file = e.dataTransfer.files[0];
                    if (!file) return;
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    input.files = dt.files;
                    pick(file);
                });
            }
        }
        if (preview.dataset.existingSrc) render(preview.dataset.existingSrc, preview.dataset.existingMime);
    }

    /* ---------- Formulaire d'inspection : aperçu du rapport, « inspecter tout le reste » ---------- */
    document.querySelectorAll('[data-inspection-form]').forEach((form) => {
        const i18nEl = document.getElementById('file-preview-i18n');
        initFilePreview(form, i18nEl ? JSON.parse(i18nEl.textContent) : {});
        const fill = form.querySelector('[data-fill-rest]');
        if (fill) {
            fill.addEventListener('click', () => {
                form.querySelectorAll('[data-insp-line]').forEach((tr) => {
                    const rest = Number(tr.dataset.rest);
                    const input = tr.querySelector('[data-inspected]');
                    if (rest > 0 && input) { input.value = String(rest); input.dispatchEvent(new Event('input', { bubbles: true })); }
                });
            });
        }
    });
    /* ---------- Formulaire de licence : marchandises du PO, CIF calculé, validité, aperçu du fichier joint ---------- */
    document.querySelectorAll('[data-licence-form]').forEach((form) => {
        const i18n = JSON.parse(document.getElementById('licence-form-i18n').textContent);
        const fmt = (n) => Number(n).toLocaleString(document.documentElement.lang || 'fr', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const fill = (s, p) => Object.keys(p).reduce((acc, k) => acc.replace(':' + k, p[k]), s);
        const poSelect = form.querySelector('[data-po-goods-select]');
        const cif = form.querySelector('[data-cif]');
        const cifCur = form.querySelector('[data-cif-currency]');
        const curSelect = form.querySelector('[data-cost-currency]');
        const compare = form.querySelector('[data-po-amount-compare]');
        const fob = form.querySelector('#amount');

        // Marchandises du PO choisi (codes SH)
        const showGoods = () => {
            if (!poSelect) return;
            form.querySelectorAll('[data-po-goods]').forEach((g) => { g.hidden = g.dataset.poGoods !== poSelect.value; });
            const empty = form.querySelector('[data-goods-empty]');
            if (empty) empty.hidden = poSelect.value !== '';
        };
        // CIF = FOB + fret + assurance + autres frais ; comparaison FOB / montant du PO
        const recalc = () => {
            const values = Array.from(form.querySelectorAll('[data-cost]')).map((i) => (i.value === '' ? null : Number(i.value)));
            cif.textContent = values[0] === null ? '—' : fmt(values.reduce((s, v) => s + (v || 0), 0));
            if (cifCur && curSelect) cifCur.textContent = curSelect.value;
            const opt = poSelect && poSelect.selectedOptions[0];
            if (compare) {
                const poAmount = opt && opt.dataset.amount ? Number(opt.dataset.amount) : null;
                compare.hidden = poAmount === null || values[0] === null;
                if (!compare.hidden) {
                    const diff = values[0] - poAmount;
                    compare.textContent = Math.abs(diff) < 0.005
                        ? fill(i18n.compare_equal, { amount: fmt(poAmount) })
                        : fill(i18n.compare_diff, { amount: fmt(poAmount), diff: (diff > 0 ? '+' : '−') + fmt(Math.abs(diff)) });
                    compare.classList.toggle('text-warn', Math.abs(diff) >= 0.005);
                }
            }
        };
        // Durée de validité
        const validity = () => {
            const from = form.querySelector('[data-validity-from]').value;
            const to = form.querySelector('[data-validity-to]').value;
            const line = form.querySelector('[data-validity-line]');
            if (!from || !to) return;
            const days = Math.round((new Date(to) - new Date(from)) / 86400000);
            const left = Math.round((new Date(to) - new Date(new Date().toISOString().slice(0, 10))) / 86400000);
            line.classList.remove('text-bad', 'text-warn');
            if (days < 0) { line.textContent = i18n.invalid; line.classList.add('text-bad'); return; }
            line.textContent = left < 0 ? fill(i18n.expired, { n: -left }) : fill(i18n.days, { n: days, left });
            if (left < 0) line.classList.add('text-bad'); else if (left <= 30) line.classList.add('text-warn');
        };

        initFilePreview(form, i18n);

        form.addEventListener('input', (e) => { if (e.target.matches('[data-cost]')) recalc(); if (e.target.matches('[data-validity-from], [data-validity-to]')) validity(); });
        form.addEventListener('change', (e) => {
            if (e.target === poSelect) { showGoods(); setTimeout(recalc, 0); } // le FOB est repris du PO par data-fill-from
            if (e.target === curSelect) recalc();
            if (e.target.matches('[data-validity-from], [data-validity-to]')) validity();
        });
        showGoods(); recalc(); validity();
    });

    /* ---------- Inspection : n° de rapport et n° d'AV / ARA suivent la référence tant qu'ils n'ont pas été modifiés à la main ---------- */
    document.querySelectorAll('[data-inspection-ref]').forEach((ref) => {
        const followers = ref.form ? Array.from(ref.form.querySelectorAll('[name="decision_number"], [data-report-number]')) : [];
        let last = ref.value;
        ref.addEventListener('input', () => {
            followers.forEach((f) => { if (f.value === '' || f.value === last) f.value = ref.value; });
            last = ref.value;
        });
    });

    /* ---------- Tableaux : DataTables compactes partout (tri, recherche et pagination selon le volume) ----------
     * Exclus : tableaux serveur (data-table, déjà DataTables), data-no-dt, grilles de saisie (champs visibles :
     * une page masquée ne serait pas envoyée), sous-lignes fusionnées (colspan / rowspan dans le corps), tableaux vides.
     * Adaptatif : recherche dès 7 lignes, pagination au-delà de data-dt-length (10 par défaut), choix du nombre de lignes au-delà de 25. */
    function initStaticTables(root) {
        root.querySelectorAll('table.table:not([data-table]):not([data-no-dt]):not(.dataTable)').forEach((el) => {
            const body = el.tBodies[0];
            if (!body || !el.tHead || el.closest('template')) return;
            const rows = body.rows.length;
            if (rows === 0 || body.querySelector('td[colspan], td[rowspan]')) return;
            if (body.querySelector('input:not([type="hidden"]), select, textarea')) return;
            const length = Number(el.dataset.dtLength) || 10;
            const paging = rows > length;
            const searching = rows > 6;
            el.classList.add('table--compact');
            const heads = Array.from(el.tHead.rows[0].cells);
            const noSort = heads.map((c, i) => (c.dataset.orderable === 'false' || c.classList.contains('actions-col') || c.classList.contains('check-col') || c.textContent.trim() === '' ? i : null)).filter((i) => i !== null);
            new DataTable(el, {
                paging,
                pageLength: length,
                lengthMenu: [length, 25, 50, 100].filter((v, i, a) => a.indexOf(v) === i).sort((a, b) => a - b),
                searching,
                info: paging,
                order: el.dataset.dtOrder ? JSON.parse(el.dataset.dtOrder) : [],
                autoWidth: false,
                layout: {
                    topStart: searching ? 'search' : null,
                    topEnd: rows > 25 ? 'pageLength' : null,
                    bottomStart: paging ? 'info' : null,
                    bottomEnd: paging ? 'paging' : null,
                },
                columnDefs: noSort.length ? [{ targets: noSort, orderable: false, searchable: false }] : [],
                drawCallback: refreshIcons,
                language: {
                    search: '', searchPlaceholder: t('dt.search_placeholder'), lengthMenu: t('dt.length'),
                    info: t('dt.info'), infoEmpty: t('dt.info_empty'), infoFiltered: t('dt.info_filtered'),
                    emptyTable: t('dt.empty'), zeroRecords: t('dt.zero'),
                },
            });
        });
    }
    initStaticTables(document);

    refreshIcons();
})(window.jQuery);
