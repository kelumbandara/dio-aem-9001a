<?php

namespace App\Modules\Core\DTOs;

final readonly class MenuItem
{
    /**
     * @param  string  $labelKey  translation key, e.g. core::menu.dashboard
     * @param  string  $route  named web route
     * @param  string|null  $permission  permission required to see the item (null = any authenticated user)
     */
    public function __construct(
        public string $labelKey,
        public string $route,
        public ?string $permission = null,
        public string $icon = 'circle',
        public int $order = 100,
        public string $module = '',
    ) {}

    public function forModule(string $module): self
    {
        return new self($this->labelKey, $this->route, $this->permission, $this->icon, $this->order, $module);
    }
}
