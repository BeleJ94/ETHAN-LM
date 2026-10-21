<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Checklist;
use PHPUnit\Framework\TestCase;

/** Documents du transitaire : dépôt subordonné à la désignation de l'agence en douane. */
final class ChecklistAgencyTest extends TestCase
{
    public function testForwarderDocumentsNeedAnAgency(): void
    {
        self::assertTrue(Checklist::needsAgency(['provider' => 'forwarder', 'agency_ok' => 0]));
        self::assertFalse(Checklist::needsAgency(['provider' => 'forwarder', 'agency_ok' => 1]));
    }

    public function testOtherProvidersAreNotConcerned(): void
    {
        foreach (['supplier', 'internal', 'customs', 'customer'] as $p) {
            self::assertFalse(Checklist::needsAgency(['provider' => $p, 'agency_ok' => 0]), $p);
        }
    }
}
