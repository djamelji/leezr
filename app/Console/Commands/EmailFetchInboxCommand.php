<?php

namespace App\Console\Commands;

use App\Core\Email\ImapFetcher;
use Illuminate\Console\Command;

class EmailFetchInboxCommand extends Command
{
    protected $signature = 'email:fetch-inbox {--limit=50 : Max emails to fetch per run}';

    protected $description = 'Fetch new emails from IMAP mailbox into the inbox';

    public function handle(): int
    {
        $fetcher = new ImapFetcher;

        if (! $fetcher->isConfigured()) {
            $this->info('IMAP not configured — skipping.');

            return self::SUCCESS;
        }

        if (! $fetcher->connect()) {
            $this->error('Failed to connect to IMAP server.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $count = $fetcher->fetch($limit);

        $fetcher->disconnect();

        $this->info("Fetched {$count} new email(s).");

        return self::SUCCESS;
    }
}
