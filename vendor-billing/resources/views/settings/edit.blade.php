@extends('layouts.app')
@section('title', 'Settings')
@section('content')
    <h1>Settings</h1>
    <form class="card" method="post" action="{{ route('settings.update') }}">
        @csrf @method('PUT')
        <h2 style="margin-top:0">Plan</h2>
        <div class="row">
            <div><label for="plan_name">Plan name</label><input id="plan_name" name="plan_name" value="{{ old('plan_name', $s['plan_name']) }}" required></div>
            <div><label for="monthly_price">Monthly price, LKR excluding VAT</label><input id="monthly_price" name="monthly_price" value="{{ old('monthly_price', $s['monthly_price']) }}" inputmode="decimal" required></div>
            <div><label for="vat_rate">VAT rate %</label><input id="vat_rate" name="vat_rate" value="{{ old('vat_rate', $s['vat_rate']) }}" inputmode="decimal" required></div>
            <div><label for="trial_days">Free trial (days)</label><input id="trial_days" name="trial_days" value="{{ old('trial_days', $s['trial_days']) }}" required></div>
            <div><label for="invoice_lead_days">Issue each invoice this many days before the period</label><input id="invoice_lead_days" name="invoice_lead_days" value="{{ old('invoice_lead_days', $s['invoice_lead_days']) }}" required></div>
            <div><label for="max_users">User limit on the licence (empty = unlimited)</label><input id="max_users" name="max_users" value="{{ old('max_users', $s['max_users']) }}"></div>
        </div>
        <h2>Your company (printed on invoices)</h2>
        <div class="row">
            <div><label for="issuer_name">Company name</label><input id="issuer_name" name="issuer_name" value="{{ old('issuer_name', $s['issuer_name']) }}" required></div>
            <div><label for="issuer_vat_number">Your VAT registration number</label><input id="issuer_vat_number" name="issuer_vat_number" value="{{ old('issuer_vat_number', $s['issuer_vat_number']) }}"></div>
            <div><label for="issuer_email">E-mail</label><input id="issuer_email" name="issuer_email" value="{{ old('issuer_email', $s['issuer_email']) }}"></div>
            <div><label for="issuer_phone">Phone</label><input id="issuer_phone" name="issuer_phone" value="{{ old('issuer_phone', $s['issuer_phone']) }}"></div>
        </div>
        <label for="issuer_address">Address</label><textarea id="issuer_address" name="issuer_address">{{ old('issuer_address', $s['issuer_address']) }}</textarea>
        <label for="bank_details">Bank details for transfers</label><textarea id="bank_details" name="bank_details">{{ old('bank_details', $s['bank_details']) }}</textarea>
        <label for="invoice_footer">Invoice footer</label><input id="invoice_footer" name="invoice_footer" value="{{ old('invoice_footer', $s['invoice_footer']) }}">
        <p><button type="submit">Save</button></p>
    </form>
    <div class="card muted">The licence signing key and the PayHere merchant secret are not set here: they live only in this server's environment file.</div>
@endsection
