@extends('emails.layout')

@section('content')
    <h2>{{ __('email.billing.trial_started.greeting', ['name' => $user->first_name]) }}</h2>

    <p>{{ __('email.billing.trial_started.line1', ['days' => $subscription->trial_ends_at?->diffInDays(now()) ?? 14]) }}</p>

    <div class="highlight">
        <strong>{{ __('email.billing.trial_started.trial_ends') }}</strong>
        {{ $subscription->trial_ends_at?->format('d/m/Y') }}
    </div>

    <p>{{ __('email.billing.trial_started.line2') }}</p>

    <a href="{{ url('/company/billing') }}" class="btn">
        {{ __('email.billing.trial_started.action') }}
    </a>

    <p>{{ __('email.billing.trial_started.line3') }}</p>
@endsection
