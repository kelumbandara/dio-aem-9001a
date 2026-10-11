<?php

namespace App\Modules\MasterData\Console;

use App\Modules\MasterData\Services\CsvImporter;
use Illuminate\Console\Command;

class ImportCsv extends Command
{
    protected $signature = 'masterdata:import {kind : items, customers or suppliers} {file : path to a CSV file} {--check : validate only; nothing is saved}';

    protected $description = 'Import items, customers or suppliers from a CSV file (all or nothing)';

    public function handle(CsvImporter $import): int
    {
        $kind = (string) $this->argument('kind');
        $file = (string) $this->argument('file');

        if (! in_array($kind, CsvImporter::KINDS, true)) {
            $this->error('Kind must be one of: '.implode(', ', CsvImporter::KINDS).'.');

            return self::INVALID;
        }
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::INVALID;
        }

        $result = $import($kind, $file, (bool) $this->option('check'));

        foreach ($result['errors'] as $error) {
            $this->line("line {$error['row']}".($error['field'] ? " [{$error['field']}]" : '').": {$error['message']}");
        }
        $this->info("{$result['rows']} row(s): {$result['created']} to create, {$result['updated']} to update.");

        if ($result['errors'] !== []) {
            $this->error('Nothing was saved.');

            return self::FAILURE;
        }

        $this->info($result['committed'] ? 'Imported.' : 'Check passed; nothing was saved.');

        return self::SUCCESS;
    }
}
