@extends('emails.layout')

@section('content')
    <h2>{{ __('email.billing.payment_method_expiring.greeting', ['name' => $user->first_name]) }}</h2>

    <p>{{ __('email.billing.payment_method_expiring.line1', ['brand' => $profile->brand, 'last4' => $profile->last4, 'month' => $profile->exp_month, 'year' => $profile->exp_year]) }}</p>

    <div class="highlight">
        <strong>{{ $profile->brand }}</strong> **** {{ $profile->last4 }}
        <br>{{ __('email.billing.payment_method_expiring.expires') }} {{ $profile->exp_month }}/{{ $profile->exp_year }}
    </div>

    <p>{{ __('email.billing.payment_method_expiring.line2') }}</p>

    <a href="{{ url('/company/billing') }}" class="btn">
        {{ __('email.billing.payment_method_expiring.action') }}
    </a>

    <p>{{ __('email.billing.payment_method_expiring.line3') }}</p>
@endsection
