<?php

declare(strict_types=1);

namespace App\Controllers;

final class CustomerController extends PartyController
{
    protected function type(): string
    {
        return 'customer';
    }
}
