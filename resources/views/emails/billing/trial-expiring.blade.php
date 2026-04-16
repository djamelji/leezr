@extends('emails.layout')

@section('content')
    <h2>{{ __('email.billing.trial_expiring.greeting', ['name' => $user->first_name]) }}</h2>

    <p>{{ __('email.billing.trial_expiring.line1', ['date' => $subscription->trial_ends_at?->format('d/m/Y')]) }}</p>

    <div class="highlight">
        <strong>{{ __('email.billing.trial_expiring.days_left') }}</strong>
        {{ $subscription->trial_ends_at?->diffInDays(now()) }} {{ __('email.billing.trial_expiring.days') }}
    </div>

    <p>{{ __('email.billing.trial_expiring.line2') }}</p>

    <a href="{{ url('/company/billing') }}" class="btn">
        {{ __('email.billing.trial_expiring.action') }}
    </a>

    <p>{{ __('email.billing.trial_expiring.line3') }}</p>
@endsection
