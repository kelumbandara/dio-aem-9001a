<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(config('core.locales'))]]);

        $request->session()->put('locale', $data['locale']);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $data['locale']])->save();
        }

        return back();
    }
}
