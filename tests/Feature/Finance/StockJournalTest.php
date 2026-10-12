<?php

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\Events\IntegrationEvent;
use App\Modules\Core\Models\OutboxEvent;
use App\Modules\Core\Services\OutboxRelay;
use App\Modules\Finance\Actions\ChangePeriodStatus;
use App\Modules\Finance\Actions\CreateFiscalYear;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Listeners\StockJournalListener;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FiscalPeriod;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Finance\Services\FinancialReports;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Services\StockReconciler;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->shop = invWarehouse(['code' => 'SHOP']);
    $this->item = mdItem(['name' => 'Widget']);
    $this->relay = fn () => app(OutboxRelay::class)->run(2000);
    /** Net balance (debit − credit) of an account by code. */
    $this->bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
    $this->journalFor = fn (StockDocument|int $doc) => JournalEntry::where('source_type', 'stock_document')->where('source_id', (string) ($doc instanceof StockDocument ? $doc->id : $doc))->first();
});

describe('journals from stock movements', function () {
    it('debits inventory and credits goods-received-not-invoiced for a purchase receipt', function () {
        $posted = invReceive($this->item, $this->wh, '10', '100');
        expect(JournalEntry::count())->toBe(0);   // nothing until the outbox is delivered

        ($this->relay)();

        $journal = ($this->journalFor)($posted->documentId);
        $lines = $journal->lines->map(fn ($l) => [Account::find($l->account_id)->code, $l->debit, $l->credit])->all();
        expect($lines)->toBe([['1100', '1000.0000', '0.0000'], ['1510', '0.0000', '1000.0000']])
            ->and($journal->kind)->toBe('system')->and($journal->reference)->toBe($posted->documentNo)->and($journal->entry_date->toDateString())->toBe(now('Asia/Colombo')->toDateString());
    });

    it('credits inventory and debits cost of sales when stock is sold, at the average cost', function () {
        invReceive($this->item, $this->wh, '10', '100');
        invReceive($this->item, $this->wh, '10', '200');
        $sale = invPost('issue', [invLine($this->item, '5', ['warehouseId' => $this->wh->id])]);

        ($this->relay)();

        $lines = ($this->journalFor)($sale->documentId)->lines->map(fn ($l) => [Account::find($l->account_id)->code, $l->debit, $l->credit])->all();
        expect($lines)->toBe([['1100', '0.0000', '750.0000'], ['2206', '750.0000', '0.0000']])
            ->and(($this->bal)('1100'))->toBe('2250.0000')->and(($this->bal)('2206'))->toBe('750.0000');
    });

    it('chooses the counter account by the kind and reason of the movement', function (string $type, string $reason, string $direction, string $code) {
        invReceive($this->item, $this->wh, '50', '10');   // so there is stock to take out
        $line = invLine($this->item, '2', ['warehouseId' => $this->wh->id] + ($type === 'receipt' ? ['unitCost' => '10'] : []) + ($type === 'adjustment' ? ['direction' => $direction] : []));
        $posted = invPost($type, [$line], ['reason' => $reason]);

        ($this->relay)();

        $codes = ($this->journalFor)($posted->documentId)->lines->map(fn ($l) => Account::find($l->account_id)->code)->all();
        expect($codes)->toContain($code)->toContain('1100');
    })->with([
        'purchase' => ['receipt', 'purchase', 'in', '1510'],
        'production receipt' => ['receipt', 'production', 'in', '1105'],
        'sales return' => ['receipt', 'return', 'in', '2206'],
        'opening stock' => ['receipt', 'opening', 'in', '1340'],
        'other receipt' => ['receipt', 'other', 'in', '2207'],
        'sale' => ['issue', 'sale', 'out', '2206'],
        'issue to production' => ['issue', 'production', 'out', '1105'],
        'sample' => ['issue', 'sample', 'out', '2203'],
        'scrap' => ['issue', 'scrap', 'out', '2207'],
        'count loss' => ['adjustment', 'count', 'out', '2207'],
        'found stock' => ['adjustment', 'found', 'in', '2207'],
    ]);

    it('posts no journal for a transfer, which changes no value', function () {
        invReceive($this->item, $this->wh, '10', '100');
        ($this->relay)();
        $before = JournalEntry::count();

        invPost('transfer', [invLine($this->item, '4', ['warehouseId' => $this->wh->id, 'toWarehouseId' => $this->shop->id])]);
        ($this->relay)();

        expect(JournalEntry::count())->toBe($before);
    });

    it('undoes a movement\'s journal when the stock document is reversed, against the original counter account', function () {
        $sale = (function () {
            invReceive($this->item, $this->wh, '10', '100');

            return invPost('issue', [invLine($this->item, '4', ['warehouseId' => $this->wh->id])]);
        })();
        $reversal = app(StockPoster::class)->reverse($sale->documentId);

        ($this->relay)();

        $lines = ($this->journalFor)($reversal->documentId)->lines->map(fn ($l) => [Account::find($l->account_id)->code, $l->debit, $l->credit])->all();
        expect($lines)->toBe([['1100', '400.0000', '0.0000'], ['2206', '0.0000', '400.0000']])
            ->and(($this->bal)('2206'))->toBe('0.0000')->and(($this->bal)('1100'))->toBe('1000.0000');
    });

    it('is safe when the same event is delivered twice or out of order', function () {
        $receipt = invReceive($this->item, $this->wh, '10', '100');
        $reversal = app(StockPoster::class)->reverse($receipt->documentId);

        // deliver the reversal's event first, then everything twice
        $events = OutboxEvent::where('event_type', 'inventory.stock.moved')->orderByDesc('id')->get();
        foreach ([$events, $events] as $batch) {
            foreach ($batch as $row) {
                app(StockJournalListener::class)->handle(new IntegrationEvent((string) $row->id, $row->event_type, 1, now()->toImmutable(), 'user', null, null, null, null, null, $row->payload));
            }
        }

        expect(JournalEntry::where('source_type', 'stock_document')->count())->toBe(2)->and(($this->bal)('1100'))->toBe('0.0000')->and(($this->bal)('1510'))->toBe('0.0000');
    });

    it('rolls a stock journal forward to today when its own period has been closed', function () {
        $past = now('Asia/Colombo')->subDays(2)->toDateString();
        $period = FiscalPeriod::where('starts_on', '<=', $past)->where('ends_on', '>=', $past)->first();
        $today = FiscalPeriod::where('starts_on', '<=', now('Asia/Colombo')->toDateString())->where('ends_on', '>=', now('Asia/Colombo')->toDateString())->first();
        if ($period->id === $today->id) {
            // the two days fall in one period: close the previous one and back-date into it instead
            $past = $period->starts_on->subDay()->toDateString();
            app(CreateFiscalYear::class)((int) $period->year->starts_on->year - 1, 4);
            $period = FiscalPeriod::where('starts_on', '<=', $past)->where('ends_on', '>=', $past)->first();
        }
        $posted = invReceive($this->item, $this->wh, '5', '10', ['postingDate' => $past]);
        app(ChangePeriodStatus::class)($period, 'close');

        ($this->relay)();

        expect(($this->journalFor)($posted->documentId)->entry_date->toDateString())->toBe(now('Asia/Colombo')->toDateString());
    });

    it('leaves stock alone when accounting is switched off', function () {
        app(ModuleRegistry::class)->disable('procurement');
        app(ModuleRegistry::class)->disable('sales');
        app(ModuleRegistry::class)->disable('finance');
        invReceive($this->item, $this->wh, '5', '10');

        ($this->relay)();

        expect(JournalEntry::count())->toBe(0)->and(OutboxEvent::where('event_type', 'inventory.stock.moved')->whereNotNull('processed_at')->count())->toBe(1);
    });

    it('keeps the ledger equal to the stock records through a random sequence of movements', function (int $seed) {
        mt_srand($seed);
        $items = [mdItem(['name' => 'Plain']), mdItem(['name' => 'Kilos', 'base_uom' => 'KG']), $this->item];
        $whs = [$this->wh, $this->shop];
        $pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
        $poster = app(StockPoster::class);
        $reasons = ['receipt' => ['purchase', 'production', 'return', 'opening', 'other'], 'issue' => ['sale', 'production', 'sample', 'scrap', 'other']];

        for ($step = 0; $step < 90; $step++) {
            $item = $pick($items);
            $qty = $item->name === 'Kilos' ? (string) (mt_rand(1, 40) / 8) : (string) mt_rand(1, 10);
            $kind = $pick(['receipt', 'receipt', 'issue', 'issue', 'transfer', 'adjustment', 'reverse']);
            try {
                if ($kind === 'reverse') {
                    $doc = StockDocument::where('status', 'posted')->whereNull('reverses_id')->inRandomOrder()->first();
                    $doc && $poster->reverse($doc->id);
                } elseif ($kind === 'transfer') {
                    $from = $pick($whs);
                    invPost('transfer', [invLine($item, $qty, ['warehouseId' => $from->id, 'toWarehouseId' => ($from->id === $whs[0]->id ? $whs[1] : $whs[0])->id])]);
                } elseif ($kind === 'adjustment') {
                    invPost('adjustment', [invLine($item, $qty, ['warehouseId' => $pick($whs)->id, 'direction' => $pick(['in', 'out']), 'unitCost' => '12.5'])], ['reason' => 'count']);
                } else {
                    invPost($kind, [invLine($item, $qty, ['warehouseId' => $pick($whs)->id] + ($kind === 'receipt' ? ['unitCost' => (string) (mt_rand(100, 99999) / 100)] : []))], ['reason' => $pick($reasons[$kind])]);
                }
            } catch (BusinessException) {
                // refused movements leave no trace
            }
            if ($step % 10 === 9) {
                ($this->relay)();
            }
        }
        ($this->relay)();

        $stockValue = (string) DB::table('item_costs')->sum('value');
        expect(OutboxEvent::whereNull('processed_at')->count())->toBe(0)
            ->and(($this->bal)('1100'))->toBe(Decimal_round($stockValue))
            ->and(app(FinanceReconciler::class)->discrepancies())->toBe([])
            ->and(app(StockReconciler::class)->discrepancies())->toBe([]);

        $tb = app(FinancialReports::class)->trialBalance(null, now('Asia/Colombo')->toDateString());
        expect($tb['total_debit'])->toBe($tb['total_credit']);
    })->with([11, 2027, 424242]);
});

function Decimal_round(string $v): string
{
    return Decimal::round($v === '' ? '0' : $v, 4);
}

it('does not let a stock journal be reversed by hand: the stock document is what gets reversed', function () {
    $posted = invReceive($this->item, $this->wh, '5', '10');
    app(OutboxRelay::class)->run(100);
    $journal = JournalEntry::where('source_type', 'stock_document')->where('source_id', (string) $posted->documentId)->first();

    expect(fn () => app(JournalPoster::class)->reverse($journal->id))->toThrow(BusinessException::class, 'reverse that document instead');
});
