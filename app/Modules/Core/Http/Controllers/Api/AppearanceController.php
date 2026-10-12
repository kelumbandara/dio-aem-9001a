<?php

namespace App\Modules\Core\Http\Controllers\Api;

use App\Modules\Core\Actions\Appearance\ResetAppearance;
use App\Modules\Core\Actions\Appearance\SaveAppearance;
use App\Modules\Core\Actions\Appearance\SaveBrandingLogo;
use App\Modules\Core\Services\SettingsAppearance;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppearanceController extends Controller
{
    /** What apps need to draw themselves like the installation: readable by any signed-in user. */
    public function show(SettingsAppearance $appearance): JsonResponse
    {
        return response()->json(['data' => $this->payload($appearance)]);
    }

    public function update(Request $request, SaveAppearance $save, SettingsAppearance $appearance): JsonResponse
    {
        abort_unless($request->user()->can('core.appearance.manage'), 403);
        $data = $request->validate([
            'app_name' => ['nullable', 'string', 'max:60'], 'primary' => ['nullable', 'string', 'max:20'], 'sidebar' => ['nullable', 'string', 'max:10'],
            'font' => ['nullable', 'string', 'max:20'], 'font_size' => ['nullable', 'string', 'max:10'], 'radius' => ['nullable', 'string', 'max:10'],
            'login_message' => ['nullable', 'string', 'max:200'], 'document_footer' => ['nullable', 'string', 'max:200'], 'print_mono' => ['nullable', 'boolean'],
            'powered_by_enabled' => ['nullable', 'boolean'], 'powered_by_text' => ['nullable', 'string', 'max:60'],
        ]);
        $save($data, $request->user()->can('core.appearance.vendor'));

        return response()->json(['data' => $this->payload($appearance)]);
    }

    public function logo(Request $request, SaveBrandingLogo $logo, SettingsAppearance $appearance): JsonResponse
    {
        abort_unless($request->user()->can('core.appearance.manage'), 403);
        $request->validate(['logo' => ['required', 'file', 'max:'.SaveBrandingLogo::MAX_KB]]);
        $logo($request->file('logo'));

        return response()->json(['data' => $this->payload($appearance)], 201);
    }

    public function removeLogo(Request $request, ResetAppearance $reset, SettingsAppearance $appearance): JsonResponse
    {
        abort_unless($request->user()->can('core.appearance.manage'), 403);
        $reset->removeLogo();

        return response()->json(['data' => $this->payload($appearance)]);
    }

    public function reset(Request $request, ResetAppearance $reset, SettingsAppearance $appearance): JsonResponse
    {
        abort_unless($request->user()->can('core.appearance.manage'), 403);
        $reset(true, $request->user()->can('core.appearance.vendor'));

        return response()->json(['data' => $this->payload($appearance)]);
    }

    /** @return array<string, mixed> */
    private function payload(SettingsAppearance $appearance): array
    {
        $values = $appearance->all();

        return [
            'app_name' => $appearance->appName(), 'primary' => $appearance->primary(), 'sidebar' => $appearance->sidebar(),
            'font' => $values['font'], 'font_size' => $values['font_size'], 'radius' => $values['radius'],
            'login_message' => $appearance->loginMessage(), 'document_footer' => $appearance->documentFooter(), 'print_mono' => (bool) $values['print_mono'],
            'powered_by' => $appearance->poweredBy(), 'logo_url' => $appearance->logoUrl(),
        ];
    }
}
