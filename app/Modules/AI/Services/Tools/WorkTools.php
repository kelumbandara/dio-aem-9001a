<?php

namespace App\Modules\AI\Services\Tools;

use App\Modules\AI\Contracts\BusinessTool;
use App\Modules\AI\DTOs\ToolContext;
use App\Modules\AI\DTOs\ToolResult;
use App\Modules\AI\Exceptions\AiException;
use App\Modules\AI\Services\DraftLimiter;
use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\Procurement\Contracts\PurchaseOrderDrafter;
use App\Modules\Sales\Contracts\QuotationDrafter;
use Illuminate\Contracts\Container\Container;

/**
 * The tools that help the department agents do their daily work: look up a customer, supplier, item or stock level (read, R0), and
 * prepare drafts (R1). A draft is a real document in the "draft" state that nobody has sent, confirmed or posted; the person reviews
 * it on its page. The assistant never submits, approves, posts or sends anything (R3), and cannot set prices on a quotation.
 */
final class WorkTools
{
    public function __construct(private readonly Container $container) {}

    /** @return list<BusinessTool> */
    public function all(): array
    {
        return array_merge($this->lookups(), $this->drafts());
    }

    /** @return list<BusinessTool> */
    private function lookups(): array
    {
        $term = ['term' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Part of the code or name.']];
        $parties = fn (string $side) => function (array $i) use ($side) {
            $rows = array_slice($this->container->make(PartyDirectory::class)->search($side, (string) $i['term'], 10), 0, 10);

            return new ToolResult(
                ['matches' => array_map(fn ($p) => ['code' => $p->code, 'name' => $p->name, 'active' => $p->isActive], $rows)],
                array_map(fn ($p) => ['label' => $p->name, 'url' => route($side === 'customer' ? 'customers.show' : 'suppliers.show', [$p->publicId])], $rows),
            );
        };

        return [
            new ClosureTool('master.find_customer', 'Find customers by part of their code or name. Use it to get the exact customer code before preparing a quotation.', 'masterdata', 'masterdata.customer.view', $term, ['term'], $parties('customer')),
            new ClosureTool('master.find_supplier', 'Find suppliers by part of their code or name. Use it to get the exact supplier code before preparing a purchase order.', 'masterdata', 'masterdata.supplier.view', $term, ['term'], $parties('supplier')),
            new ClosureTool('master.find_item', 'Find items by part of their code, name or barcode, with the unit they are counted in.', 'masterdata', 'masterdata.item.view', $term, ['term'], function (array $i) {
                $rows = array_slice(array_values($this->container->make(ItemCatalog::class)->search((string) $i['term'], 10)), 0, 10);

                return new ToolResult(
                    ['matches' => array_map(fn ($x) => ['code' => $x->code, 'name' => $x->name, 'unit' => $x->baseUomCode, 'stocked' => $x->isStocked(), 'sellable' => $x->isSellable, 'purchasable' => $x->isPurchasable, 'active' => $x->isActive], $rows)],
                    array_map(fn ($x) => ['label' => $x->name, 'url' => route('items.show', [$x->code])], $rows),
                );
            }),
            new ClosureTool('stock.item', "Quantity of one item on hand, promised to orders and available, across all warehouses (in the item's own unit).", 'inventory', 'inventory.stock.view',
                ['item' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Exact item code.']], ['item'], function (array $i) {
                    $item = $this->container->make(ItemCatalog::class)->findByCode((string) $i['item']);
                    if ($item === null) {
                        throw AiException::make('ai.tool_input', 'There is no item with that code. Use master.find_item to search.', 422, 'item');
                    }
                    $l = $this->container->make(StockAvailability::class)->level($item->id);

                    return new ToolResult(
                        ['item' => $item->code, 'name' => $item->name, 'unit' => $item->baseUomCode, 'on_hand' => $l->onHand, 'reserved' => $l->reserved, 'available' => $l->available],
                        [['label' => $item->name, 'url' => route('inventory.stock.item', [$item->code])]],
                    );
                }),
        ];
    }

    /** @return list<BusinessTool> */
    private function drafts(): array
    {
        $qty = ['type' => 'string', 'pattern' => '^\d{1,12}(\.\d{1,6})?$', 'description' => 'Quantity as a number, e.g. 12 or 2.5.'];
        $notes = ['type' => 'string', 'maxLength' => 300, 'description' => 'Optional note for the reviewer.'];
        $limiter = fn (): DraftLimiter => $this->container->make(DraftLimiter::class);

        return [
            new ClosureTool('sales.draft_quotation', "Prepare a DRAFT quotation for a customer. It is not sent or accepted; the person reviews it. Prices come from the customer's price list; you cannot set prices or discounts. Get the exact customer and item codes first with master.find_customer and master.find_item.", 'sales', 'sales.quotation.manage', [
                'customer' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Exact customer code.'],
                'notes' => $notes,
                'lines' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['type' => 'object', 'properties' => ['item' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Exact item code.'], 'qty' => $qty], 'required' => ['item', 'qty']]],
            ], ['customer', 'lines'], function (array $i, ToolContext $ctx) use ($limiter) {
                $limiter()->assertCanDraft($ctx);
                $d = $this->container->make(QuotationDrafter::class)->draft($ctx->user, $ctx->userId, $i);
                $limiter()->made($ctx);

                return new ToolResult(
                    ['created' => 'draft quotation', 'document_no' => $d['document_no'], 'customer' => $d['customer'], 'lines' => $d['lines'], 'net_total' => $d['net_total'], 'gross_total' => $d['gross_total'], 'note' => 'A draft was saved. It has not been sent or accepted; the person must review it on its page.'],
                    [['label' => $d['document_no'], 'url' => route('sales.quotations.show', [$d['id']])]],
                    ['document_no' => $d['document_no']],
                );
            }, 'R1'),
            new ClosureTool('procurement.draft_purchase_order', 'Prepare a DRAFT purchase order. It is not submitted or approved; the person reviews and submits it. Get exact supplier, warehouse and item codes first. Give unit_price only if the person told you the agreed price.', 'procurement', 'procurement.order.manage', [
                'supplier' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Exact supplier code.'],
                'warehouse' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Exact warehouse code the goods come into.'],
                'notes' => $notes,
                'lines' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['type' => 'object', 'properties' => [
                    'item' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Exact item code.'], 'qty' => $qty,
                    'unit_price' => ['type' => 'string', 'pattern' => '^\d{1,12}(\.\d{1,4})?$', 'description' => 'Price per unit in LKR, net of VAT.'],
                ], 'required' => ['item', 'qty']]],
            ], ['supplier', 'warehouse', 'lines'], function (array $i, ToolContext $ctx) use ($limiter) {
                $limiter()->assertCanDraft($ctx);
                $d = $this->container->make(PurchaseOrderDrafter::class)->draft($ctx->user, $ctx->userId, $i);
                $limiter()->made($ctx);

                return new ToolResult(
                    ['created' => 'draft purchase order', 'document_no' => $d['document_no'], 'supplier' => $d['supplier'], 'lines' => $d['lines'], 'net_total' => $d['net_total'], 'gross_total' => $d['gross_total'], 'note' => 'A draft was saved. It has not been submitted or approved; the person must review and submit it on its page.'],
                    [['label' => $d['document_no'], 'url' => route('procurement.orders.show', [$d['id']])]],
                    ['document_no' => $d['document_no']],
                );
            }, 'R1'),
        ];
    }
}
