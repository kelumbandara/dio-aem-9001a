<?php

/*
|--------------------------------------------------------------------------
| AI assistant (module AI, docs/architecture/ai-agents.md)
|--------------------------------------------------------------------------
|
| Model names, prices and limits are configuration, never code. The provider key comes from the environment only
| (ANTHROPIC_API_KEY in .env on the server); it is never stored in the database, a log or a prompt.
*/

return [
    'provider' => env('AI_PROVIDER', 'claude'),
    'model' => env('AI_MODEL', 'claude-haiku-5-5'),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 1500),

    'claude' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',
        'timeout' => (int) env('AI_TIMEOUT', 60),
    ],

    // The run loop: how many provider calls one question may take, and how much earlier conversation is sent along.
    'max_steps' => (int) env('AI_MAX_STEPS', 6),
    'history_messages' => (int) env('AI_HISTORY_MESSAGES', 12),
    'max_message_chars' => 2000,

    // Drafts the assistant may prepare (quotations, purchase orders): per question and per person per hour.
    'max_drafts_per_run' => (int) env('AI_MAX_DRAFTS_PER_RUN', 3),
    'drafts_per_hour' => (int) env('AI_DRAFTS_PER_HOUR', 10),

    // Safety: tool results are cut to this many characters per text value and this many rows per list.
    'tool_text_chars' => 120,
    'tool_rows' => 15,

    // Limits per person: questions per minute, and money per calendar month (Colombo). The company limit protects the whole bill.
    'rate_per_minute' => (int) env('AI_RATE_PER_MINUTE', 10),
    'budget_user_month_usd' => env('AI_BUDGET_USER_MONTH_USD', '5.00'),
    'budget_company_month_usd' => env('AI_BUDGET_COMPANY_MONTH_USD', '50.00'),

    // US dollars per million tokens, by model name. A model missing here is metered at the default.
    // Prices as published by the provider (prompts up to 100,000 tokens); an unknown model is metered at the dearer default.
    'pricing' => [
        'claude-haiku-5-5' => ['input' => '0.10', 'output' => '0.50'],
        'claude-sonnet-5-5' => ['input' => '2.00', 'output' => '10.00'],
        'default' => ['input' => '3.00', 'output' => '15.00'],
    ],
];
