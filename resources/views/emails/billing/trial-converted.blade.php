@extends('emails.layout')

@section('content')
    <h2>{{ __('email.billing.trial_converted.greeting', ['name' => $user->first_name]) }}</h2>

    <p>{{ __('email.billing.trial_converted.line1') }}</p>

    <p>{{ __('email.billing.trial_converted.line2') }}</p>

    <a href="{{ url('/company/billing') }}" class="btn">
        {{ __('email.billing.trial_converted.action') }}
    </a>

    <p>{{ __('email.billing.trial_converted.line3') }}</p>
@endsection
