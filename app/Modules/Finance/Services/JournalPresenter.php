<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Contracts\UserDirectory;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalEntry;

/** Builds the readable form of a journal (shared by the API and the web screens). */
final class JournalPresenter
{
    public function __construct(private readonly UserDirectory $users) {}

    /**
     * @param  iterable<JournalEntry>  $entries
     * @return list<array<string, mixed>> headers only (no lines), for lists
     */
    public function headers(iterable $entries): array
    {
        $entries = collect($entries);
        $names = $this->users->names($entries->pluck('created_by')->filter()->all());
        $related = JournalEntry::query()->whereIn('id', $entries->pluck('reverses_id')->merge($entries->pluck('reversed_by_id'))->filter()->unique()->all())->pluck('journal_no', 'id')->all();

        return $entries->map(fn (JournalEntry $e) => $this->header($e, $names, $related))->values()->all();
    }

    /** @return array<string, mixed> */
    public function full(JournalEntry $entry): array
    {
        $lines = $entry->lines()->get();
        $accounts = Account::query()->whereIn('id', $lines->pluck('account_id')->all())->get()->keyBy('id');
        $names = $this->users->names($entry->created_by ? [$entry->created_by] : []);
        $related = JournalEntry::query()->whereIn('id', array_filter([$entry->reverses_id, $entry->reversed_by_id]))->pluck('journal_no', 'id')->all();

        return $this->header($entry, $names, $related) + [
            'lines' => $lines->map(fn ($l) => [
                'line_no' => $l->line_no, 'account' => $accounts[$l->account_id]->code ?? null, 'account_name' => $accounts[$l->account_id]->name ?? null,
                'debit' => $l->debit, 'credit' => $l->credit, 'description' => $l->description, 'party_type' => $l->party_type, 'party_id' => $l->party_id,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, string>  $userNames
     * @param  array<int, string>  $journalNos
     * @return array<string, mixed>
     */
    private function header(JournalEntry $e, array $userNames, array $journalNos): array
    {
        return [
            'id' => $e->public_id, 'journal_no' => $e->journal_no, 'entry_date' => $e->entry_date->toDateString(), 'status' => $e->status, 'kind' => $e->kind,
            'description' => $e->description, 'reference' => $e->reference, 'total' => $e->total, 'source_type' => $e->source_type, 'source_id' => $e->source_id,
            'reverses' => $e->reverses_id ? ($journalNos[$e->reverses_id] ?? null) : null, 'reversed_by' => $e->reversed_by_id ? ($journalNos[$e->reversed_by_id] ?? null) : null,
            'created_by' => $e->created_by ? ($userNames[$e->created_by] ?? null) : null, 'created_at' => $e->created_at->toIso8601String(),
        ];
    }
}
