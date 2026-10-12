<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\Appearance;
use App\Modules\Core\Contracts\SettingsService;
use App\Modules\Core\Support\AppearanceOptions as O;
use App\Modules\Core\Support\ColorScale;

/** Appearance read from the (cached) settings: nothing here touches the database on a normal request. */
final class SettingsAppearance implements Appearance
{
    public function __construct(private readonly SettingsService $settings) {}

    /** One setting with its default. */
    public function value(string $key): mixed
    {
        return $this->settings->get("appearance.{$key}", O::DEFAULTS[$key] ?? null);
    }

    /** @return array<string, mixed> every option with its current value (the logo as its stored description) */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(O::DEFAULTS) as $key) {
            $out[$key] = $this->value($key);
        }
        $out['logo'] = $this->logo();

        return $out;
    }

    public function appName(): string
    {
        $name = $this->text('app_name');

        return $name ?? (string) __('core::ui.app_name');
    }

    public function primary(): string
    {
        $primary = (string) $this->value('primary');

        return ColorScale::isHex($primary) ? ColorScale::normalise($primary) : O::DEFAULTS['primary'];
    }

    /** @return array{path: string, mime: string, version: int}|null */
    public function logo(): ?array
    {
        $logo = $this->settings->get('appearance.logo');

        return is_array($logo) && isset($logo['path'], $logo['mime'], $logo['version']) ? $logo : null;
    }

    public function logoUrl(): ?string
    {
        $logo = $this->logo();

        return $logo === null ? null : route('branding.logo', ['v' => $logo['version']]);
    }

    public function loginMessage(): ?string
    {
        return $this->text('login_message');
    }

    public function documentFooter(): ?string
    {
        return $this->text('document_footer');
    }

    public function poweredBy(): ?string
    {
        if (! (bool) $this->value('powered_by_enabled')) {
            return null;
        }

        return $this->text('powered_by_text') ?? 'Powered by '.config('core.vendor_name', 'DIO ERP');
    }

    public function sidebar(): string
    {
        $sidebar = (string) $this->value('sidebar');

        return in_array($sidebar, O::SIDEBARS, true) ? $sidebar : 'dark';
    }

    public function themeCss(): string
    {
        return $this->themeCssFor($this->all());
    }

    /**
     * The theme for any set of choices (the live preview uses this with what is typed, before saving).
     *
     * @param  array<string, mixed>  $choices
     */
    public function themeCssFor(array $choices): string
    {
        $primary = isset($choices['primary']) && is_string($choices['primary']) && ColorScale::isHex($choices['primary']) ? ColorScale::normalise($choices['primary']) : O::DEFAULTS['primary'];
        $scale = ColorScale::scale($primary);
        $vars = [];
        foreach ($scale as $step => $hex) {
            $vars["--color-indigo-{$step}"] = $hex;
        }

        $sidebar = in_array($choices['sidebar'] ?? 'dark', O::SIDEBARS, true) ? $choices['sidebar'] : 'dark';
        $vars += match ($sidebar) {
            'light' => ['--sidebar-bg' => '#ffffff', '--sidebar-text' => '#334155', '--sidebar-hover' => '#f1f5f9', '--sidebar-active-bg' => $scale[50], '--sidebar-active-text' => $scale[700], '--sidebar-title' => '#0f172a', '--sidebar-border' => '#e2e8f0'],
            'brand' => ['--sidebar-bg' => $scale[900], '--sidebar-text' => $scale[100], '--sidebar-hover' => $scale[800], '--sidebar-active-bg' => $scale[700], '--sidebar-active-text' => '#ffffff', '--sidebar-title' => '#ffffff', '--sidebar-border' => 'transparent'],
            default => ['--sidebar-bg' => '#0f172a', '--sidebar-text' => '#cbd5e1', '--sidebar-hover' => '#1e293b', '--sidebar-active-bg' => '#1e293b', '--sidebar-active-text' => '#ffffff', '--sidebar-title' => '#ffffff', '--sidebar-border' => 'transparent'],
        };

        $radius = O::RADII[$choices['radius'] ?? 'standard'] ?? null;
        if ($radius !== null) {
            $vars += ['--radius-md' => $radius[0], '--radius-lg' => $radius[1], '--radius-xl' => $radius[2], '--radius-2xl' => $radius[3]];
        }

        $vars['--font-sans'] = $this->fontStack((string) ($choices['font'] ?? 'system'));

        $lines = [];
        foreach ($vars as $name => $value) {
            $lines[] = "{$name}: {$value};";
        }
        $size = O::SIZES[$choices['font_size'] ?? 'normal'] ?? O::SIZES['normal'];

        return ':root { '.implode(' ', $lines)." }\nhtml { font-size: {$size}; }";
    }

    public function documentCss(): string
    {
        $mono = (bool) $this->value('print_mono');
        $scale = ColorScale::scale($this->primary());
        $accent = $mono ? '#111111' : $scale[600];
        $soft = $mono ? '#f1f1f1' : $scale[50];
        $size = O::SIZES[(string) $this->value('font_size')] ?? O::SIZES['normal'];

        return ":root { --accent: {$accent}; --accent-soft: {$soft}; --font: {$this->fontStack((string) $this->value('font'))}; }\n"
            ."html { font-size: {$size}; }";
    }

    public function fontStack(string $font): string
    {
        $first = O::FONTS[$font] ?? null;

        return $first === null ? O::FALLBACK : $first.', '.O::FALLBACK;
    }

    private function text(string $key): ?string
    {
        $value = $this->value($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
