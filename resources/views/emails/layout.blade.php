@php
    $branding = \App\Core\Email\EmailService::branding();
    $primaryColor = $branding['color'] ?? '#7367F0';
    $appName = $branding['app_name'] ?? 'Leezr';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? $appName }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f4f5fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
        .card { background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .header { padding: 24px 32px 16px; text-align: center; border-bottom: 1px solid #f0f0f0; }
        .header .brand-logo { font-size: 22px; font-weight: 700; color: #333; display: inline; }
        .header .brand-dot { color: {{ $primaryColor }}; font-weight: 700; }
        .content { padding: 32px; color: #333; font-size: 15px; line-height: 1.6; }
        .content h2 { margin: 0 0 16px; font-size: 20px; color: #333; }
        .content p { margin: 0 0 12px; }
        .content .highlight { background: #f8f7ff; border-left: 3px solid {{ $primaryColor }}; padding: 12px 16px; border-radius: 0 4px 4px 0; margin: 16px 0; }
        .content .highlight strong { color: #333; }
        .btn { display: inline-block; padding: 12px 28px; background: {{ $primaryColor }}; color: #ffffff !important; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; margin: 16px 0; }
        .btn:hover { opacity: 0.9; }
        .footer { padding: 20px 32px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #f0f0f0; }
        .footer a { color: {{ $primaryColor }}; text-decoration: none; }
        .amount { font-size: 24px; font-weight: 700; color: {{ $primaryColor }}; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #e8f5e9; color: #2e7d32; }
        .badge-warning { background: #fff3e0; color: #e65100; }
        .badge-error { background: #ffebee; color: #c62828; }
        .badge-info { background: #e3f2fd; color: #1565c0; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <span class="brand-logo">{{ strtolower($appName) }}</span><span class="brand-dot">.</span>
        </div>

        <div class="content">
            @yield('content')
        </div>

        <div class="footer">
            &copy; {{ date('Y') }} {{ $appName }}
        </div>
    </div>
</div>
</body>
</html>
