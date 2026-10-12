<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Exceptions\AiException;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** What the assistant costs: tokens turned into money, a limit on questions per minute, and monthly budgets per person and for the company. */
final class UsageMeter
{
    /** Cost in US dollars (6 places) of a call. */
    public function cost(string $model, int $input, int $output): string
    {
        $prices = (array) config('ai.pricing');
        $price = (array) ($prices[$model] ?? $prices['default']);
        $in = Decimal::div(Decimal::mul((string) $input, (string) $price['input']), '1000000');
        $out = Decimal::div(Decimal::mul((string) $output, (string) $price['output']), '1000000');

        return Decimal::round(Decimal::add($in, $out), 6);
    }

    /**
     * @throws AiException ai.rate_limited (429) or ai.budget_exceeded (429)
     */
    public function assertAllowed(int $userId): void
    {
        $recent = DB::table('ai_executions')->where('user_id', $userId)->whereNotIn('status', ['rate_limited', 'budget'])
            ->where('created_at', '>', CarbonImmutable::now('UTC')->subMinute())->count();
        if ($recent >= (int) config('ai.rate_per_minute')) {
            throw AiException::make('ai.rate_limited', 'You are asking very quickly. Please wait a minute and try again.', 429);
        }

        if (Decimal::compare($this->spent($userId), (string) config('ai.budget_user_month_usd'), 6) >= 0) {
            throw AiException::make('ai.budget_exceeded', 'Your monthly limit for the AI assistant has been reached. It resets next month, or ask an administrator to raise it.', 429);
        }
        if (Decimal::compare($this->spent(null), (string) config('ai.budget_company_month_usd'), 6) >= 0) {
            throw AiException::make('ai.budget_exceeded', 'The company\'s monthly limit for the AI assistant has been reached. Ask an administrator to raise it.', 429);
        }
    }

    /** US dollars spent this Colombo calendar month by one person, or by everyone when $userId is null. */
    public function spent(?int $userId): string
    {
        $start = CarbonImmutable::now(config('core.display_timezone'))->startOfMonth()->utc();
        $q = DB::table('ai_executions')->where('created_at', '>=', $start);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return Decimal::round((string) $q->sum('cost_usd'), 6);
    }

    /**
     * Usage this month, per person, most expensive first.
     *
     * @return list<array{user_id: int, name: string, questions: int, input_tokens: int, output_tokens: int, cost_usd: string}>
     */
    public function byUser(): array
    {
        $start = CarbonImmutable::now(config('core.display_timezone'))->startOfMonth()->utc();
        $rows = DB::table('ai_executions as e')->join('users as u', 'u.id', '=', 'e.user_id')->where('e.created_at', '>=', $start)
            ->groupBy('e.user_id', 'u.name')->orderByRaw('SUM(e.cost_usd) DESC')->orderBy('e.user_id')
            ->selectRaw("e.user_id, u.name, COUNT(*) FILTER (WHERE e.status IN ('ok','max_steps')) AS questions, SUM(e.input_tokens) AS i, SUM(e.output_tokens) AS o, SUM(e.cost_usd) AS c")->get();

        return $rows->map(fn ($r) => ['user_id' => (int) $r->user_id, 'name' => (string) $r->name, 'questions' => (int) $r->questions, 'input_tokens' => (int) $r->i, 'output_tokens' => (int) $r->o, 'cost_usd' => Decimal::round((string) $r->c, 6)])->all();
    }
}
