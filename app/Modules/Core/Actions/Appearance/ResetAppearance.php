<?php

namespace App\Modules\Core\Actions\Appearance;

use App\Modules\Core\Contracts\SettingsService;
use App\Modules\Core\Support\AppearanceOptions as O;
use Illuminate\Support\Facades\Storage;

/** Back to the standard look. The logo is removed too when asked (`$logo`); the vendor-only options only when `$vendor`. */
final class ResetAppearance
{
    public function __construct(private readonly SettingsService $settings) {}

    public function __invoke(bool $logo = true, bool $vendor = false): void
    {
        foreach (O::KEYS as $key) {
            if ($key === 'logo' || (! $vendor && in_array($key, O::VENDOR_KEYS, true))) {
                continue;
            }
            $this->settings->forget("appearance.{$key}");
        }
        if ($logo) {
            $this->removeLogo();
        }
    }

    public function removeLogo(): void
    {
        $old = $this->settings->get('appearance.logo');
        if (is_array($old)) {
            if (isset($old['path'])) {
                Storage::disk(config('core.branding.disk', 'local'))->delete($old['path']);
            }
            $this->settings->forget('appearance.logo');
        }
    }
}
