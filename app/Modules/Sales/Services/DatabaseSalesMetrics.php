<?php

namespace App\Modules\Sales\Services;

use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\Sales\Contracts\SalesMetrics;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseSalesMetrics implements SalesMetrics
{
    public function __construct(private readonly PartyDirectory $parties, private readonly ItemCatalog $items) {}

    public function summary(string $from, string $to): array
    {
        $inv = DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(net_total), 0) AS net, COALESCE(SUM(gross_total), 0) AS gross FROM sales_invoices WHERE invoice_date BETWEEN ? AND ?', [$from, $to]);
        $cn = DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(net_total), 0) AS net, COALESCE(SUM(gross_total), 0) AS gross FROM sales_credit_notes WHERE note_date BETWEEN ? AND ?', [$from, $to]);
        $q = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(net_total), 0) AS v FROM sales_quotations WHERE quote_date BETWEEN ? AND ? AND status IN ('sent', 'accepted', 'converted')", [$from, $to]);
        $o = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(net_total), 0) AS v FROM sales_orders WHERE confirmed_at IS NOT NULL AND (confirmed_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Colombo')::date BETWEEN ? AND ? AND status IN ('confirmed', 'completed')", [$from, $to]);
        $d = DB::selectOne("SELECT COUNT(*) AS n FROM sales_deliveries WHERE delivery_date BETWEEN ? AND ? AND status = 'posted'", [$from, $to]);

        $net = Decimal::sub((string) $inv->net, (string) $cn->net);

        return [
            'invoices' => (int) $inv->n, 'net_invoiced' => Decimal::round((string) $inv->net, 2), 'gross_invoiced' => Decimal::round((string) $inv->gross, 2),
            'credit_notes' => (int) $cn->n, 'net_credited' => Decimal::round((string) $cn->net, 2),
            'net_sales' => Decimal::round($net, 2), 'gross_sales' => Decimal::round(Decimal::sub((string) $inv->gross, (string) $cn->gross), 2),
            'average_invoice' => (int) $inv->n === 0 ? '0.00' : Decimal::round(Decimal::div((string) $inv->net, (string) $inv->n), 2),
            'quotations' => (int) $q->n, 'quotations_value' => Decimal::round((string) $q->v, 2),
            'orders_confirmed' => (int) $o->n, 'orders_value' => Decimal::round((string) $o->v, 2), 'deliveries' => (int) $d->n,
        ];
    }

    public function dailyNet(string $from, string $to): array
    {
        $net = [];
        foreach (DB::select('SELECT invoice_date AS d, SUM(net_total) AS v FROM sales_invoices WHERE invoice_date BETWEEN ? AND ? GROUP BY invoice_date', [$from, $to]) as $r) {
            $net[(string) $r->d] = (string) $r->v;
        }
        foreach (DB::select('SELECT note_date AS d, SUM(net_total) AS v FROM sales_credit_notes WHERE note_date BETWEEN ? AND ? GROUP BY note_date', [$from, $to]) as $r) {
            $net[(string) $r->d] = Decimal::sub($net[(string) $r->d] ?? '0', (string) $r->v);
        }

        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->lessThanOrEqualTo(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            $out[] = ['date' => $d->toDateString(), 'net' => Decimal::round($net[$d->toDateString()] ?? '0', 2)];
        }

        return $out;
    }

    public function topCustomers(string $from, string $to, int $limit = 10): array
    {
        $rows = DB::select(
            'SELECT customer_id, SUM(net) AS net, SUM(n) AS invoices FROM (
                SELECT customer_id, net_total AS net, 1 AS n FROM sales_invoices WHERE invoice_date BETWEEN ? AND ?
                UNION ALL SELECT customer_id, -net_total, 0 FROM sales_credit_notes WHERE note_date BETWEEN ? AND ?
             ) t GROUP BY customer_id HAVING SUM(net) <> 0 ORDER BY SUM(net) DESC, customer_id LIMIT ?',
            [$from, $to, $from, $to, $limit],
        );

        return array_map(function ($r) {
            $c = $this->parties->customer((int) $r->customer_id);

            return ['customer_id' => (int) $r->customer_id, 'public_id' => $c->publicId ?? '', 'code' => $c->code ?? '', 'name' => $c->name ?? '', 'invoices' => (int) $r->invoices, 'net' => Decimal::round((string) $r->net, 2)];
        }, $rows);
    }

    public function topItems(string $from, string $to, int $limit = 10): array
    {
        $rows = DB::select(
            'SELECT item_id, SUM(qty) AS qty, SUM(net) AS net FROM (
                SELECT l.item_id, l.qty, l.net_amount AS net FROM sales_invoice_lines l JOIN sales_invoices i ON i.id = l.invoice_id WHERE i.invoice_date BETWEEN ? AND ?
                UNION ALL SELECT l.item_id, -l.qty, -l.net_amount FROM sales_credit_note_lines l JOIN sales_credit_notes c ON c.id = l.credit_note_id WHERE c.note_date BETWEEN ? AND ?
             ) t GROUP BY item_id HAVING SUM(net) <> 0 ORDER BY SUM(net) DESC, item_id LIMIT ?',
            [$from, $to, $from, $to, $limit],
        );
        $info = $this->items->findMany(array_map(fn ($r) => (int) $r->item_id, $rows));

        return array_map(fn ($r) => [
            'item_id' => (int) $r->item_id, 'code' => $info[(int) $r->item_id]->code ?? '', 'name' => $info[(int) $r->item_id]->name ?? '', 'uom' => $info[(int) $r->item_id]->baseUomCode ?? '',
            'qty' => Decimal::trim(Decimal::round((string) $r->qty, 6)), 'net' => Decimal::round((string) $r->net, 2),
        ], $rows);
    }

    public function largestInvoices(string $from, string $to, int $limit = 10): array
    {
        $rows = DB::select('SELECT public_id, document_no, customer_id, invoice_date, gross_total FROM sales_invoices WHERE invoice_date BETWEEN ? AND ? ORDER BY gross_total DESC, id LIMIT ?', [$from, $to, $limit]);

        return array_map(fn ($r) => [
            'id' => (string) $r->public_id, 'document_no' => (string) $r->document_no, 'customer' => $this->parties->customer((int) $r->customer_id)->name ?? '',
            'date' => (string) $r->invoice_date, 'gross' => Decimal::round((string) $r->gross_total, 2),
        ], $rows);
    }

    public function openOrders(int $limit = 10): array
    {
        $today = CarbonImmutable::now(config('core.display_timezone'))->toDateString();
        $rows = DB::select(
            "SELECT o.public_id, o.document_no, o.customer_id, o.required_date,
                    GREATEST(o.gross_total - COALESCE((SELECT SUM(i.gross_total) FROM sales_invoices i WHERE i.order_id = o.id), 0), 0) AS to_invoice
             FROM sales_orders o WHERE o.status = 'confirmed' ORDER BY to_invoice DESC, o.id",
        );
        $open = array_values(array_filter($rows, fn ($r) => Decimal::compare((string) $r->to_invoice, '0') > 0));

        $total = '0';
        $overdue = 0;
        foreach ($open as $r) {
            $total = Decimal::add($total, (string) $r->to_invoice);
            $overdue += $r->required_date !== null && (string) $r->required_date < $today ? 1 : 0;
        }

        return [
            'orders' => count($open), 'to_invoice' => Decimal::round($total, 2), 'overdue' => $overdue,
            'list' => array_map(fn ($r) => [
                'id' => (string) $r->public_id, 'document_no' => (string) $r->document_no, 'customer' => $this->parties->customer((int) $r->customer_id)->name ?? '',
                'required_date' => $r->required_date === null ? null : (string) $r->required_date, 'to_invoice' => Decimal::round((string) $r->to_invoice, 2),
                'overdue' => $r->required_date !== null && (string) $r->required_date < $today,
            ], array_slice($open, 0, $limit)),
        ];
    }
}
