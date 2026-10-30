<?php

declare(strict_types=1);

namespace App\Security;

final class CurrentUser
{
    /** @param list<int> $siteIds Sites autorisés (vide + rôle global = tous) */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly Role $role,
        public readonly array $siteIds,
        public readonly ?int $forwarderId = null,
        public readonly bool $invoiceApprover = false,
    ) {
    }

    public function isForwarder(): bool
    {
        return $this->role === Role::Forwarder;
    }

    public function can(string $permission): bool
    {
        // Le portail n'a de sens que pour un compte rattaché à un transitaire (même l'administrateur n'y a pas accès).
        if ($permission === 'portal.access') {
            return $this->isForwarder() && $this->forwarderId !== null;
        }
        // Approbation des factures clients : habilitation donnée à l'utilisateur (Paramètres → Utilisateurs), pas au rôle.
        // L'administrateur approuve toujours, y compris ses propres factures.
        if ($permission === 'finance.approve') {
            return $this->role === Role::Admin || ($this->invoiceApprover && !$this->isForwarder());
        }
        $perms = $this->role->permissions();
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /** Contrôle à 2 niveaux des factures : l'émetteur ne s'approuve pas lui-même, sauf l'administrateur. */
    public function canApproveInvoiceOf(int $createdBy): bool
    {
        return $this->can('finance.approve') && ($this->role === Role::Admin || $this->id !== $createdBy);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2));
        return mb_strtoupper(implode('', $letters));
    }
}
