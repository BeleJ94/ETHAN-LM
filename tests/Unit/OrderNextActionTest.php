<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\OrderNextAction;
use PHPUnit\Framework\TestCase;

final class OrderNextActionTest extends TestCase
{
    public function testEachOpenStatusHasAnAction(): void
    {
        foreach (['received' => 'source', 'sourcing' => 'issue_po', 'awaiting_licence' => 'licence', 'inspection' => 'inspection',
            'blocked' => 'ara', 'ordered' => 'ship', 'in_delivery' => 'follow', 'delivered' => 'close'] as $status => $code) {
            self::assertSame($code, OrderNextAction::for($status)['code'], $status);
        }
        self::assertSame('danger', OrderNextAction::for('blocked')['tone']);
        self::assertSame('success', OrderNextAction::for('delivered')['tone']);
    }

    public function testFinishedOrdersHaveNoAction(): void
    {
        self::assertNull(OrderNextAction::for('closed'));
        self::assertNull(OrderNextAction::for('cancelled'));
        self::assertNull(OrderNextAction::for('unknown'));
    }

    public function testDraftSupplierPoMustBeSentFirst(): void
    {
        self::assertSame('send_po', OrderNextAction::for('ordered', 1)['code']);
        self::assertSame('send_po', OrderNextAction::for('sourcing', 2)['code']);
        // Ailleurs dans le flux, le brouillon n'est pas l'action prioritaire
        self::assertSame('ara', OrderNextAction::for('blocked', 1)['code']);
        self::assertSame('close', OrderNextAction::for('delivered', 1)['code']);
    }

    public function testGroupsCoverEveryStatusOnce(): void
    {
        $covered = array_merge(...array_values(array_filter(OrderNextAction::GROUPS)));
        self::assertSame(count($covered), count(array_unique($covered)));
        foreach (['received', 'sourcing', 'awaiting_licence', 'inspection', 'blocked', 'ordered', 'in_delivery', 'delivered', 'closed'] as $s) {
            self::assertContains($s, $covered, $s);
        }
    }
}
