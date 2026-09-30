<?php

namespace App\Livewire\Concerns;

trait Notifies
{
    /**
     * Last message handed to the toast stack. Kept as public state so the
     * component contract stays explicit and testable.
     */
    public ?string $notice = null;

    public string $noticeType = 'success';

    /**
     * Show a message in the global toast stack.
     */
    protected function notify(string $message, string $type = 'success'): void
    {
        $this->notice = $message;
        $this->noticeType = $type;

        $this->dispatch('toast', message: $message, tone: $type);
    }

    protected function notifySuccess(string $message): void
    {
        $this->notify($message, 'success');
    }

    protected function notifyError(string $message): void
    {
        $this->notify($message, 'error');
    }
}
