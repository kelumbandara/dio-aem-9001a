<?php

use App\Modules\Core\Models\AuditLog;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Services\CsvImporter;
use Illuminate\Http\UploadedFile;

function csv(string $content, string $name = 'data.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function importFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, $content);

    return $path;
}

beforeEach(fn () => $this->user = masterDataUser());

describe('the importer', function () {
    it('creates records from a file', function () {
        $result = app(CsvImporter::class)('items', importFile("name,category,base_uom,barcode,is_sellable\nWidget,GENERAL,PCS,1234,yes\nFabric,GENERAL,M,,no\n"), false);

        expect($result)->rows->toBe(2)->created->toBe(2)->updated->toBe(0)->committed->toBeTrue()->errors->toBe([]);
        expect(Item::orderBy('id')->pluck('name')->all())->toBe(['Widget', 'Fabric'])
            ->and(Item::where('name', 'Fabric')->value('is_sellable'))->toBeFalse()
            ->and(Item::where('name', 'Widget')->value('code'))->toStartWith('ITM-');
        expect(AuditLog::where('event', 'masterdata.import')->count())->toBe(1);
    });

    it('updates a known code and leaves blank cells alone', function () {
        $item = mdItem(['name' => 'Widget', 'barcode' => '111', 'description' => 'Keep me']);

        $result = app(CsvImporter::class)('items', importFile("code,name,barcode,description\n{$item->code},Widget Mk2,,\n"), false);

        expect($result)->created->toBe(0)->updated->toBe(1)->committed->toBeTrue();
        expect($item->fresh())->name->toBe('Widget Mk2')->barcode->toBe('111')->description->toBe('Keep me')->version->toBe(2);
    });

    it('matches codes without regard to case', function () {
        $result = app(CsvImporter::class)('items', importFile("code,name,category,base_uom\nmycode,One,GENERAL,PCS\nMYCODE2,Two,GENERAL,PCS\n"), false);
        $again = app(CsvImporter::class)('items', importFile("code,name\nMyCode,One v2\n"), false);

        expect($result['created'])->toBe(2)->and($again['updated'])->toBe(1)->and(Item::where('code', 'MYCODE')->value('name'))->toBe('One v2');
    });

    it('does a check without saving anything, then the same file imports', function () {
        $file = importFile("name,category,base_uom\nWidget,GENERAL,PCS\n");

        $check = app(CsvImporter::class)('items', $file, true);
        expect($check)->created->toBe(1)->committed->toBeFalse()->errors->toBe([])->and(Item::count())->toBe(0);
        expect(AuditLog::where('event', 'masterdata.import')->count())->toBe(0);

        expect(app(CsvImporter::class)('items', $file, false))->committed->toBeTrue();
        expect(Item::count())->toBe(1);
    });

    it('saves nothing when any row has a problem, and reports every problem with its line', function () {
        $result = app(CsvImporter::class)('items', importFile("name,category,base_uom,is_sellable\nGood,GENERAL,PCS,yes\n,GENERAL,PCS,yes\nBad unit,GENERAL,ZZZ,yes\nBad flag,GENERAL,PCS,maybe\nAlso good,GENERAL,PCS,no\n"), false);

        expect($result['committed'])->toBeFalse()->and(Item::count())->toBe(0);
        $lines = collect($result['errors'])->pluck('row', 'field')->all();
        expect($lines)->toMatchArray(['name' => 3, 'base_uom' => 4, 'is_sellable' => 5]);
    });

    it('catches a repeated code in the same file', function () {
        $result = app(CsvImporter::class)('items', importFile("code,name,category,base_uom\nX1,One,GENERAL,PCS\nx1,Two,GENERAL,PCS\n"), false);

        expect($result['errors'])->toHaveCount(1)->and($result['errors'][0])->toMatchArray(['row' => 3, 'field' => 'code'])->and(Item::count())->toBe(0);
    });

    it('applies the same business rules as the forms: locked fields cannot be changed', function () {
        $item = mdItem(['base_uom' => 'PCS']);

        $result = app(CsvImporter::class)('items', importFile("code,name,base_uom\n{$item->code},Widget,KG\n"), false);

        expect($result['committed'])->toBeFalse()->and($result['errors'][0]['row'])->toBe(2)->and($item->fresh()->base_uom_id)->toBe($item->base_uom_id);
    });

    it('rejects a file with unknown or repeated columns, or without a name column', function (string $file, string $message) {
        $result = app(CsvImporter::class)('items', importFile($file), false);

        expect($result['committed'])->toBeFalse()->and($result['errors'][0]['message'])->toContain($message)->and(Item::count())->toBe(0);
    })->with([
        'unknown column' => ["name,colour\nA,red\n", 'Unknown column'],
        'repeated column' => ["name,name\nA,B\n", 'more than once'],
        'no name' => ["code,barcode\nA,1\n", "'name' column"],
        'empty' => ['', 'empty'],
    ]);

    it('says so when there is nothing to import', function () {
        $result = app(CsvImporter::class)('items', importFile("name,category,base_uom\n\n  ,\n"), false);

        expect($result['committed'])->toBeFalse()->and($result['errors'][0]['message'])->toContain('no data rows');
    });

    it('accepts a UTF-8 byte-order mark, quoted commas and Sinhala/Tamil text', function () {
        $result = app(CsvImporter::class)('customers', importFile("\xEF\xBB\xBFname,tax_id,phone\n\"Lanka, Traders\",123,0771234567\nසිංහල වෙළඳසැල,456,\nதமிழ் கடை,789,\n"), false);

        expect($result)->created->toBe(3)->committed->toBeTrue();
        expect(Customer::orderBy('id')->pluck('name')->all())->toBe(['Lanka, Traders', 'සිංහල වෙළඳසැල', 'தமிழ் கடை']);
    });

    it('imports suppliers and customers with their own columns and code lookups', function () {
        $c = app(CsvImporter::class)('customers', importFile("name,currency,payment_terms,credit_limit\nA,LKR,NET30,50000\nB,XXX,,\n"), false);
        $s = app(CsvImporter::class)('suppliers', importFile("name,currency,lead_time_days\nA,USD,14\n"), false);

        expect($c['committed'])->toBeFalse()->and($c['errors'][0])->toMatchArray(['row' => 3, 'field' => 'currency']);
        expect($s['committed'])->toBeTrue()->and(Supplier::sole()->lead_time_days)->toBe(14);
        expect(Customer::count())->toBe(0);
    });

    it('stops at the row limit', function () {
        $rows = implode("\n", array_fill(0, CsvImporter::MAX_ROWS + 1, 'x'));
        $result = app(CsvImporter::class)('customers', importFile("name\n{$rows}\n"), true);

        expect(collect($result['errors'])->pluck('message')->implode(' '))->toContain('Too many rows');
    });
});

