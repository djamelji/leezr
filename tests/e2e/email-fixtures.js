// ADR-830 (D-21) — inbound e-mail fixtures for the platform inbox specs, created by the guarded recipe
// command (marked clone only) with the SAME fields as an IMAP import (ImapFetcher), removed by exact ids.
// The specs used to depend on threads seeded by hand on the DEV long ago.
import { recipePhp } from './recipe.js'

export function seedInboundThread({ subject, html, from = 'e2e.sender@example.test', name = 'E2E Sender', to = 'support@leezr.com' }) {
  const q = s => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`
  const out = recipePhp(`<?php
$t = App\\Core\\Email\\EmailThread::create(['subject' => ${q(subject)}, 'participant_email' => ${q(from)}, 'participant_name' => ${q(name)},
  'folder' => 'inbox', 'status' => 'open', 'last_message_at' => now(), 'message_count' => 1, 'unread_count' => 1]);
$m = App\\Core\\Email\\EmailLog::create(['message_id' => 'e2e-'.uniqid().'@example.test', 'recipient_email' => ${q(to)}, 'from_email' => ${q(from)},
  'subject' => ${q(subject)}, 'body_html' => ${q(html)}, 'body_text' => strip_tags(${q(html)}), 'template_key' => 'inbound', 'notification_class' => 'ImapFetch',
  'status' => 'sent', 'direction' => 'received', 'thread_id' => $t->id, 'is_read' => false, 'metadata' => ['source' => 'e2e'], 'sent_at' => now()]);
echo 'RESULT='.$t->id.':'.$m->id;`)
  const m = out.match(/RESULT=(\d+):(\d+)/)
  if (!m) throw new Error(`inbound fixture failed: ${out}`)

  return { threadId: Number(m[1]), messageId: Number(m[2]) }
}

export function removeInboundThread(f) {
  if (!f) return
  recipePhp(`<?php App\\Core\\Email\\EmailLog::where('thread_id', ${f.threadId})->delete(); App\\Core\\Email\\EmailThread::whereKey(${f.threadId})->delete(); echo 'ok';`)
}
