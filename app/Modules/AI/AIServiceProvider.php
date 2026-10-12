<?php

namespace App\Modules\AI;

use App\Modules\AI\Contracts\AiProvider;
use App\Modules\AI\Providers\ClaudeProvider;
use App\Modules\Core\DTOs\MenuItem;
use App\Support\ModuleServiceProvider;

class AIServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // The provider is chosen by configuration only; tests bind their own scripted double over this.
        $this->app->bind(AiProvider::class, ClaudeProvider::class);
    }

    protected function moduleKey(): string
    {
        return 'ai';
    }

    protected function permissions(): array
    {
        return ['ai.use', 'ai.usage.view'];
    }

    protected function menu(): array
    {
        return [
            new MenuItem('ai::ui.menu.assistant', 'ai.chat.index', 'ai.use', 'chat', 10, group: 'main'),
            new MenuItem('ai::ui.menu.usage', 'ai.usage', 'ai.usage.view', 'chart', 20, group: 'admin'),
        ];
    }
}
