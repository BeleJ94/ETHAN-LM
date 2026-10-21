<?php

declare(strict_types=1);

namespace App\Domain;

/** Règle métier violée ; le message est une clé de traduction affichable à l'utilisateur. */
final class DomainException extends \RuntimeException
{
    /** @param array<string,string|int|float> $params */
    public function __construct(string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}
