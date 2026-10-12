<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Contracts\QuotationDrafter;
use App\Modules\Sales\Exceptions\SalesException;
use Illuminate\Contracts\Auth\Access\Authorizable;

final class EloquentQuotationDrafter implements QuotationDrafter
{
    public const MAX_LINES = 20;

    public function __construct(private readonly QuotationService $quotations) {}

    public function draft(Authorizable $actor, int $userId, array $data): array
    {
        if (! $actor->can('sales.quotation.manage')) {
            throw SalesException::make('sales.forbidden', 'You may not prepare quotations.', 403);
        }
        $lines = [];
        foreach (array_slice((array) ($data['lines'] ?? []), 0, self::MAX_LINES + 1) as $row) {
            // only the item and the quantity: price, discount, tax and unit are never taken from the helper
            $lines[] = ['item' => (string) ($row['item'] ?? ''), 'qty' => (string) ($row['qty'] ?? '')];
        }
        if (count($lines) > self::MAX_LINES) {
            throw SalesException::make('sales.too_many_lines', 'A drafted quotation may have at most '.self::MAX_LINES.' lines.', 422, 'lines');
        }

        $note = trim((string) ($data['notes'] ?? ''));
        $quotation = $this->quotations->save([
            'customer' => (string) ($data['customer'] ?? ''),
            'notes' => 'Drafted by the AI assistant: please check before sending.'.($note !== '' ? ' '.$note : ''),
            'lines' => $lines,
        ], null, $userId);

        return [
            'id' => $quotation->public_id, 'document_no' => $quotation->document_no, 'customer' => (string) ($data['customer'] ?? ''),
            'lines' => count($lines), 'net_total' => $quotation->net_total, 'gross_total' => $quotation->gross_total, 'status' => $quotation->status,
        ];
    }
}
