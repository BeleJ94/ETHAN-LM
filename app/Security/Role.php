<?php

declare(strict_types=1);

namespace App\Security;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Sales = 'sales';
    case Buyer = 'buyer';
    case Storekeeper = 'storekeeper';
    case Accountant = 'accountant';
    case Viewer = 'viewer';
    case Forwarder = 'forwarder';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => ['*'],
            self::Manager => [
                'dashboard.view', 'stock.view', 'stock.move', 'stock.adjust', 'items.manage', 'warehouses.manage', 'activity.view',
                'orders.view', 'orders.manage', 'orders.close', 'parties.manage', 'sourcing.manage', 'deliveries.manage',
                'finance.view', 'finance.manage',
                'documents.view', 'documents.upload', 'documents.validate', 'documents.waive', 'documents.configure',
                'imports.manage', 'banks.manage',
            ],
            self::Sales => [
                'dashboard.view', 'stock.view', 'orders.view', 'orders.manage', 'orders.close', 'parties.manage',
                'deliveries.manage', 'finance.view', 'documents.view', 'documents.upload',
            ],
            self::Buyer => [
                'dashboard.view', 'stock.view', 'items.manage', 'orders.view', 'parties.manage', 'sourcing.manage',
                'deliveries.manage', 'documents.view', 'documents.upload', 'documents.validate', 'imports.manage',
            ],
            self::Storekeeper => ['dashboard.view', 'stock.view', 'stock.move', 'orders.view', 'deliveries.manage', 'documents.view', 'documents.upload'],
            self::Accountant => ['dashboard.view', 'stock.view', 'orders.view', 'finance.view', 'finance.manage', 'banks.manage', 'documents.view', 'documents.upload', 'documents.validate'],
            self::Viewer => ['dashboard.view', 'stock.view', 'orders.view', 'documents.view'],
            // Accès externe limité : uniquement les expéditions confiées à son entreprise (portail)
            self::Forwarder => ['portal.access', 'documents.upload'],
        };
    }

    /** Un rôle global voit tous les sites sans affectation explicite. */
    public function isGlobal(): bool
    {
        return $this === self::Admin;
    }
}
