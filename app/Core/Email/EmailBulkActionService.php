<?php

namespace App\Core\Email;

class EmailBulkActionService
{
    /**
     * Apply a bulk action to the given thread IDs.
     *
     * @param  int[]  $ids
     */
    public function apply(array $ids, string $action, ?string $label = null): void
    {
        $threads = EmailThread::whereIn('id', $ids);

        match ($action) {
            'read' => $this->markRead($ids, $threads),
            'unread' => $threads->update(['unread_count' => 1]),
            'star' => $threads->update(['is_starred' => true]),
            'unstar' => $threads->update(['is_starred' => false]),
            'trash' => $threads->update(['folder' => 'trash', 'trashed_at' => now()]),
            'spam' => $threads->update(['folder' => 'spam']),
            'inbox' => $threads->update(['folder' => 'inbox', 'trashed_at' => null]),
            'delete' => $this->permanentDelete($ids),
            'label' => $label ? $this->addLabel($ids, $label) : null,
            'unlabel' => $label ? $this->removeLabel($ids, $label) : null,
            default => null,
        };
    }

    private function markRead(array $ids, $threads): void
    {
        $threads->update(['unread_count' => 0]);
        EmailLog::whereIn('thread_id', $ids)->where('is_read', false)->update(['is_read' => true]);
    }

    private function permanentDelete(array $ids): void
    {
        EmailThread::whereIn('id', $ids)->where('folder', 'trash')->each(function ($thread) {
            $thread->messages()->delete();
            $thread->delete();
        });
    }

    private function addLabel(array $ids, string $label): void
    {
        foreach (EmailThread::whereIn('id', $ids)->get() as $thread) {
            $labels = $thread->labels ?? [];
            if (! in_array($label, $labels)) {
                $labels[] = $label;
                $thread->update(['labels' => $labels]);
            }
        }
    }

    private function removeLabel(array $ids, string $label): void
    {
        foreach (EmailThread::whereIn('id', $ids)->get() as $thread) {
            $labels = array_values(array_filter($thread->labels ?? [], fn ($l) => $l !== $label));
            $thread->update(['labels' => $labels ?: null]);
        }
    }
}
