<?php

namespace App\Modules\Core\Support;

/** What can be chosen on the Appearance screen, and the defaults. Every colour here passes the readability check. */
final class AppearanceOptions
{
    /** id => [label key suffix (core::ui.appearance.presets.<id>), brand colour] */
    public const PRESETS = [
        'indigo' => '#4f46e5',
        'blue' => '#2563eb',
        'teal' => '#0f766e',
        'green' => '#15803d',
        'orange' => '#c2410c',
        'rose' => '#be123c',
        'violet' => '#6d28d9',
        'maroon' => '#9f1239',
        'slate' => '#334155',
    ];

    /** key => CSS family placed first in the font stack (null = the device's own font) */
    public const FONTS = [
        'system' => null,
        'inter' => "'Inter Variable'",
        'noto' => "'Noto Sans Variable'",
        'opensans' => "'Open Sans Variable'",
        'roboto' => "'Roboto'",
    ];

    /** The rest of every font stack: device fonts, then the bundled Sinhala and Tamil families so those scripts look the same everywhere. */
    public const FALLBACK = "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Noto Sans Sinhala Variable', 'Noto Sans Tamil Variable', 'Noto Sans Sinhala', 'Noto Sans Tamil', 'Iskoola Pota', 'Nirmala UI', sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji'";

    /** key => root font size (everything on screen is sized in rem, so this scales the whole interface) */
    public const SIZES = ['small' => '14px', 'normal' => '16px', 'large' => '18px'];

    /** key => [radius-md, radius-lg, radius-xl, radius-2xl] ('standard' leaves the defaults) */
    public const RADII = [
        'square' => ['0', '0', '0', '0'],
        'standard' => null,
        'round' => ['0.5rem', '0.75rem', '1rem', '1.5rem'],
    ];

    public const SIDEBARS = ['dark', 'light', 'brand'];

    public const DEFAULTS = [
        'app_name' => null,
        'primary' => '#4f46e5',
        'sidebar' => 'dark',
        'font' => 'system',
        'font_size' => 'normal',
        'radius' => 'standard',
        'login_message' => null,
        'document_footer' => null,
        'print_mono' => false,
        'powered_by_enabled' => true,
        'powered_by_text' => null,
    ];

    /** Settings keys, all under "appearance.". */
    public const KEYS = ['app_name', 'primary', 'sidebar', 'font', 'font_size', 'radius', 'login_message', 'document_footer', 'print_mono', 'powered_by_enabled', 'powered_by_text', 'logo'];

    /** Only the vendor (super-admin) may change these. */
    public const VENDOR_KEYS = ['powered_by_enabled', 'powered_by_text'];
}
