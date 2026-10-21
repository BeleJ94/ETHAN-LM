<?php

declare(strict_types=1);

namespace App\Domain;

enum MovementType: string
{
    case In = 'in';
    case Out = 'out';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case AdjustIn = 'adjust_in';
    case AdjustOut = 'adjust_out';

    /** +1 entrée, -1 sortie */
    public function sign(): int
    {
        return match ($this) {
            self::In, self::TransferIn, self::AdjustIn => 1,
            self::Out, self::TransferOut, self::AdjustOut => -1,
        };
    }

    /** Seules les entrées valorisées (achat, réception d'import) recalculent le CMUP. */
    public function revaluesCost(): bool
    {
        return $this === self::In;
    }
}
