<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\BusinessTool;
use App\Modules\AI\Services\Tools\ToolCatalog;
use App\Modules\Core\Contracts\ModuleRegistry;
use Illuminate\Contracts\Auth\Access\Authorizable;

/** Which tools exist for whom: the module must be on and the person must hold the tool's permission. R0 only in this sprint. */
final class ToolRegistry
{
    public function __construct(private readonly ToolCatalog $catalog, private readonly ModuleRegistry $modules) {}

    /** @return list<BusinessTool> */
    public function availableTo(Authorizable $user): array
    {
        return array_values(array_filter($this->catalog->all(), fn (BusinessTool $t) => $this->allowed($t, $user)));
    }

    /** The tool by name when the person may use it right now, else null (unknown, module off, or no permission look the same). */
    public function find(string $name, Authorizable $user): ?BusinessTool
    {
        foreach ($this->catalog->all() as $tool) {
            if ($tool->name() === $name && $this->allowed($tool, $user)) {
                return $tool;
            }
        }

        return null;
    }

    private function allowed(BusinessTool $tool, Authorizable $user): bool
    {
        return $tool->risk() === 'R0' && $this->modules->isEnabled($tool->module()) && $user->can($tool->permission());
    }
}
