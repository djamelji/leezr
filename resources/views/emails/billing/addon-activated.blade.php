@extends('emails.layout')

@section('content')
    <h2>{{ __('email.billing.addon_activated.greeting', ['name' => $user->first_name]) }}</h2>

    <p>{{ __('email.billing.addon_activated.line1', ['module' => $moduleName]) }}</p>

    <div class="highlight">
        <strong>{{ $moduleName }}</strong>
        <br>
        <span class="amount">{{ number_format($invoice->total_amount / 100, 2) }} {{ $invoice->currency }}</span>
    </div>

    <a href="{{ url('/company/billing') }}" class="btn">
        {{ __('email.billing.addon_activated.action') }}
    </a>

    <p>{{ __('email.billing.addon_activated.line2') }}</p>
@endsection
