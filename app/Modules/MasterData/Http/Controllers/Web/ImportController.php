<?php

namespace App\Modules\MasterData\Http\Controllers\Web;

use App\Modules\MasterData\Services\CsvImporter;
use App\Support\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    /** Permission needed to import each kind, on top of masterdata.import. */
    private const MANAGE = ['items' => 'masterdata.item.manage', 'customers' => 'masterdata.customer.manage', 'suppliers' => 'masterdata.supplier.manage'];

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('masterdata.import'), 403);

        return view('masterdata::import.index', ['kinds' => $this->allowedKinds($request), 'result' => null, 'columns' => [
            'items' => CsvImporter::columns('items'), 'customers' => CsvImporter::columns('customers'), 'suppliers' => CsvImporter::columns('suppliers'),
        ]]);
    }

    public function store(Request $request, CsvImporter $import): View
    {
        abort_unless($request->user()->can('masterdata.import'), 403);
        $kinds = $this->allowedKinds($request);

        $data = $request->validate([
            'kind' => ['required', Rule::in($kinds)],
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
            'mode' => ['required', 'in:check,import'],
        ]);

        $result = $import($data['kind'], $request->file('file')->getRealPath(), $data['mode'] === 'check');

        return view('masterdata::import.index', ['kinds' => $kinds, 'result' => $result, 'columns' => [
            'items' => CsvImporter::columns('items'), 'customers' => CsvImporter::columns('customers'), 'suppliers' => CsvImporter::columns('suppliers'),
        ]]);
    }

    public function template(Request $request, string $kind): StreamedResponse
    {
        abort_unless($request->user()->can('masterdata.import') && in_array($kind, $this->allowedKinds($request), true), 403);

        return response()->streamDownload(function () use ($kind) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, CsvImporter::columns($kind), ',', '"', '');
            fclose($out);
        }, "{$kind}-template.csv", ['Content-Type' => 'text/csv']);
    }

    /** @return list<string> */
    private function allowedKinds(Request $request): array
    {
        return array_values(array_filter(CsvImporter::KINDS, fn ($k) => Gate::forUser($request->user())->check(self::MANAGE[$k])));
    }
}
