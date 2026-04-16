@extends('emails.layout')

@section('content')
    <h2>{{ __('email.billing.payment_failed.greeting', ['name' => $user->first_name]) }}</h2>

    <p>{{ __('email.billing.payment_failed.line1', ['amount' => number_format($invoice->total_amount / 100, 2), 'currency' => $invoice->currency]) }}</p>

    <div class="highlight">
        <span class="badge badge-error">{{ __('email.billing.payment_failed.failed') }}</span>
        <p style="margin:8px 0 0"><strong>{{ __('email.billing.payment_failed.invoice') }}</strong> #{{ $invoice->number }}</p>
    </div>

    <p>{{ __('email.billing.payment_failed.line2') }}</p>

    <a href="{{ url('/company/billing') }}" class="btn">
        {{ __('email.billing.payment_failed.action') }}
    </a>

    <p>{{ __('email.billing.payment_failed.line3') }}</p>
@endsection
