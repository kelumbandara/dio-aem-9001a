<?php

namespace App\Modules\Core\Contracts;

/**
 * How this installation looks: name, logo, colours, font. Read by the page layouts and by the printed documents of
 * every module (invoices, purchase orders…), so one setting restyles everything. Edited on Administration → Appearance.
 */
interface Appearance
{
    /** The name shown in the sidebar, page titles and printed documents. */
    public function appName(): string;

    /** Address of the logo (cache-busted), or null when none was uploaded. */
    public function logoUrl(): ?string;

    public function loginMessage(): ?string;

    /** A line printed at the foot of invoices, purchase orders and other documents. */
    public function documentFooter(): ?string;

    /** "Powered by …" text, or null when switched off. */
    public function poweredBy(): ?string;

    /** Sidebar look: dark | light | brand. */
    public function sidebar(): string;

    /**
     * Body of a <style> block for the app screens: the colour scale, corner radius, font family, base text size and
     * sidebar colours as CSS variables on :root.
     */
    public function themeCss(): string;

    /**
     * Body of a <style> block for printed documents: --accent (and shades), --font and the page text size, in colour or
     * in black and white as set.
     */
    public function documentCss(): string;
}
