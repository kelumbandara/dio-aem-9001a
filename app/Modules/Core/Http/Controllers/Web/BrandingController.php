<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Services\SettingsAppearance;
use App\Support\Http\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/** Serves the company logo (public: the sign-in page shows it). The address carries a version, so browsers keep it for a year. */
class BrandingController extends Controller
{
    public function logo(SettingsAppearance $appearance): Response
    {
        $logo = $appearance->logo();
        $disk = Storage::disk(config('core.branding.disk', 'local'));
        abort_if($logo === null || ! $disk->exists($logo['path']), 404);

        return response($disk->get($logo['path']), 200, [
            'Content-Type' => $logo['mime'],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
