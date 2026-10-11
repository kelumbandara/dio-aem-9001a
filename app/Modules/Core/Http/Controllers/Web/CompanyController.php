<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Actions\SaveCompanyProfile;
use App\Modules\Core\Models\CompanyProfile;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless($request->user()->can('core.company.manage'), 403);

        return view('core::company.edit', ['company' => CompanyProfile::current()]);
    }

    public function update(Request $request, SaveCompanyProfile $save): RedirectResponse
    {
        abort_unless($request->user()->can('core.company.manage'), 403);
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:150'], 'trade_name' => ['nullable', 'string', 'max:150'],
            'registration_no' => ['nullable', 'string', 'max:60'], 'tax_id' => ['nullable', 'string', 'max:60'],
            'address_line1' => ['nullable', 'string', 'max:150'], 'address_line2' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'], 'country' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:150'], 'website' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $save($data);
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors([($e->details[0]['field'] ?? 'form') => $e->getMessage()]);
        }

        return redirect()->route('company.edit')->with('status', __('core::ui.company.saved'));
    }
}
