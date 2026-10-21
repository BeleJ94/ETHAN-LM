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
        $perms = $this->role->permissions();
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2));
        return mb_strtoupper(implode('', $letters));
    }
}
