<?php

namespace App\Modules\Communications;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Communications\Approvals\MessageSend;
use App\Modules\Communications\Contracts\MessageDrafter;
use App\Modules\Communications\Contracts\Messenger;
use App\Modules\Communications\Services\EloquentMessageDrafter;
use App\Modules\Core\DTOs\MenuItem;
use App\Support\ModuleServiceProvider;

class CommunicationsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MessageDrafter::class, EloquentMessageDrafter::class);
        $this->app->bind(Messenger::class, EloquentMessageDrafter::class);
    }

    protected function moduleKey(): string
    {
        return 'communications';
    }

    protected function permissions(): array
    {
        return ['comm.message.view', 'comm.message.draft', 'comm.message.send', 'comm.template.manage', 'comm.consent.manage'];
    }

    protected function menu(): array
    {
        return [
            new MenuItem('communications::ui.menu.messages', 'comm.messages.index', 'comm.message.view', 'chat', 600, group: 'communications'),
            new MenuItem('communications::ui.menu.templates', 'comm.templates.index', 'comm.message.view', 'book', 610, group: 'communications'),
            new MenuItem('communications::ui.menu.consents', 'comm.consents.index', 'comm.message.view', 'shield', 620, group: 'communications'),
            new MenuItem('communications::ui.menu.channels', 'comm.channels.index', null, 'key', 630, group: 'communications'),
        ];
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(ApprovalService::class)->registerAction(MessageSend::class);
    }
}
