<?php

declare(strict_types=1);

namespace App\Controllers;

final class SupplierController extends PartyController
{
    protected function type(): string
    {
        return 'supplier';
    }
}
