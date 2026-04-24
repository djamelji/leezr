<?php

namespace App\Modules\Platform\Email\Http;

use App\Core\Email\EmailLog;
use App\Core\Email\EmailRecipient;
use App\Core\Email\EmailService;
use App\Notifications\Email\ManualEmailNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class EmailHealthController extends Controller
{
    private const DNSBL_SERVERS = [
        'spamhaus' => 'zen.spamhaus.org',
        'barracuda' => 'b.barracudacentral.org',
        'spamcop' => 'bl.spamcop.net',
        'sorbs' => 'dnsbl.sorbs.net',
    ];

    public function index(): JsonResponse
    {
        $domain = $this->getSendingDomain();
        $serverIp = $this->getServerIp();

        return response()->json([
            'dns' => [
                'spf' => $this->checkSpf($domain),
                'dkim' => $this->checkDkim($domain),
                'dmarc' => $this->checkDmarc($domain),
                'ptr' => $this->checkPtr($serverIp),
                'ptr_ipv6' => $this->checkIpv6Ptr($domain, $serverIp),
            ],
            'reputation' => $this->checkReputation($serverIp),
            'stats' => $this->getStats(),
            'smtp' => $this->getSmtpStatus(),
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $testEmail = $request->input('email', auth()->user()->email);
        if (! filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'Invalid email address'], 422);
        }

        try {
            $recipient = new EmailRecipient($testEmail, 'Deliverability Test');
            $body = '<h2 style="color:#7367F0">Leezr Deliverability Test</h2>'
                .'<p>Automated test — '.now()->format('Y-m-d H:i:s T').'</p>'
                .'<p>Gmail: "Show original" → verify spf=pass, dkim=pass, dmarc=pass</p>';
            $notification = new ManualEmailNotification('Leezr Email Deliverability Test', $body);

            $log = app(EmailService::class)->send(
                $notification, $recipient, 'health.deliverability_test', null, ['health_test' => true],
            );

            return response()->json(['success' => true, 'message_id' => $log->message_id, 'recipient' => $testEmail]);
        } catch (\Throwable $e) {
            Log::error('[email-health] Test failed', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function checkSpf(string $domain): array
    {
        $records = dns_get_record($domain, DNS_TXT) ?: [];
        $spfRecords = array_filter($records, fn ($r) => str_starts_with($r['txt'] ?? '', 'v=spf1'));

        if (count($spfRecords) === 0) {
            return ['status' => 'missing', 'record' => null, 'aligned' => false];
        }
        if (count($spfRecords) > 1) {
            return ['status' => 'multiple', 'record' => array_column($spfRecords, 'txt'), 'aligned' => false,
                'detail' => 'RFC 7208 violation: multiple SPF records cause PermError'];
        }

        $spf = array_values($spfRecords)[0]['txt'];
        $hardFail = str_ends_with($spf, '-all');

        return [
            'status' => $hardFail && str_contains($spf, 'ip4:') ? 'valid' : 'weak',
            'record' => $spf, 'aligned' => $hardFail,
            'detail' => ! $hardFail ? 'Use -all (hard fail) instead of ~all' : null,
        ];
    }

    private function checkDkim(string $domain): array
    {
        $dkimDomain = "default._domainkey.{$domain}";
        $records = dns_get_record($dkimDomain, DNS_TXT) ?: [];
        $found = false;
        foreach ($records as $r) {
            if (str_contains($r['txt'] ?? '', 'v=DKIM1')) { $found = true; break; }
        }

        return [
            'status' => $found ? 'configured' : 'missing', 'selector' => 'default',
            'record_exists' => $found,
            'detail' => $found ? 'DNS record exists. Signing verified via test email.' : "No DKIM record at {$dkimDomain}",
        ];
    }

    private function checkDmarc(string $domain): array
    {
        $records = dns_get_record("_dmarc.{$domain}", DNS_TXT) ?: [];
        $dmarcRecord = null;
        foreach ($records as $r) {
            if (str_starts_with($r['txt'] ?? '', 'v=DMARC1')) { $dmarcRecord = $r['txt']; break; }
        }
        if (! $dmarcRecord) {
            return ['status' => 'missing', 'record' => null, 'policy' => null];
        }

        $policy = preg_match('/p=(none|quarantine|reject)/', $dmarcRecord, $m) ? $m[1] : 'none';

        return [
            'status' => $policy, 'record' => $dmarcRecord, 'policy' => $policy,
            'detail' => $policy === 'none' ? 'p=none offers no protection. Upgrade to quarantine/reject.' : null,
        ];
    }

    private function checkPtr(string $ip): array
    {
        $ptrName = gethostbyaddr($ip);
        $ok = $ptrName && $ptrName !== $ip;
        $hostname = gethostname() ?: 'unknown';

        return [
            'status' => $ok ? 'configured' : 'missing',
            'ptr_name' => $ok ? $ptrName : null, 'ip' => $ip,
            'detail' => $ok ? "PTR resolves to {$ptrName}" : 'No PTR record for server IP',
        ];
    }

    private function checkIpv6Ptr(string $domain, string $ipv4): array
    {
        $aaaaRecords = dns_get_record($domain, DNS_AAAA) ?: [];
        if (empty($aaaaRecords)) {
            return ['status' => 'none', 'detail' => 'No AAAA record — IPv6 not configured'];
        }

        $ipv6 = $aaaaRecords[0]['ipv6'] ?? null;
        if (! $ipv6) {
            return ['status' => 'none', 'detail' => 'No IPv6 address found'];
        }

        $ipv6Ptr = @gethostbyaddr($ipv6);
        $ipv4Ptr = @gethostbyaddr($ipv4);
        $hasPtr = $ipv6Ptr && $ipv6Ptr !== $ipv6;
        $match = $hasPtr && $ipv4Ptr && $ipv6Ptr === $ipv4Ptr;

        if (! $hasPtr) {
            return [
                'status' => 'missing', 'ip' => $ipv6, 'ptr_name' => null,
                'detail' => "IPv6 {$ipv6} has no PTR record — emails via IPv6 will fail SPF/iprev",
            ];
        }

        if (! $match) {
            return [
                'status' => 'mismatch', 'ip' => $ipv6,
                'ptr_name' => $ipv6Ptr, 'ipv4_ptr' => $ipv4Ptr,
                'detail' => "IPv6 PTR ({$ipv6Ptr}) differs from IPv4 PTR ({$ipv4Ptr}) — causes intermittent spam. Fix: set inet_protocols=ipv4 in Postfix or align IPv6 PTR.",
            ];
        }

        return [
            'status' => 'configured', 'ip' => $ipv6, 'ptr_name' => $ipv6Ptr,
            'detail' => "IPv6 PTR matches IPv4 PTR ({$ipv6Ptr})",
        ];
    }

    private function checkReputation(string $ip): array
    {
        $reversed = implode('.', array_reverse(explode('.', $ip)));
        $results = [];
        foreach (self::DNSBL_SERVERS as $name => $server) {
            $results[$name] = empty(@dns_get_record("{$reversed}.{$server}", DNS_A)) ? 'clean' : 'listed';
        }

        return $results;
    }

    private function getStats(): array
    {
        return [
            'sent_24h' => EmailLog::sent()->where('created_at', '>=', now()->subDay())->count(),
            'sent_7d' => EmailLog::sent()->where('created_at', '>=', now()->subDays(7))->count(),
            'failed_24h' => EmailLog::failed()->where('created_at', '>=', now()->subDay())->count(),
            'failed_7d' => EmailLog::failed()->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    private function getSmtpStatus(): array
    {
        $result = app(EmailService::class)->testConnection();

        return ['configured' => $result['success'], 'message' => $result['message']];
    }

    private function getSendingDomain(): string
    {
        $from = (\App\Platform\Models\PlatformSetting::first()?->email ?? [])['from_email']
            ?? config('mail.from.address', 'noreply@leezr.com');

        return substr($from, strpos($from, '@') + 1) ?: 'leezr.com';
    }

    private function getServerIp(): string
    {
        $aRecords = dns_get_record($this->getSendingDomain(), DNS_A) ?: [];

        return $aRecords[0]['ip'] ?? '127.0.0.1';
    }
}
