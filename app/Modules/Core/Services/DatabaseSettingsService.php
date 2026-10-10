<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\SettingsService;
use App\Modules\Core\Models\Setting;
use Illuminate\Support\Facades\Cache;

final class DatabaseSettingsService implements SettingsService
{
    private const CACHE_KEY = 'core.settings';

    public function __construct(private readonly AuditLogger $audit) {}

    public function get(string $key, mixed $default = null, string $scope = self::SCOPE_GLOBAL, int $scopeId = 0): mixed
    {
        $all = $this->all();

        foreach ([[$scope, $scopeId], [self::SCOPE_GLOBAL, 0]] as [$s, $id]) {
            $slot = $this->slot($key, $s, $id);
            if (array_key_exists($slot, $all)) {
                return $all[$slot];
            }
        }

        // Keys contain dots, so look them up directly instead of as a nested config path.
        return config('core.settings_defaults', [])[$key] ?? $default;
    }

    public function set(string $key, mixed $value, string $scope = self::SCOPE_GLOBAL, int $scopeId = 0): void
    {
        $setting = Setting::query()->firstOrNew(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId]);
        $old = $setting->exists ? $setting->value : null;
        $setting->value = $value;
        $setting->save();

        $this->audit->record('setting.updated', null, ['value' => $old], ['value' => $value], [
            'key' => $key, 'scope' => $scope, 'scope_id' => $scopeId,
        ]);
        Cache::forget(self::CACHE_KEY);
    }

    public function forget(string $key, string $scope = self::SCOPE_GLOBAL, int $scopeId = 0): void
    {
        $setting = Setting::query()->where(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId])->first();
        if ($setting === null) {
            return;
        }

        $setting->delete();
        $this->audit->record('setting.removed', null, ['value' => $setting->value], [], [
            'key' => $key, 'scope' => $scope, 'scope_id' => $scopeId,
        ]);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $map = [];
            foreach (Setting::query()->get() as $row) {
                $map[$this->slot($row->key, $row->scope, $row->scope_id)] = $row->value;
            }

            return $map;
        });
    }

    private function slot(string $key, string $scope, int $scopeId): string
    {
        return "{$scope}|{$scopeId}|{$key}";
    }
}
