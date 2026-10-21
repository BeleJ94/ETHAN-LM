<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Prochaine action attendue sur un PO client (liste des commandes) : ce qu'il faut faire maintenant pour le faire avancer.
 * Déduite du statut (déjà calculé à partir des faits) et de quelques indicateurs complémentaires.
 */
final class OrderNextAction
{
    /** Tonalités d'affichage : neutral (suivi), warn (action à mener), danger (blocage), success (à finaliser). */
    private const MAP = [
        'received' => ['source', 'warn'],
        'sourcing' => ['issue_po', 'warn'],
        'awaiting_licence' => ['licence', 'warn'],
        'inspection' => ['inspection', 'neutral'],
        'blocked' => ['ara', 'danger'],
        'ordered' => ['ship', 'neutral'],
        'in_delivery' => ['follow', 'neutral'],
        'delivered' => ['close', 'success'],
    ];

    /**
     * @param int $draftPos PO fournisseurs encore en brouillon (non envoyés)
     * @return array{code:string,tone:string}|null null : dossier terminé (clôturé / annulé)
     */
    public static function for(string $status, int $draftPos = 0): ?array
    {
        if (!isset(self::MAP[$status])) {
            return null;
        }
        // PO fournisseur préparé mais pas encore envoyé : c'est l'action la plus urgente
        if ($draftPos > 0 && in_array($status, ['sourcing', 'ordered'], true)) {
            return ['code' => 'send_po', 'tone' => 'warn'];
        }
        [$code, $tone] = self::MAP[$status];
        return ['code' => $code, 'tone' => $tone];
    }

    /** Onglets de statut de la liste : groupe → statuts couverts (null = condition spéciale). */
    public const GROUPS = [
        'open' => null,
        'to_source' => ['received', 'sourcing'],
        'import' => ['awaiting_licence', 'inspection', 'blocked'],
        'in_delivery' => ['ordered', 'in_delivery'],
        'to_close' => ['delivered'],
        'late' => null,
        'closed' => ['closed'],
        '' => null,
    ];
}
