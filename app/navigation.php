<?php

declare(strict_types=1);

/*
 * Menu par sections, dans l'ordre du processus métier.
 * Entrée : chemin, icône, clé de libellé, permission requise, pastille facultative (App\Services\NavBadges),
 * 'match' facultatif = autres préfixes d'URL qui rendent l'entrée active.
 * Section : 'collapsible' = repliable (état mémorisé dans le navigateur), ouverte d'office si elle contient la page courante.
 */
return [
    ['id' => 'portal', 'section' => 'nav.section.portal', 'items' => [
        ['path' => '/portal', 'icon' => 'folder-check', 'label' => 'nav.portal', 'perm' => 'portal.access', 'badge' => 'portal'],
    ]],
    ['id' => 'steering', 'section' => 'nav.section.steering', 'items' => [
        ['path' => '/', 'icon' => 'layout-dashboard', 'label' => 'nav.dashboard', 'perm' => 'dashboard.view'],
    ]],
    ['id' => 'operations', 'section' => 'nav.section.operations', 'items' => [
        ['path' => '/orders', 'icon' => 'clipboard-list', 'label' => 'nav.orders', 'perm' => 'orders.view', 'badge' => 'orders'],
        ['path' => '/purchase-orders', 'icon' => 'shopping-cart', 'label' => 'nav.purchase_orders', 'perm' => 'orders.view', 'badge' => 'purchase_orders'],
        ['path' => '/licences', 'icon' => 'stamp', 'label' => 'nav.licences', 'perm' => 'orders.view', 'badge' => 'licences'],
        ['path' => '/deliveries', 'icon' => 'truck', 'label' => 'nav.deliveries', 'perm' => 'orders.view', 'badge' => 'deliveries'],
        ['path' => '/finance/customers', 'icon' => 'file-up', 'label' => 'nav.invoices_customers', 'perm' => 'finance.view', 'badge' => 'receivables'],
        ['path' => '/finance/suppliers', 'icon' => 'file-down', 'label' => 'nav.invoices_suppliers', 'perm' => 'finance.view', 'badge' => 'payables'],
    ]],
    ['id' => 'stock', 'section' => 'nav.section.stock', 'items' => [
        ['path' => '/items', 'icon' => 'package', 'label' => 'nav.items', 'perm' => 'stock.view'],
        ['path' => '/movements', 'icon' => 'arrow-left-right', 'label' => 'nav.movements', 'perm' => 'stock.view'],
        ['path' => '/warehouses', 'icon' => 'warehouse', 'label' => 'nav.warehouses', 'perm' => 'stock.view'],
    ]],
    ['id' => 'masterdata', 'section' => 'nav.section.masterdata', 'collapsible' => true, 'items' => [
        ['path' => '/customers', 'icon' => 'building-2', 'label' => 'nav.customers', 'perm' => 'orders.view'],
        ['path' => '/suppliers', 'icon' => 'factory', 'label' => 'nav.suppliers', 'perm' => 'orders.view'],
        ['path' => '/families', 'icon' => 'shapes', 'label' => 'nav.families', 'perm' => 'items.manage'],
        ['path' => '/banks', 'icon' => 'landmark', 'label' => 'nav.banks', 'perm' => 'orders.view'],
        ['path' => '/customs-agencies', 'icon' => 'briefcase', 'label' => 'nav.customs_agencies', 'perm' => 'orders.view'],
        ['path' => '/documents/settings', 'icon' => 'file-check-2', 'label' => 'nav.documents_settings', 'perm' => 'documents.configure'],
    ]],
    ['id' => 'admin', 'section' => 'nav.section.admin', 'collapsible' => true, 'items' => [
        ['path' => '/sites', 'icon' => 'map-pin', 'label' => 'nav.sites', 'perm' => 'sites.manage'],
        ['path' => '/users', 'icon' => 'users', 'label' => 'nav.users', 'perm' => 'users.manage'],
        ['path' => '/settings/company', 'icon' => 'building', 'label' => 'nav.company', 'perm' => 'settings.company'],
        ['path' => '/settings/import','icon' => 'file-spreadsheet', 'label' => 'nav.masterdata_import', 'perm' => 'settings.import'],
        ['path' => '/activity','icon' => 'history', 'label' => 'nav.activity', 'perm' => 'activity.view'],
    ]],
];
