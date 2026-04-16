@extends('emails.layout')

@section('content')
    <h2>{{ __('email.billing.plan_changed.greeting', ['name' => $user->first_name]) }}</h2>

    <p>{{ __('email.billing.plan_changed.line1', ['old' => $oldPlanName, 'new' => $newPlanName]) }}</p>

    <div class="highlight">
        <span style="text-decoration: line-through; color: #999;">{{ $oldPlanName }}</span>
        &rarr;
        <strong>{{ $newPlanName }}</strong>
    </div>

    <a href="{{ url('/company/plan') }}" class="btn">
        {{ __('email.billing.plan_changed.action') }}
    </a>

    <p>{{ __('email.billing.plan_changed.line2') }}</p>
@endsection
