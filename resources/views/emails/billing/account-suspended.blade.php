@extends('emails.layout')

@section('content')
    <h2>{{ __('email.billing.account_suspended.greeting', ['name' => $user->first_name]) }}</h2>

    <p>{{ __('email.billing.account_suspended.line1') }}</p>

    <div class="highlight">
        <span class="badge badge-error">{{ __('email.billing.account_suspended.suspended') }}</span>
        <p style="margin:8px 0 0">{{ __('email.billing.account_suspended.line2') }}</p>
    </div>

    <a href="{{ url('/company/billing') }}" class="btn">
        {{ __('email.billing.account_suspended.action') }}
    </a>

    <p>{{ __('email.billing.account_suspended.line3') }}</p>
@endsection
