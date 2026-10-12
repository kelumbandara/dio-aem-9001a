<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\Activity;
use App\Services\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit(Settings $settings): View
    {
        return view('settings.edit', ['s' => $settings->all()]);
    }

    public function update(Request $request, Settings $settings, Activity $activity): RedirectResponse
    {
        $data = $request->validate([
            'plan_name' => 'required|string|max:60',
            'monthly_price' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/', 'gt:0'],
            'vat_rate' => ['required', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'trial_days' => 'required|integer|min:0|max:365',
            'invoice_lead_days' => 'required|integer|min:0|max:60',
            'max_users' => 'nullable|integer|min:1|max:100000',
            'issuer_name' => 'required|string|max:255',
            'issuer_address' => 'nullable|string|max:1000',
            'issuer_vat_number' => 'nullable|string|max:40',
            'issuer_email' => 'nullable|email|max:255',
            'issuer_phone' => 'nullable|string|max:40',
            'bank_details' => 'nullable|string|max:1000',
            'invoice_footer' => 'nullable|string|max:500',
        ]);

        $before = $settings->all();
        $settings->save(array_map(fn ($v) => (string) $v, $data));
        $changed = array_keys(array_filter($data, fn ($v, $k) => (string) $v !== ($before[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
        $activity->log('settings.updated', null, ['changed' => $changed], $request->user()->id);

        return back()->with('status', 'Saved. A new price applies to customers added from now on; existing subscriptions keep the price they started with.');
    }

    public function activity(): View
    {
        return view('settings.activity', ['entries' => ActivityLog::query()->with('user')->orderByDesc('id')->paginate(50)]);
    }
}
