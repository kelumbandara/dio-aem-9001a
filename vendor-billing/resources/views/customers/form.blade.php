@extends('layouts.app')
@section('title', $customer->exists ? 'Edit customer' : 'New customer')
@section('content')
    <h1>{{ $customer->exists ? 'Edit '.$customer->name : 'New customer' }}</h1>
    <form class="card" method="post" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">
        @csrf
        @if ($customer->exists) @method('PUT') @endif
        <div class="row">
            <div><label for="name">Company name</label><input id="name" name="name" value="{{ old('name', $customer->name) }}" required></div>
            <div><label for="email">Billing e-mail (invoices and licences go here)</label><input id="email" type="email" name="email" value="{{ old('email', $customer->email) }}" required></div>
            <div><label for="contact_name">Contact person</label><input id="contact_name" name="contact_name" value="{{ old('contact_name', $customer->contact_name) }}"></div>
            <div><label for="phone">Phone</label><input id="phone" name="phone" value="{{ old('phone', $customer->phone) }}"></div>
            <div><label for="vat_number">VAT / TIN number</label><input id="vat_number" name="vat_number" value="{{ old('vat_number', $customer->vat_number) }}"></div>
            <div><label for="installation_id">Installation ID (from their Administration → Licence screen)</label><input id="installation_id" name="installation_id" value="{{ old('installation_id', $customer->installation_id) }}" placeholder="inst_…"></div>
            @unless ($customer->exists)
                <div><label for="started_on">Trial starts on (leave empty for today)</label><input id="started_on" type="date" name="started_on" value="{{ old('started_on') }}"></div>
            @endunless
        </div>
        <label for="address">Address</label><textarea id="address" name="address">{{ old('address', $customer->address) }}</textarea>
        <p><button type="submit">Save</button> <a class="btn alt" href="{{ $customer->exists ? route('customers.show', $customer) : route('customers.index') }}">Cancel</a></p>
    </form>
@endsection
