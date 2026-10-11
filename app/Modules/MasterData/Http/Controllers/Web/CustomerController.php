<?php

namespace App\Modules\MasterData\Http\Controllers\Web;

use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Http\Requests\SaveCustomerRequest;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Support\InputMapper;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CustomerController extends PartyController
{
    protected function model(): string
    {
        return Customer::class;
    }

    protected function slug(): string
    {
        return 'customers';
    }

    protected function priceListType(): string
    {
        return PriceList::SALES;
    }

    public function show(Customer $customer): View
    {
        return $this->renderShow($customer);
    }

    public function edit(Customer $customer): View
    {
        return $this->renderEdit($customer);
    }

    public function store(SaveCustomerRequest $request, SaveCustomer $save, InputMapper $map): RedirectResponse
    {
        return $this->attempt(fn () => $this->saved($save($map->customer($this->normalise($request->validated())))));
    }

    public function update(SaveCustomerRequest $request, Customer $customer, SaveCustomer $save, InputMapper $map): RedirectResponse
    {
        return $this->attempt(function () use ($request, $customer, $save, $map) {
            $data = $this->normalise($request->validated());

            return $this->saved($save($map->customer($data, $customer), $customer, (int) $data['version']));
        });
    }
}
