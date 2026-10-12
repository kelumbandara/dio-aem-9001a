<?php

namespace App\Modules\Core\Http\Controllers\Api;

use App\Modules\Core\Contracts\Appearance;
use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\Models\MobileDevice;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** What the phone app needs besides the business endpoints: its settings, and the phones that receive push messages. */
class MobileController extends Controller
{
    /** The company, which modules are on, the oldest app version still accepted, and the languages. */
    public function config(Request $request, Appearance $look, ModuleRegistry $modules): JsonResponse
    {
        $enabled = [];
        foreach ($modules->all() as $key => $definition) {
            if ($modules->isEnabled((string) $key)) {
                $enabled[] = (string) $key;
            }
        }

        return response()->json(['data' => [
            'company' => $look->appName(), 'logo_url' => $look->logoUrl(), 'modules' => $enabled,
            'min_app_version' => (string) config('mobile.min_app_version'), 'locales' => ['en', 'si', 'ta'],
            'server_time' => now()->toIso8601String(),
        ]]);
    }

    /** Register (or refresh) this phone's push address; the same address again only updates the record. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'push_token' => ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[[A-Za-z0-9_\-]{10,}\]$/D'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'locale' => ['nullable', Rule::in(['en', 'si', 'ta'])],
        ]);
        $user = $request->user();

        $device = MobileDevice::query()->updateOrCreate(
            ['user_id' => $user->id, 'push_token' => $data['push_token']],
            ['token_id' => $user->currentAccessToken()->id ?? null, 'platform' => $data['platform'], 'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null, 'locale' => $data['locale'] ?? null, 'last_seen_at' => now()],
        );

        // keep the newest few phones: an old, forgotten device stops receiving messages
        $keep = MobileDevice::query()->where('user_id', $user->id)->orderByDesc('last_seen_at')->orderByDesc('id')->limit((int) config('mobile.push.max_devices_per_user'))->pluck('id');
        MobileDevice::query()->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();

        return response()->json(['data' => ['id' => $device->public_id]], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function forget(Request $request, string $device): JsonResponse
    {
        MobileDevice::query()->where('user_id', $request->user()->id)->where('public_id', $device)->firstOrFail()->delete();

        return response()->json(null, 204);
    }
}
