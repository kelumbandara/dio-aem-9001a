<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Procurement\Contracts\PurchaseOrderDrafter;
use App\Modules\Procurement\Exceptions\ProcurementException;
use Illuminate\Contracts\Auth\Access\Authorizable;

final class EloquentPurchaseOrderDrafter implements PurchaseOrderDrafter
{
    public const MAX_LINES = 20;

    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function draft(Authorizable $actor, int $userId, array $data): array
    {
        if (! $actor->can('procurement.order.manage')) {
            throw ProcurementException::make('procurement.forbidden', 'You may not prepare purchase orders.', 403);
        }
        $lines = [];
        foreach (array_slice((array) ($data['lines'] ?? []), 0, self::MAX_LINES + 1) as $row) {
            // item, quantity and (optionally) the agreed price; tax, discount and unit are never taken from the helper
            $line = ['item' => (string) ($row['item'] ?? ''), 'qty' => (string) ($row['qty'] ?? '')];
            if (isset($row['unit_price'])) {
                $line['unit_price'] = (string) $row['unit_price'];
            }
            $lines[] = $line;
        }
        if (count($lines) > self::MAX_LINES) {
            throw ProcurementException::make('procurement.too_many_lines', 'A drafted purchase order may have at most '.self::MAX_LINES.' lines.', 422, 'lines');
        }

        $note = trim((string) ($data['notes'] ?? ''));
        $order = $this->orders->save([
            'supplier' => (string) ($data['supplier'] ?? ''), 'warehouse' => (string) ($data['warehouse'] ?? ''),
            'notes' => 'Drafted by the AI assistant: please check before submitting.'.($note !== '' ? ' '.$note : ''),
            'lines' => $lines,
        ], null, $userId);

        return [
            'id' => $order->public_id, 'document_no' => $order->document_no, 'supplier' => (string) ($data['supplier'] ?? ''),
            'lines' => count($lines), 'net_total' => $order->net_total, 'gross_total' => $order->gross_total, 'status' => $order->status,
        ];
    }
}
