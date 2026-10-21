<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Cmup;
use App\Domain\DomainException;
use App\Domain\MovementType;
use App\Security\Auth;
use PDO;

/**
 * Seul point d'écriture du stock. Chaque opération :
 *  - verrouille l'article (sérialise le CMUP) puis le niveau de stock concerné ;
 *  - interdit le stock négatif ;
 *  - écrit le mouvement immuable + le journal d'activité dans la même transaction.
 */
final class StockService
{
    /** @param array{source_type?:?string,source_id?:?int} $source */
    public function receive(
        int $itemId,
        int $warehouseId,
        float $qty,
        float $unitCost,
        string $currency,
        float $rate,
        string $movedAt,
        ?string $note = null,
        array $source = [],
        ?string $reference = null,
        ?string $suggested = null,
    ): string {
        return Database::transaction(function () use ($itemId, $warehouseId, $qty, $unitCost, $currency, $rate, $movedAt, $note, $source, $reference, $suggested) {
            $ref = ReferenceGenerator::resolve('movement_in', $reference, $suggested);
            $this->apply(MovementType::In, $ref, $itemId, $warehouseId, $qty, $movedAt, $note, [
                'unit_cost' => $unitCost,
                'currency_code' => $currency,
                'exchange_rate' => $rate,
                'unit_cost_base' => Cmup::toBase($unitCost, $rate),
            ] + $source);
            return $ref;
        });
    }

    public function issue(int $itemId, int $warehouseId, float $qty, string $movedAt, ?string $note = null, array $source = [], ?string $reference = null, ?string $suggested = null): string
    {
        return Database::transaction(function () use ($itemId, $warehouseId, $qty, $movedAt, $note, $source, $reference, $suggested) {
            $ref = ReferenceGenerator::resolve('movement_out', $reference, $suggested);
            $this->apply(MovementType::Out, $ref, $itemId, $warehouseId, $qty, $movedAt, $note, $source);
            return $ref;
        });
    }

    public function transfer(int $itemId, int $fromWarehouseId, int $toWarehouseId, float $qty, string $movedAt, ?string $note = null, ?string $reference = null, ?string $suggested = null): string
    {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new DomainException('stock.error.same_warehouse');
        }
        return Database::transaction(function () use ($itemId, $fromWarehouseId, $toWarehouseId, $qty, $movedAt, $note, $reference, $suggested) {
            $ref = ReferenceGenerator::resolve('movement_transfer', $reference, $suggested);
            $this->apply(MovementType::TransferOut, $ref, $itemId, $fromWarehouseId, $qty, $movedAt, $note, ['transfer_ref' => $ref]);
            $this->apply(MovementType::TransferIn, $ref, $itemId, $toWarehouseId, $qty, $movedAt, $note, ['transfer_ref' => $ref]);
            return $ref;
        });
    }

    /** Ajustement d'inventaire : on saisit la quantité comptée, l'écart est calculé. */
    public function adjust(int $itemId, int $warehouseId, float $countedQty, string $movedAt, string $note, ?string $reference = null, ?string $suggested = null): ?string
    {
        if ($countedQty < 0) {
            throw new DomainException('stock.error.negative_count');
        }
        return Database::transaction(function () use ($itemId, $warehouseId, $countedQty, $movedAt, $note, $reference, $suggested) {
            $this->lockItem($itemId);
            $current = $this->lockLevel($itemId, $warehouseId);
            $delta = round($countedQty - $current, 3);
            if ($delta == 0.0) {
                return null;
            }
            $ref = ReferenceGenerator::resolve('movement_adjust', $reference, $suggested);
            $type = $delta > 0 ? MovementType::AdjustIn : MovementType::AdjustOut;
            $this->apply($type, $ref, $itemId, $warehouseId, abs($delta), $movedAt, $note);
            return $ref;
        });
    }

    /** @param array<string,mixed> $extra */
    private function apply(MovementType $type, string $ref, int $itemId, int $warehouseId, float $qty, string $movedAt, ?string $note, array $extra = []): void
    {
        if ($qty <= 0) {
            throw new DomainException('stock.error.qty_positive');
        }
        $pdo = Database::pdo();
        $item = $this->lockItem($itemId);
        $level = $this->lockLevel($itemId, $warehouseId);
        $newLevel = round($level + $type->sign() * $qty, 3);
        if ($newLevel < 0) {
            throw new DomainException('stock.error.insufficient', ['available' => fmt_qty($level)]);
        }

        $avgCost = (float) $item['avg_cost'];
        $unitCostBase = (float) ($extra['unit_cost_base'] ?? $avgCost);
        if ($type->revaluesCost()) {
            $totalQty = $this->totalQty($itemId);
            $avgCost = Cmup::afterReceipt($totalQty, $avgCost, $qty, $unitCostBase);
            $pdo->prepare('UPDATE items SET avg_cost = ? WHERE id = ?')->execute([$avgCost, $itemId]);
        }

        $pdo->prepare('INSERT INTO stock_levels (item_id, warehouse_id, quantity) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)')
            ->execute([$itemId, $warehouseId, $newLevel]);

        $pdo->prepare('INSERT INTO stock_movements
            (reference, type, item_id, warehouse_id, quantity, unit_cost, currency_code, exchange_rate, unit_cost_base,
             balance_after, avg_cost_after, transfer_ref, source_type, source_id, note, user_id, moved_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $ref, $type->value, $itemId, $warehouseId, $qty,
                $extra['unit_cost'] ?? null, $extra['currency_code'] ?? null, $extra['exchange_rate'] ?? null, $unitCostBase,
                $newLevel, $avgCost, $extra['transfer_ref'] ?? null, $extra['source_type'] ?? null, $extra['source_id'] ?? null,
                $note ?: null, Auth::require()->id, $movedAt,
            ]);
        $movementId = (int) $pdo->lastInsertId();

        ActivityLogger::log('stock_movement', $movementId, $type->value, null, [
            'reference' => $ref, 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
            'quantity' => $qty, 'balance' => $level . ' → ' . $newLevel,
        ]);
    }

    /** @return array<string,mixed> */
    private function lockItem(int $itemId): array
    {
        $stmt = Database::pdo()->prepare('SELECT id, avg_cost, active FROM items WHERE id = ? FOR UPDATE');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            throw new DomainException('stock.error.item_not_found');
        }
        return $item;
    }

    private function lockLevel(int $itemId, int $warehouseId): float
    {
        $stmt = Database::pdo()->prepare('SELECT quantity FROM stock_levels WHERE item_id = ? AND warehouse_id = ? FOR UPDATE');
        $stmt->execute([$itemId, $warehouseId]);
        $v = $stmt->fetchColumn();
        return $v === false ? 0.0 : (float) $v;
    }

    private function totalQty(int $itemId): float
    {
        $stmt = Database::pdo()->prepare('SELECT COALESCE(SUM(quantity), 0) FROM stock_levels WHERE item_id = ?');
        $stmt->execute([$itemId]);
        return (float) $stmt->fetchColumn();
    }
}
