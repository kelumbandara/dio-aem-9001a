<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\DTOs\JournalRequest;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Account;

/**
 * Turns request/form input — accounts given by code — into a JournalRequest. Shared by the API and the web forms.
 * Blank cells mean "not given"; rows left completely empty are not lines.
 */
final class JournalInput
{
    /** @param  array<string, mixed>  $in */
    public function request(array $in, ?int $by = null): JournalRequest
    {
        $lines = [];
        foreach (array_values((array) ($in['lines'] ?? [])) as $i => $row) {
            $row = (array) $row;
            $code = trim((string) ($row['account'] ?? ''));
            $debit = trim((string) ($row['debit'] ?? ''));
            $credit = trim((string) ($row['credit'] ?? ''));
            if ($code === '' && $debit === '' && $credit === '') {
                continue;
            }

            $account = Account::query()->where('code', $code)->first()
                ?? throw FinanceException::make('finance.unknown_code', "Unknown account code '{$code}'.", 422, "lines.{$i}.account");
            $lines[] = new JournalLineData(
                accountId: $account->id, debit: $debit === '' ? '0' : $debit, credit: $credit === '' ? '0' : $credit,
                description: ($row['description'] ?? '') !== '' ? (string) $row['description'] : null,
            );
        }

        return new JournalRequest(
            entryDate: (string) (($in['entry_date'] ?? '') !== '' ? $in['entry_date'] : now((string) config('core.display_timezone'))->toDateString()),
            description: (string) ($in['description'] ?? ''),
            lines: $lines,
            reference: ($in['reference'] ?? '') !== '' ? (string) $in['reference'] : null,
            kind: 'manual',
            createdBy: $by,
        );
    }
}
