<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Actions\Appearance\ResetAppearance;
use App\Modules\Core\Actions\Appearance\SaveAppearance;
use App\Modules\Core\Actions\Appearance\SaveBrandingLogo;
use App\Modules\Core\Services\SettingsAppearance;
use App\Modules\Core\Support\AppearanceOptions as O;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Administration → Appearance: name, logo, colours, font, size, corners, document footer. */
class AppearanceController extends Controller
{
    public function edit(Request $request, SettingsAppearance $appearance): View
    {
        $this->allow($request);

        return view('core::appearance.edit', [
            'values' => $appearance->all(),
            'presets' => O::PRESETS, 'fonts' => array_keys(O::FONTS), 'sizes' => array_keys(O::SIZES), 'radii' => array_keys(O::RADII), 'sidebars' => O::SIDEBARS,
            'vendor' => $request->user()->can('core.appearance.vendor'),
            'previewCss' => $appearance->themeCss(),
            'logoUrl' => $appearance->logoUrl(),
        ]);
    }

    public function update(Request $request, SaveAppearance $save, SaveBrandingLogo $logo, ResetAppearance $reset): RedirectResponse
    {
        $this->allow($request);
        $data = $request->validate([
            'app_name' => ['nullable', 'string', 'max:60'], 'primary' => ['nullable', 'string', 'max:20'], 'custom_primary' => ['nullable', 'string', 'max:20'],
            'sidebar' => ['nullable', 'string', 'max:10'], 'font' => ['nullable', 'string', 'max:20'], 'font_size' => ['nullable', 'string', 'max:10'], 'radius' => ['nullable', 'string', 'max:10'],
            'login_message' => ['nullable', 'string', 'max:200'], 'document_footer' => ['nullable', 'string', 'max:200'], 'print_mono' => ['nullable', 'boolean'],
            'powered_by_enabled' => ['nullable', 'boolean'], 'powered_by_text' => ['nullable', 'string', 'max:60'],
            'logo' => ['nullable', 'file', 'max:'.SaveBrandingLogo::MAX_KB], 'remove_logo' => ['nullable', 'boolean'],
        ]);
        // The colour picker and the ready-made swatches are two inputs for one setting: a typed custom colour wins.
        if (! empty($data['custom_primary'])) {
            $data['primary'] = $data['custom_primary'];
        }
        $data['print_mono'] = $request->boolean('print_mono');
        $vendor = $request->user()->can('core.appearance.vendor');
        if ($vendor) {
            $data['powered_by_enabled'] = $request->boolean('powered_by_enabled');
        }

        try {
            $save(array_filter($data, fn ($v, $k) => ! in_array($k, ['logo', 'remove_logo', 'custom_primary'], true) && ($v !== null || in_array($k, ['app_name', 'login_message', 'document_footer', 'powered_by_text'], true)), ARRAY_FILTER_USE_BOTH), $vendor);
            if ($request->hasFile('logo')) {
                $logo($request->file('logo'));
            } elseif ($request->boolean('remove_logo')) {
                $reset->removeLogo();
            }
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors([($e->details[0]['field'] ?? 'form') => $e->getMessage()]);
        }

        return redirect()->route('appearance.edit')->with('status', __('core::ui.appearance.saved'));
    }

    public function reset(Request $request, ResetAppearance $reset): RedirectResponse
    {
        $this->allow($request);
        $reset(true, $request->user()->can('core.appearance.vendor'));

        return redirect()->route('appearance.edit')->with('status', __('core::ui.appearance.was_reset'));
    }

    /** The CSS for what is currently typed on the form, for the live preview (nothing is saved). */
    public function preview(Request $request, SettingsAppearance $appearance): JsonResponse
    {
        $this->allow($request);
        $choices = $request->only(['primary', 'sidebar', 'font', 'font_size', 'radius']);
        $custom = (string) $request->input('custom_primary', '');
        if ($custom !== '') {
            $choices['primary'] = $custom;
        }
        if (isset($choices['primary']) && isset(O::PRESETS[$choices['primary']])) {
            $choices['primary'] = O::PRESETS[$choices['primary']];
        }

        return response()->json(['css' => $appearance->themeCssFor($choices)]);
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('core.appearance.manage'), 403);
    }
}
