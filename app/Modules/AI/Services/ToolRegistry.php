<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\BusinessTool;
use App\Modules\AI\Services\Tools\ToolCatalog;
use App\Modules\Core\Contracts\ModuleRegistry;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Which tools exist for whom. A tool is available only if the module is on, the person holds the tool's permission and — for the
 * agent being used — the agent's list allows it. Reading (R0) is open to any agent whose list allows it (or has no list); drafting
 * (R1) only to an agent that names the tool explicitly; nothing above R1 (change, commit, forbidden) is ever available.
 */
final class ToolRegistry
{
    public function __construct(private readonly ToolCatalog $catalog, private readonly ModuleRegistry $modules) {}

    /**
     * @param  list<string>|null  $allow  the agent's tool list; null = every read tool
     * @return list<BusinessTool>
     */
    public function availableTo(Authorizable $user, ?array $allow = null): array
    {
        return array_values(array_filter($this->catalog->all(), fn (BusinessTool $t) => $this->allowed($t, $user, $allow)));
    }

    /**
     * The tool by name when the person may use it right now, else null (unknown, module off, no permission, not this agent's: all look the same).
     *
     * @param  list<string>|null  $allow
     */
    public function find(string $name, Authorizable $user, ?array $allow = null): ?BusinessTool
    {
        foreach ($this->catalog->all() as $tool) {
            if ($tool->name() === $name && $this->allowed($tool, $user, $allow)) {
                return $tool;
            }
        }

        return null;
    }

    /** @param  list<string>|null  $allow */
    private function allowed(BusinessTool $tool, Authorizable $user, ?array $allow): bool
    {
        $risk = match ($tool->risk()) {
            'R0' => $allow === null || in_array($tool->name(), $allow, true),
            'R1' => $allow !== null && in_array($tool->name(), $allow, true),
            default => false,
        };

        return $risk && $this->modules->isEnabled($tool->module()) && $user->can($tool->permission());
    }
}
