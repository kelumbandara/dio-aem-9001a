<?php

namespace App\Modules\Finance\Actions;

use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\AccountMapping;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Creates or changes an account or group. Accounts are never deleted. Once an account has postings its code and class
 * are fixed; an account that is mapped for automatic posting or still has a balance cannot be deactivated.
 */
final class SaveAccount
{
    /**
     * @param  array<string, mixed>  $data  code, name, class, parent_id, is_group, subtype, is_active, notes
     */
    public function __invoke(array $data, ?Account $account = null, ?int $expectedVersion = null): Account
    {
        return DB::transaction(function () use ($data, $account, $expectedVersion): Account {
            $creating = $account === null;

            if (! $creating) {
                $account = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                if ($expectedVersion !== null && $account->version !== $expectedVersion) {
                    throw FinanceException::stale('account');
                }
            }

            $hasPostings = ! $creating && DB::table('journal_lines')->where('account_id', $account->id)->exists();
            $code = trim((string) ($data['code'] ?? $account->code ?? ''));
            $class = (string) ($data['class'] ?? $account->class ?? '');
            $isGroup = (bool) ($data['is_group'] ?? $account->is_group ?? false);
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $account?->parent_id;
            $active = (bool) ($data['is_active'] ?? $account->is_active ?? true);

            if ($code === '' || ! preg_match('/^[A-Za-z0-9.\-]{1,12}$/', $code)) {
                throw FinanceException::make('finance.code_invalid', 'The code is 1–12 letters, digits, dots or dashes.', 422, 'code');
            }
            if (Account::query()->where('code', $code)->when(! $creating, fn ($q) => $q->whereKeyNot($account->id))->exists()) {
                throw FinanceException::make('finance.code_taken', 'This account code is already used.', 422, 'code');
            }
            if (! in_array($class, Account::CLASSES, true)) {
                throw FinanceException::make('finance.class_invalid', 'Choose asset, liability, equity, income or expense.', 422, 'class');
            }
            if ($hasPostings && ($code !== $account->code || $class !== $account->class || $isGroup !== $account->is_group)) {
                throw FinanceException::make('finance.account_in_use', 'The code, class and kind cannot change once the account has postings.', 422, 'code');
            }
            if (! $creating && $isGroup && ! $account->is_group && Account::query()->where('parent_id', $account->id)->exists()) {
                throw FinanceException::make('finance.account_invalid', 'Move the accounts under it first.', 422, 'is_group');
            }

            if ($parentId !== null) {
                $parent = Account::query()->find($parentId);
                if ($parent === null || ! $parent->is_group || $parent->class !== $class) {
                    throw FinanceException::make('finance.parent_invalid', 'The parent must be a group of the same class.', 422, 'parent');
                }
                if (! $creating && $this->isDescendant($parent, $account->id)) {
                    throw FinanceException::make('finance.parent_invalid', 'An account cannot sit under itself.', 422, 'parent');
                }
            }
            if (! $isGroup && $parentId === null) {
                throw FinanceException::make('finance.parent_invalid', 'An account belongs to a group.', 422, 'parent');
            }

            if (! $creating && $account->is_active && ! $active) {
                if (AccountMapping::query()->where('account_id', $account->id)->exists()) {
                    throw FinanceException::make('finance.account_in_use', 'This account is used for automatic posting; point that mapping at another account first.', 422, 'is_active');
                }
                if (DB::table('bank_accounts')->where('account_id', $account->id)->where('is_active', true)->exists()) {
                    throw FinanceException::make('finance.account_in_use', 'This is the account of an active bank/cash account.', 422, 'is_active');
                }
                $net = DB::table('journal_lines')->where('account_id', $account->id)->selectRaw('coalesce(sum(debit - credit), 0) as n')->value('n');
                if (Decimal::compare((string) $net, '0', 4) !== 0) {
                    throw FinanceException::make('finance.account_in_use', 'The account still has a balance; clear it before deactivating.', 422, 'is_active');
                }
            }

            $account ??= new Account;
            $account->fill([
                'code' => $code, 'name' => trim((string) ($data['name'] ?? $account->name ?? '')), 'class' => $class, 'parent_id' => $parentId,
                'is_group' => $isGroup, 'subtype' => array_key_exists('subtype', $data) ? ($data['subtype'] ?: null) : $account->subtype,
                'is_active' => $active, 'notes' => array_key_exists('notes', $data) ? $data['notes'] : $account->notes,
                'version' => $creating ? 1 : $account->version + 1,
            ]);
            if ($account->name === '' || mb_strlen($account->name) > 150) {
                throw FinanceException::make('finance.name_required', 'Name the account in up to 150 characters.', 422, 'name');
            }
            $account->save();

            return $account->refresh();
        });
    }

    private function isDescendant(Account $candidate, int $accountId): bool
    {
        for ($node = $candidate, $guard = 0; $node !== null && $guard < 20; $node = $node->parent_id ? Account::query()->find($node->parent_id) : null, $guard++) {
            if ($node->id === $accountId) {
                return true;
            }
        }

        return false;
    }
}
