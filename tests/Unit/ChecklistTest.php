<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Checklist;
use PHPUnit\Framework\TestCase;

final class ChecklistTest extends TestCase
{
    private const REA = 1;
    private const CAR = 2;

    private function rule(int $type, ?string $mode = null, ?int $family = null, ?string $transport = null, int $required = 1, ?string $stage = null): array
    {
        return ['document_type_id' => $type, 'fulfillment_mode' => $mode, 'family_id' => $family,
            'transport_mode' => $transport, 'required' => $required, 'blocking_stage' => $stage];
    }

    public function testRulesCumulateAndEmptyCriteriaMatchEverything(): void
    {
        $rules = [
            $this->rule(10, 'import', null, null, 1, 'departure'),    // licence : toute importation
            $this->rule(20, null, self::REA, null, 1, 'departure'),   // FDS : tous réactifs
            $this->rule(30, null, self::CAR, 'road', 1, 'departure'), // bon de chargement : carburant par route
            $this->rule(40, null, null, null, 1, 'invoicing'),        // BL signé : tout
        ];
        $importAcid = ['mode' => 'import', 'family_ids' => [self::REA], 'transport_modes' => ['road']];
        self::assertSame([10, 20, 40], array_keys(Checklist::expected($rules, $importAcid)));

        $localFuelRail = ['mode' => 'local_purchase', 'family_ids' => [self::CAR], 'transport_modes' => ['rail']];
        self::assertSame([40], array_keys(Checklist::expected($rules, $localFuelRail)));

        // Expédition mixte : union des familles
        $mixed = ['mode' => 'local_purchase', 'family_ids' => [self::REA, self::CAR], 'transport_modes' => ['road']];
        self::assertSame([20, 30, 40], array_keys(Checklist::expected($rules, $mixed)));
    }

    public function testSameDocumentFromSeveralRulesMergesToStrictest(): void
    {
        $rules = [
            $this->rule(50, null, null, null, 0, 'closure'),
            $this->rule(50, 'import', null, null, 1, 'reception'),
        ];
        $exp = Checklist::expected($rules, ['mode' => 'import', 'family_ids' => [], 'transport_modes' => ['road']]);
        self::assertSame(['required' => true, 'blocking_stage' => 'reception'], $exp[50]);
    }

    public function testOnlyValidatedOrWaivedDocumentsUnblock(): void
    {
        $today = '2026-10-19';
        $doc = fn (string $status, string $stage, ?string $exp = null) => ['status' => $status, 'required' => 1, 'blocking_stage' => $stage, 'expires_at' => $exp];
        $docs = [
            $doc('submitted', 'departure'),            // déposé mais pas validé : bloque (contrôle à 2 niveaux)
            $doc('validated', 'departure'),
            $doc('waived', 'departure'),               // dérogation
            $doc('validated', 'departure', '2026-10-01'), // validé mais expiré : bloque
            $doc('pending', 'reception'),
            $doc('pending', 'invoicing'),
        ];
        self::assertCount(2, Checklist::blockers($docs, 'departure', $today));
        self::assertCount(3, Checklist::blockers($docs, 'reception', $today));
        self::assertCount(4, Checklist::blockers($docs, 'closure', $today));
        self::assertSame(['done' => 2, 'total' => 6], Checklist::progress($docs, $today));
    }

    public function testOptionalDocumentsNeverBlock(): void
    {
        $docs = [['status' => 'pending', 'required' => 0, 'blocking_stage' => 'departure', 'expires_at' => null]];
        self::assertSame([], Checklist::blockers($docs, 'closure', '2026-10-19'));
    }

    public function testExplainListsSourceRules(): void
    {
        $rules = [
            ['id' => 1, 'active' => 1] + $this->rule(10, 'import', null, null, 1, 'reception'),
            ['id' => 2, 'active' => 1] + $this->rule(10, null, self::REA, null, 1, 'departure'),
            ['id' => 3, 'active' => 1] + $this->rule(11, 'local_purchase'),
        ];
        $x = Checklist::explain($rules, ['mode' => 'import', 'family_ids' => [self::REA], 'transport_modes' => ['road']]);
        self::assertSame([10], array_keys($x));
        self::assertSame(['required' => true, 'blocking_stage' => 'departure', 'rule_ids' => [1, 2]], $x[10]);
    }

    public function testRequirementLevel(): void
    {
        self::assertSame('optional', Checklist::requirement($this->rule(1, null, null, null, 0)));
        self::assertSame('required', Checklist::requirement($this->rule(1)));
        self::assertSame('blocking', Checklist::requirement($this->rule(1, null, null, null, 1, 'departure')));
    }

    public function testOverlapsAndDuplicates(): void
    {
        $rules = [
            ['id' => 1, 'active' => 1] + $this->rule(10, 'import'),
            ['id' => 2, 'active' => 1] + $this->rule(10, 'import', self::REA),
            ['id' => 3, 'active' => 1] + $this->rule(10, 'local_purchase'),
            ['id' => 4, 'active' => 0] + $this->rule(10, 'import'),          // inactive : ignorée
            ['id' => 5, 'active' => 1] + $this->rule(99, 'import'),          // autre document
        ];
        // Nouvelle règle « import, tous produits » : doublon de 1, chevauche 2 (plus spécifique), pas 3 (autre mode)
        self::assertSame([['id' => 1, 'kind' => 'duplicate'], ['id' => 2, 'kind' => 'overlap']], Checklist::overlaps($this->rule(10, 'import'), $rules));
        // Modification de la règle 1 : elle ne se compare pas à elle-même
        self::assertSame([['id' => 2, 'kind' => 'overlap']], Checklist::overlaps($rules[0], $rules));
        // Familles différentes : jamais simultanées
        self::assertSame([['id' => 1, 'kind' => 'overlap']], Checklist::overlaps($this->rule(10, 'import', self::CAR), $rules));
    }
}
