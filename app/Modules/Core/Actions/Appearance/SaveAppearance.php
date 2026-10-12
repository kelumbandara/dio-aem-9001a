<?php

namespace App\Modules\Core\Actions\Appearance;

use App\Modules\Core\Contracts\SettingsService;
use App\Modules\Core\Support\AppearanceOptions as O;
use App\Modules\Core\Support\ColorScale;
use App\Support\Exceptions\BusinessException;

/**
 * Saves the look of this installation (Administration → Appearance and PATCH /api/v1/appearance). Everything is
 * checked here, not in the form: unknown choices are refused, and a brand colour must be readable with white text
 * (buttons and links are drawn in it). Only keys that are present are changed; the vendor-only options (the
 * "Powered by" line) are ignored unless `$vendor` is true.
 */
final class SaveAppearance
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  array<string, mixed>  $data  app_name, primary (hex or preset id), sidebar, font, font_size, radius, login_message, document_footer, print_mono, powered_by_enabled, powered_by_text
     * @return list<string> the keys that were changed
     *
     * @throws BusinessException core.appearance_invalid
     */
    public function __invoke(array $data, bool $vendor = false): array
    {
        $clean = [];

        if (array_key_exists('app_name', $data)) {
            $clean['app_name'] = $this->text($data['app_name'], 60, 'app_name');
        }
        foreach (['login_message', 'document_footer'] as $key) {
            if (array_key_exists($key, $data)) {
                $clean[$key] = $this->text($data[$key], 200, $key);
            }
        }
        if (array_key_exists('primary', $data)) {
            $clean['primary'] = $this->colour((string) $data['primary']);
        }
        foreach (['sidebar' => O::SIDEBARS, 'font' => array_keys(O::FONTS), 'font_size' => array_keys(O::SIZES), 'radius' => array_keys(O::RADII)] as $key => $allowed) {
            if (array_key_exists($key, $data)) {
                $value = (string) $data[$key];
                if (! in_array($value, $allowed, true)) {
                    throw $this->invalid($key, 'Choose one of: '.implode(', ', $allowed).'.');
                }
                $clean[$key] = $value;
            }
        }
        if (array_key_exists('print_mono', $data)) {
            $clean['print_mono'] = filter_var($data['print_mono'], FILTER_VALIDATE_BOOLEAN);
        }
        if ($vendor) {
            if (array_key_exists('powered_by_enabled', $data)) {
                $clean['powered_by_enabled'] = filter_var($data['powered_by_enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            if (array_key_exists('powered_by_text', $data)) {
                $clean['powered_by_text'] = $this->text($data['powered_by_text'], 60, 'powered_by_text');
            }
        }

        $changed = [];
        foreach ($clean as $key => $value) {
            $current = $this->settings->get("appearance.{$key}", O::DEFAULTS[$key] ?? null);
            if ($value === null || $value === (O::DEFAULTS[$key] ?? null)) {
                if (! $this->isDefault($key, $current)) {
                    $this->settings->forget("appearance.{$key}");
                    $changed[] = $key;
                }

                continue;
            }
            if ($current !== $value) {
                $this->settings->set("appearance.{$key}", $value);
                $changed[] = $key;
            }
        }

        return $changed;
    }

    private function isDefault(string $key, mixed $current): bool
    {
        return $current === (O::DEFAULTS[$key] ?? null);
    }

    private function text(mixed $value, int $max, string $field): ?string
    {
        $text = $value === null ? '' : trim((string) $value);
        if (mb_strlen($text) > $max) {
            throw $this->invalid($field, "At most {$max} characters.");
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) === 1) {
            throw $this->invalid($field, 'Contains characters that cannot be shown.');
        }

        return $text === '' ? null : $text;
    }

    private function colour(string $value): string
    {
        $value = trim($value);
        $hex = O::PRESETS[strtolower($value)] ?? $value;
        if (! ColorScale::isHex($hex)) {
            throw $this->invalid('primary', 'Choose one of the ready-made colours or enter a colour like #1d4ed8.');
        }
        $hex = ColorScale::normalise($hex);
        if (! ColorScale::readableOnWhite($hex)) {
            throw $this->invalid('primary', sprintf('That colour is too light for buttons with white text (contrast %.1f, at least %.1f needed). Choose a darker one.', ColorScale::contrast($hex, '#ffffff'), ColorScale::MIN_CONTRAST));
        }

        return $hex;
    }

    private function invalid(string $field, string $message): BusinessException
    {
        return new BusinessException('core.appearance_invalid', $message, 422, [['field' => $field, 'message' => $message]]);
    }
}
