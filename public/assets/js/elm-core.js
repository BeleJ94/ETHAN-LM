/* ETHAN LM — utilitaires purs (navigateur : window.ELM.core ; Node : require). */
(function (root, factory) {
    const mod = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = mod;
    } else {
        root.ELM = root.ELM || {};
        root.ELM.core = mod;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' };

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).replace(/[&<>"'`]/g, (c) => HTML_ESCAPES[c]);
    }

    /** Traducteur lié à un catalogue { clé: texte } ; remplace :param. */
    function translator(catalog) {
        return function t(key, params) {
            let text = Object.prototype.hasOwnProperty.call(catalog, key) ? catalog[key] : key;
            if (params) {
                Object.keys(params).forEach((name) => {
                    text = text.split(':' + name).join(String(params[name]));
                });
            }
            return text;
        };
    }

    function formatNumber(value, locale, decimals) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        const n = Number(value);
        if (!Number.isFinite(n)) {
            return '—';
        }
        const d = decimals === undefined ? (Number.isInteger(n) ? 0 : 2) : decimals;
        return new Intl.NumberFormat(locale === 'en' ? 'en-US' : 'fr-FR', {
            minimumFractionDigits: d,
            maximumFractionDigits: d,
        }).format(n);
    }

    /** Quantité : entière sans décimale, sinon jusqu'à 3 décimales. */
    function formatQty(value, locale) {
        const n = Number(value);
        if (!Number.isFinite(n)) {
            return '—';
        }
        return formatNumber(n, locale, Number.isInteger(n) ? 0 : 3);
    }

    /** "2026-10-19 14:05:00" → "19/10/2026 14:05" (sans dépendance au fuseau du navigateur). */
    function formatDate(value, withTime) {
        if (!value) {
            return '—';
        }
        const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
        if (!m) {
            return '—';
        }
        const date = m[3] + '/' + m[2] + '/' + m[1];
        return withTime && m[4] ? date + ' ' + m[4] + ':' + m[5] : date;
    }

    /** Remplace les jetons {champ} d'une URL par les valeurs (encodées) de la ligne. */
    function fillTemplate(template, row) {
        return template.replace(/\{(\w+)\}/g, (_, key) => encodeURIComponent(row[key] === undefined ? '' : row[key]));
    }

    return { escapeHtml, translator, formatNumber, formatQty, formatDate, fillTemplate };
});
