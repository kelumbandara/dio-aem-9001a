<?php

use App\Modules\Approvals\Actions\SavePolicy;
use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Approvals\DTOs\GateDecision;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Core\Models\MfaSecret;
use App\Modules\Core\Models\User;
use App\Modules\Inventory\Actions\SaveWarehouse;
use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\DTOs\PostedMovement;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\WorkCenter;
use App\Modules\Manufacturing\Services\BomService;
use App\Modules\Manufacturing\Services\ProductionOrderService;
use App\Modules\Manufacturing\Services\SetupService;
use App\Modules\MasterData\Actions\ApplyPriceChanges;
use App\Modules\MasterData\Actions\SaveItem;
use App\Modules\MasterData\Actions\SavePriceList;
use App\Modules\MasterData\Database\Seeders\ReferenceDataSeeder;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Support\InputMapper;
use App\Support\Exceptions\BusinessException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Approvals\FakePayment;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        // The gated action used by the approval tests.
        FakePayment::reset();
        app(ApprovalService::class)->registerAction(FakePayment::class);
    })
    ->in('Feature');
pest()->extend(TestCase::class)->in('Unit', 'Architecture', 'Concurrency');

/** Give a user a confirmed TOTP secret and return it (base32). */
function enrolMfa(User $user): string
{
    $secret = app(Google2FA::class)->generateSecretKey();
    MfaSecret::query()->create(['user_id' => $user->id, 'secret' => $secret, 'confirmed_at' => now()]);

    return $secret;
}

/** An admin who has completed MFA enrolment (admin/super-admin roles require it). */
function adminUser(string $role = 'admin'): User
{
    $user = User::factory()->withRole($role)->create();
    enrolMfa($user);

    return $user;
}

/** A user whose role needs no MFA, holding exactly the given permissions. */
function userWithPermissions(string ...$permissions): User
{
    $role = Role::findOrCreate('test-'.md5(implode(',', $permissions)), 'web');
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $role->syncPermissions($permissions);

    return User::factory()->withRole($role->name)->create();
}

/** A user whose role needs no MFA and holds every Master Data permission (plus any extra given). */
function masterDataUser(string ...$extra): User
{
    return userWithPermissions(...[
        'masterdata.reference.view', 'masterdata.reference.manage', 'masterdata.item.view', 'masterdata.item.manage',
        'masterdata.customer.view', 'masterdata.customer.manage', 'masterdata.supplier.view', 'masterdata.supplier.manage',
        'masterdata.supplier.bank.view', 'masterdata.supplier.bank.manage', 'masterdata.price_list.view',
        'masterdata.price_list.manage', 'masterdata.import', ...$extra,
    ]);
}

/** An active user holding the given business role (created on demand). Not an MFA role. */
function userWithRole(string $role, array $attributes = []): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->withRole($role)->create($attributes);
}

/**
 * A policy for the fake payment action. By default: payments from 1,000.00 need one approval by role
 * "finance-approver". Pass `steps` (as SavePolicy expects) to build chains.
 *
 * @param  array<string, mixed>  $overrides
 */
function paymentPolicy(array $overrides = []): ApprovalPolicy
{
    $steps = $overrides['steps'] ?? [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']];
    foreach ($steps as $step) {
        if (($step['type'] ?? 'role') === 'role') {
            Role::findOrCreate($step['role'], 'web');
        }
    }

    return app(SavePolicy::class)(array_merge([
        'name' => 'Payments',
        'action_key' => FakePayment::approvalKey(),
        'risk_levels' => null,
        'min_amount' => '1000.0000',
        'max_amount' => null,
        'branch_ids' => null,
        'priority' => 100,
        'expiry_hours' => 72,
        'reminder_after_hours' => 24,
        'allow_self_approval' => false,
        'distinct_approvers' => true,
        'is_active' => true,
    ], $overrides, ['steps' => $steps]));
}

/** Ask the gate for a payment. */
function requestPayment(User $requester, string $amount = '5000.0000', array $extra = [], ?string $key = null): GateDecision
{
    return app(ApprovalService::class)->gate(
        FakePayment::approvalKey(),
        ['amount' => $amount, 'payee' => 'ACME', ...$extra],
        $requester->id,
        idempotencyKey: $key,
    );
}

function approvalRequest(GateDecision $decision): ApprovalRequest
{
    return ApprovalRequest::query()->where('public_id', $decision->requestId)->firstOrFail();
}

/** Create an item through the same path the API uses (codes in, ids out). @param array<string, mixed> $overrides */
function mdItem(array $overrides = []): Item
{
    static $n = 0;
    $n++;

    return app(SaveItem::class)(app(InputMapper::class)->item(array_merge([
        'name' => "Test item {$n}", 'category' => 'GENERAL', 'base_uom' => 'PCS',
    ], $overrides)));
}

/** @param array<string, mixed> $overrides */
function mdPriceList(array $overrides = []): PriceList
{
    static $n = 0;
    $n++;

    return app(SavePriceList::class)(app(InputMapper::class)->priceList(array_merge([
        'name' => "List {$n}", 'type' => 'sales', 'currency' => 'LKR',
    ], $overrides)));
}

/**
 * Set prices on a list directly (no approval path).
 *
 * @param  list<array<string, mixed>>  $rows  each {item: code, uom?: code, min_qty?, unit_price}
 */
function mdPrices(PriceList $list, array $rows): void
{
    app(ApplyPriceChanges::class)(
        $list->id,
        app(InputMapper::class)->priceChanges($rows),
    );
}

/** Authenticate the next API call as this user (and drop any user cached from an earlier call in the same test). */
function asApi(User $user)
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($user);

    return test();
}

