<?php

use Tests\Support\ModuleBoundaryChecker;

/**
 * The boundary test is only worth trusting if it demonstrably fails on violations.
 * These tests build a throwaway module tree and check what the checker reports.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/dio-modules-'.bin2hex(random_bytes(4));
    mkdir($this->root);

    $this->put = function (string $path, string $body): void {
        $full = "{$this->root}/{$path}";
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, "<?php\n{$body}\n");
    };

    // core <- inventory <- sales ; finance -> core only ; reporting -> sales
    $this->checker = fn () => new ModuleBoundaryChecker($this->root, [
        'core' => [],
        'inventory' => ['core'],
        'sales' => ['inventory'],
        'finance' => ['core'],
        'reporting' => ['sales'],
    ], ['Contracts', 'DTOs', 'Events']);
});

afterEach(function () {
    $rm = function (string $dir) use (&$rm): void {
        foreach (glob($dir.'/*') ?: [] as $entry) {
            is_dir($entry) ? $rm($entry) : unlink($entry);
        }
        @rmdir($dir);
    };
    $rm($this->root);
});

it('accepts public contracts of a direct dependency', function () {
    ($this->put)('Inventory/Services/Stock.php', 'use App\Modules\Core\Contracts\AuditLogger; use App\Modules\Core\DTOs\MenuItem; use App\Modules\Core\Events\Thing;');

    expect(($this->checker)()->violations())->toBe([]);
});

it('accepts a transitive dependency', function () {
    ($this->put)('Sales/Actions/Quote.php', 'use App\Modules\Core\Contracts\NumberingService;');
    ($this->put)('Core/Contracts/NumberingService.php', 'interface NumberingService {}');

    expect(($this->checker)()->violations())->toBe([]);
});

it('rejects a dependency pointing upward', function () {
    ($this->put)('Core/Services/Bad.php', 'use App\Modules\Sales\Contracts\Quote;');

    expect(($this->checker)()->violations())->toHaveCount(1)
        ->and(($this->checker)()->violations()[0])->toContain('Core must not depend on Sales');
});

it('rejects a sideways dependency that is not declared', function () {
    ($this->put)('Finance/Services/Bad.php', 'use App\Modules\Inventory\Contracts\StockQuery;');

    expect(($this->checker)()->violations()[0])->toContain('Finance must not depend on Inventory');
});

it('rejects private namespaces of an allowed dependency', function () {
    ($this->put)('Inventory/Services/Bad.php', 'use App\Modules\Core\Models\User; use App\Modules\Core\Services\MfaService;');

    $violations = ($this->checker)()->violations();
    expect($violations)->toHaveCount(2)->and($violations[0])->toContain('non-public Core\Models');
});

it('catches fully qualified references in code, not only use statements', function () {
    ($this->put)('Inventory/Actions/Bad.php', 'class X { function f() { return new \App\Modules\Core\Models\Branch(); } }');

    expect(($this->checker)()->violations())->toHaveCount(1);
});

it('ignores mentions in comments and strings', function () {
    ($this->put)('Core/Services/Ok.php', "// see App\\Modules\\Sales\\Models\\Order\n/* App\\Modules\\Sales\\Models\\Order */\n\$x = 'App\\Modules\\Sales\\Models\\Order';");

    expect(($this->checker)()->violations())->toBe([]);
});

it('allows a module to use its own internals', function () {
    ($this->put)('Sales/Actions/Quote.php', 'use App\Modules\Sales\Models\Quotation;');

    expect(($this->checker)()->violations())->toBe([]);
});

it('flags module folders that are not declared in config', function () {
    ($this->put)('Mystery/Thing.php', 'class Thing {}');

    expect(($this->checker)()->violations())->toBe(["Module folder 'Mystery' is not declared in config/modules.php"]);
});

it('treats folder names case-insensitively against config keys', function () {
    ($this->put)('Reporting/Query.php', 'use App\Modules\Sales\DTOs\Summary; use App\Modules\Core\Contracts\X;');

    expect(($this->checker)()->violations())->toBe([]);
});
