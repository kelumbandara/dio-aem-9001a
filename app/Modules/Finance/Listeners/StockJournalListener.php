<?php

namespace App\Modules\Finance\Listeners;

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\Events\IntegrationEvent;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\DTOs\JournalRequest;
use App\Support\Decimal;

/**
 * Perpetual inventory: every stock movement that changes the value of stock gets its journal, built only from the
 * `inventory.stock.moved` event (Finance never reads Inventory's tables).
 *
 *   stock value up    →  debit Inventory, credit the counter account for that kind of movement
 *   stock value down  →  debit the counter account, credit Inventory
 *
 * Transfers move stock at cost and change nothing in total, so they get no journal. A reversal posts the opposite
 * amounts against the original's counter account, as its own journal (so it never depends on the original's journal
 * having been posted first). Delivery is at least once and out of order is fine: the source key makes it idempotent.
 */
final class StockJournalListener
{
    /** type => reason => mapping key of the counter account */
    private const COUNTER = [
        'receipt' => ['purchase' => 'grni', 'production' => 'wip', 'return' => 'cogs', 'opening' => 'opening_balance_equity', 'other' => 'stock_adjustment'],
        'issue' => ['sale' => 'cogs', 'production' => 'wip', 'sample' => 'sample_expense', 'scrap' => 'stock_adjustment', 'other' => 'stock_adjustment'],
        'adjustment' => [],
    ];

    public function __construct(private readonly JournalPoster $journals, private readonly ModuleRegistry $modules) {}

    public static function counterKey(string $type, string $reason): string
    {
        return self::COUNTER[$type][$reason] ?? 'stock_adjustment';
    }

    public function handle(IntegrationEvent $event): void
    {
        if ($event->type !== 'inventory.stock.moved' || ! $this->modules->isEnabled('finance')) {
            return;   // not ours, or accounting is switched off (stock then runs without journals)
        }

        $d = $event->data;
        $delta = (string) ($d['inventory_delta'] ?? '0');
        if (Decimal::compare($delta, '0', 4) === 0) {
            return;   // a transfer, or a movement worth nothing
        }

        $amount = ltrim($delta, '-');
        $counter = self::counterKey((string) ($d['counter_type'] ?? $d['type']), (string) ($d['counter_reason'] ?? $d['reason']));
        $up = ! str_starts_with($delta, '-');
        $kind = $d['reverses_document_id'] !== null ? 'Reversal of stock document' : 'Stock '.$d['type'];

        $this->journals->post(new JournalRequest(
            entryDate: (string) $d['posting_date'],
            description: "{$kind} {$d['document_no']} ({$d['reason']})",
            lines: [
                $up ? JournalLineData::debit($amount, 'inventory') : JournalLineData::credit($amount, 'inventory'),
                $up ? JournalLineData::credit($amount, $counter) : JournalLineData::debit($amount, $counter),
            ],
            reference: (string) $d['document_no'],
            sourceType: 'stock_document',
            sourceId: (string) $d['document_id'],
            kind: 'system',
            createdBy: $event->actorId,
            rollForward: true,
        ));
    }
}
