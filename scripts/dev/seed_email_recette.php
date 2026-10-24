<?php

/**
 * Isolated recette fixtures for the Platform email E2E suite (ADR-609).
 * Idempotent: wipes only the "[E2E]"-prefixed threads it owns, never touches other mail.
 * Run: php artisan tinker scripts/dev/seed_email_recette.php
 */

use App\Core\Email\EmailAttachment;
use App\Core\Email\EmailLog;
use App\Core\Email\EmailThread;

$owned = EmailThread::where('subject', 'like', '[E2E]%')->pluck('id');
EmailAttachment::whereIn('email_log_id', EmailLog::whereIn('thread_id', $owned)->pluck('id'))->delete();
EmailLog::whereIn('thread_id', $owned)->delete();
EmailThread::whereIn('id', $owned)->delete();

$mk = function (string $subject, string $folder, array $logOverrides = [], int $unread = 1) {
    $thread = EmailThread::create([
        'subject' => $subject,
        'participant_email' => $logOverrides['from_email'] ?? 'client-a@example.org',
        'participant_name' => 'Client A',
        'status' => 'open',
        'folder' => $folder,
        'last_message_at' => now(),
        'unread_count' => $unread,
        'message_count' => 1,
    ]);
    EmailLog::create(array_merge([
        'message_id' => 'e2e-'.$thread->id.'@example.org',
        'thread_id' => $thread->id,
        'direction' => 'received',
        'recipient_email' => 'admin@leezr.com',
        'from_email' => 'client-a@example.org',
        'from_name' => 'Client A',
        'subject' => $subject,
        'body_html' => '<p>Corps de recette E2E.</p>',
        'body_text' => 'Corps de recette E2E.',
        'status' => 'sent',
        'is_read' => false,
        'template_key' => 'inbound',
        'notification_class' => 'Inbound',
    ], $logOverrides));

    return $thread;
};

// Reply-all: a message with the mailbox + an extra Cc recipient besides the sender.
$ra = $mk('[E2E] Reply-all source', 'inbox', ['cc' => 'team@example.org']);
// Forward
$fw = $mk('[E2E] Forward source', 'inbox');
// Spam + Trash folders
$mk('[E2E] Spam item', 'spam');
$mk('[E2E] Trash item', 'trash');
// Search: a unique token
$mk('[E2E] UNICORNTOKEN unique subject', 'inbox');
// Hostile HTML (XSS neutralization proof) — subject contains "XSS Probe Subject".
$mk('[E2E] XSS Probe Subject', 'inbox', [
    'from_email' => 'attacker@evil.test',
    'body_html' => '<p>Safe content here.</p><script>window.__xss=1</script><img src=x onerror="window.__xss=1">',
]);

echo 'E2E recette seeded: reply-all='.$ra->id.' forward='.$fw->id.PHP_EOL;
echo 'inbox threads total='.EmailThread::where('folder', 'inbox')->count().PHP_EOL;
