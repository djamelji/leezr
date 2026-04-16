@extends('emails.layout')

@section('content')
    <h2>{{ __('email.billing.payment_received.greeting', ['name' => $user->first_name]) }}</h2>

    <p>{{ __('email.billing.payment_received.line1') }}</p>

    <div class="highlight">
        <div class="amount">{{ number_format($invoice->total_amount / 100, 2) }} {{ $invoice->currency }}</div>
        <p style="margin:4px 0 0"><strong>{{ __('email.billing.payment_received.invoice') }}</strong> #{{ $invoice->number }}</p>
    </div>

    <a href="{{ url('/company/billing') }}" class="btn">
        {{ __('email.billing.payment_received.action') }}
    </a>

    <p>{{ __('email.billing.payment_received.line2') }}</p>
@endsection
