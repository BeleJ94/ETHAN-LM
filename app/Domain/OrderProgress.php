<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Règles d'avancement d'un PO client (processus achat local, livraison directe fournisseur → client).
 * Les quantités « livrées » sont les quantités RÉCEPTIONNÉES chez le client.
 */
final class OrderProgress
{
    public const CLOSED_STATES = ['closed', 'cancelled'];

    /** Statut du PO client déduit des faits ; les états terminaux ne bougent plus. */
    public static function orderStatus(string $current, bool $hasQuotes, bool $hasActivePo, bool $hasDeliveries, bool $allDelivered): string
    {
        if (in_array($current, self::CLOSED_STATES, true)) {
            return $current;
        }
        return match (true) {
            $allDelivered => 'delivered',
            $hasDeliveries => 'in_delivery',
            $hasActivePo => 'ordered',
            $hasQuotes => 'sourcing',
            default => 'received',
        };
    }

    /**
     * Statut d'un PO fournisseur selon les quantités CHARGÉES des livraisons arrivées.
     * Le fournisseur a rempli son PO quand il a chargé la quantité commandée ; une perte en route
     * (évaporation, manquant) est un écart côté client, tracé à la réception, pas un reliquat fournisseur.
     * @param list<array{quantity:float,shipped:float}> $lines
     */
    public static function poStatus(string $current, array $lines): string
    {
        if (in_array($current, ['draft', 'cancelled'], true)) {
            return $current;
        }
        $any = false;
        $all = $lines !== [];
        foreach ($lines as $l) {
            $any = $any || $l['shipped'] > 0;
            $all = $all && $l['shipped'] + 1e-9 >= $l['quantity'];
        }
        return $all ? 'delivered' : ($any ? 'partial' : 'sent');
    }

    public static function percent(float $done, float $total): int
    {
        if ($total <= 0) {
            return 0;
        }
        return (int) min(100, floor($done / $total * 100 + 1e-9));
    }

    /** Statut d'une facture selon les paiements reçus. */
    public static function invoiceStatus(string $current, float $amount, float $paid): string
    {
        if ($current === 'cancelled') {
            return $current;
        }
        return $paid + 0.005 >= $amount ? 'paid' : 'open';
    }

    /** Échéance = date facture + délai de paiement. */
    public static function dueDate(string $invoiceDate, int $termsDays): string
    {
        return (new \DateTimeImmutable($invoiceDate))->modify("+$termsDays days")->format('Y-m-d');
    }
}