/** @return array<string, string> a fresh Idempotency-Key header */
function idemKey(): array
{
    return ['Idempotency-Key' => (string) Str::uuid()];
}

/** @param array<string, mixed> $overrides */
function invWarehouse(array $overrides = []): Warehouse
{
    static $n = 0;
    $n++;

    return app(SaveWarehouse::class)(array_merge(['code' => "WH{$n}", 'name' => "Warehouse {$n}"], $overrides));
}

/** A stock line for a master-data item (quantities as strings). @param array<string, mixed> $o StockLine fields */
function invLine(Item $item, string $qty, array $o = []): StockLine
{
    return new StockLine(...array_merge(['itemId' => $item->id, 'qty' => $qty], $o));
}

/**
 * Post a movement straight through the poster (no permission or approval layer).
 *
 * @param  list<StockLine>  $lines
 * @param  array<string, mixed>  $o  StockMovement fields except type/lines
 */
function invPost(string $type, array $lines, array $o = []): PostedMovement
{
    $reason = ['receipt' => 'purchase', 'issue' => 'sale', 'transfer' => 'transfer', 'adjustment' => 'count'][$type];

    return app(StockPoster::class)->post(new StockMovement(...array_merge([
        'type' => $type, 'postingDate' => now('Asia/Colombo')->toDateString(), 'reason' => $reason, 'lines' => $lines,
    ], $o)));
}

/** Receive $qty of an item into a warehouse at $cost per base unit. */
function invReceive(Item $item, Warehouse $wh, string $qty, string $cost, array $o = []): PostedMovement
{
    return invPost('receipt', [invLine($item, $qty, ['warehouseId' => $wh->id, 'unitCost' => $cost] + ($o['line'] ?? []))], Arr::except($o, 'line'));
}

/** Current on-hand of an item in a warehouse, base units. */
function invOnHand(Item $item, ?Warehouse $wh = null): string
{
    return app(StockAvailability::class)->level($item->id, $wh?->id)->onHand;
}

function invUser(string ...$extra): User
{
    return userWithPermissions(...[
        'inventory.warehouse.view', 'inventory.warehouse.manage', 'inventory.stock.view', 'inventory.receive', 'inventory.issue',
        'inventory.transfer', 'inventory.adjust', 'inventory.reverse', 'inventory.reorder.manage', ...$extra,
    ]);
}

/** @param array<string, mixed> $line @param array<string, mixed> $o */
function docPayload(string $type, array $line, array $o = []): array
{
    return array_merge([
        'type' => $type, 'reason' => ['receipt' => 'purchase', 'issue' => 'sale', 'transfer' => 'transfer', 'adjustment' => 'count'][$type],
        'lines' => [$line],
    ], $o);
}

function finUser(string ...$extra): User
{
    return userWithPermissions(...[
        'finance.view', 'finance.account.manage', 'finance.mapping.manage', 'finance.bank.manage', 'finance.period.manage', 'finance.period.lock',
        'finance.journal.post', 'finance.reverse', 'finance.ar.view', 'finance.ar.receive', 'finance.ap.view', 'finance.ap.pay', 'finance.report.view', ...$extra,
    ]);
}

// ---------------------------------------------------------------------------------------------------- manufacturing

/** Expect a business error with this code. */
function mfgFails(Closure $fn, string $code): void
{
    try {
        $fn();
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    test()->fail("Expected business error {$code}.");
}

/** A work centre made the way the screens make it. */
function mfgCenter(string $code = 'CUT', array $o = []): WorkCenter
{
    return app(SetupService::class)->save('work-centers', array_merge(['code' => $code, 'name' => "Centre {$code}", 'capacity_hours_per_day' => '8', 'hourly_rate' => '600'], $o));
}

/** @param  list<array{item: Item, qty: string, uom?: string, scrap_pct?: string}>  $components */
function mfgDraft(Item $product, array $components, array $o = []): BomVersion
{
    return app(BomService::class)->save(array_merge([
        'item' => $product->code,
        'components' => array_map(fn (array $c) => ['item' => $c['item']->code] + Arr::except($c, 'item'), $components),
    ], $o));
}

/** A bill of materials that is drafted and activated in one go. */
function mfgBom(Item $product, array $components, array $o = []): BomVersion
{
    return app(BomService::class)->activate(mfgDraft($product, $components, $o));
}

/** @param array<string, mixed> $o */
function mfgOrder(Item $product, string $qty, Warehouse $wh, array $o = []): ProductionOrder
{
    return app(ProductionOrderService::class)->save(array_merge([
        'item' => $product->code, 'qty' => $qty, 'source_warehouse_id' => $wh->id, 'output_warehouse_id' => $wh->id,
    ], $o));
}

/** A released order. */
function mfgReleased(Item $product, string $qty, Warehouse $wh, array $o = []): ProductionOrder
{
    $order = mfgOrder($product, $qty, $wh, $o);

    return app(ProductionOrderService::class)->release($order, userWithPermissions('manufacturing.order.release')->id)['order'];
}

/** Run a statement the database is expected to refuse without poisoning the test's own transaction. */
function inSavepoint(Closure $statement): mixed
{
    return DB::transaction($statement);
}
