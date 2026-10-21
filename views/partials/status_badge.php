<?php
/**
 * Pastille d'état unifiée : la même couleur signifie la même chose partout.
 * @var string $key clé de traduction @var string $status
 */
$color = [
    // OK / terminé
    'delivered' => 'available', 'paid' => 'available', 'closed' => 'available', 'selected' => 'available',
    // en cours
    'sourcing' => 'maintenance', 'ordered' => 'maintenance', 'sent' => 'maintenance', 'in_delivery' => 'maintenance', 'in_transit' => 'maintenance',
    // attention / à traiter
    'received' => 'waiting_part', 'partial' => 'waiting_part', 'open' => 'waiting_part', 'pending' => 'waiting_part', 'draft' => 'waiting_part',
    // critique
    'overdue' => 'breakdown', 'late' => 'breakdown',
    // neutre
    'cancelled' => 'immobilized', 'rejected' => 'immobilized',
    // expéditions et documents
    'planned' => 'waiting_part', 'awaiting_licence' => 'waiting_part', 'inspection' => 'maintenance', 'blocked' => 'breakdown',
    'doc_pending' => 'waiting_part', 'doc_submitted' => 'maintenance', 'doc_validated' => 'available',
    'doc_rejected' => 'breakdown', 'doc_expired' => 'breakdown', 'doc_waived' => 'neutral', 'doc_not_applicable' => 'immobilized',
][$status] ?? 'neutral';
?><span class="badge badge--<?= e($color) ?>"><?= e(t($key)) ?></span>
