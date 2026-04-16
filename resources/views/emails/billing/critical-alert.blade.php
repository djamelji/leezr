@extends('emails.layout')

@section('content')
    <h2>{{ __('email.billing.critical_alert.title') }}</h2>

    <div class="highlight" style="border-left-color: #c62828;">
        <span class="badge badge-error">{{ __('email.billing.critical_alert.critical') }}</span>
        <p style="margin:8px 0 0"><strong>{{ __('email.billing.critical_alert.action_label') }}</strong> {{ $auditLog->action }}</p>
        <p style="margin:4px 0 0"><strong>{{ __('email.billing.critical_alert.target') }}</strong> {{ $auditLog->target_type }}:{{ $auditLog->target_id }}</p>
        <p style="margin:4px 0 0"><strong>{{ __('email.billing.critical_alert.severity') }}</strong> {{ $auditLog->severity }}</p>
        <p style="margin:4px 0 0"><strong>{{ __('email.billing.critical_alert.time') }}</strong> {{ $auditLog->created_at->format('d/m/Y H:i:s') }}</p>
    </div>

    <a href="{{ url('/platform') }}" class="btn">
        {{ __('email.billing.critical_alert.action_button') }}
    </a>
@endsection