describe('the import screen', function () {
    it('checks a file without saving and then imports it', function () {
        $content = "name,category,base_uom\nWidget,GENERAL,PCS\n";

        $this->actingAs($this->user)->post('/masterdata/import', ['kind' => 'items', 'mode' => 'check', 'file' => csv($content)])
            ->assertOk()->assertSee('ready to import')->assertSee('1 to create');
        expect(Item::count())->toBe(0);

        $this->actingAs($this->user)->post('/masterdata/import', ['kind' => 'items', 'mode' => 'import', 'file' => csv($content)])->assertOk()->assertSee('Import complete');
        expect(Item::count())->toBe(1);
    });

    it('shows the problems and the line numbers', function () {
        $this->actingAs($this->user)->post('/masterdata/import', ['kind' => 'items', 'mode' => 'import', 'file' => csv("name,category,base_uom\nWidget,NOPE,PCS\n")])
            ->assertOk()->assertSee('The file has problems')->assertSee('Nothing was saved')->assertSee('category');
        expect(Item::count())->toBe(0);
    });

    it('offers templates and only for the kinds the person may manage', function () {
        $this->actingAs($this->user)->get('/masterdata/import/template/items')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertStreamedContent(implode(',', CsvImporter::columns('items'))."\n");

        $limited = userWithPermissions('masterdata.import', 'masterdata.item.manage');
        $this->actingAs($limited)->get('/masterdata/import')->assertOk()->assertSee('Items')->assertDontSee('value="customers"', false);
        $this->actingAs($limited)->get('/masterdata/import/template/customers')->assertForbidden();
        $this->actingAs($limited)->post('/masterdata/import', ['kind' => 'customers', 'mode' => 'import', 'file' => csv("name\nA\n")])->assertSessionHasErrors('kind');
        expect(Customer::count())->toBe(0);
    });

    it('is closed to people without the import permission', function () {
        $viewer = userWithPermissions('masterdata.item.manage');

        $this->actingAs($viewer)->get('/masterdata/import')->assertForbidden();
        $this->actingAs($viewer)->post('/masterdata/import', ['kind' => 'items', 'mode' => 'import', 'file' => csv("name\nA\n")])->assertForbidden();
    });

    it('refuses files that are not CSV or are too big', function () {
        $this->actingAs($this->user)->post('/masterdata/import', ['kind' => 'items', 'mode' => 'check', 'file' => UploadedFile::fake()->create('x.exe', 10)])->assertSessionHasErrors('file');
        $this->actingAs($this->user)->post('/masterdata/import', ['kind' => 'items', 'mode' => 'check', 'file' => UploadedFile::fake()->create('big.csv', 3000, 'text/csv')])->assertSessionHasErrors('file');
        $this->actingAs($this->user)->post('/masterdata/import', ['kind' => 'items', 'mode' => 'check'])->assertSessionHasErrors('file');
    });
});

describe('the import command', function () {
    it('checks and then imports a file, and fails loudly on problems', function () {
        $good = importFile("name,category,base_uom\nWidget,GENERAL,PCS\n");
        $bad = importFile("name,category,base_uom\nWidget,NOPE,PCS\n");

        $this->artisan('masterdata:import', ['kind' => 'items', 'file' => $good, '--check' => true])->expectsOutputToContain('nothing was saved')->assertSuccessful();
        expect(Item::count())->toBe(0);

        $this->artisan('masterdata:import', ['kind' => 'items', 'file' => $good])->expectsOutputToContain('Imported.')->assertSuccessful();
        expect(Item::count())->toBe(1);

        $this->artisan('masterdata:import', ['kind' => 'items', 'file' => $bad])->expectsOutputToContain('line 2')->expectsOutputToContain('Nothing was saved.')->assertFailed();
        $this->artisan('masterdata:import', ['kind' => 'orders', 'file' => $good])->assertExitCode(2);
        $this->artisan('masterdata:import', ['kind' => 'items', 'file' => '/no/such.csv'])->assertExitCode(2);
    });
});
