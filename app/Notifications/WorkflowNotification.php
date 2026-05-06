<?php

namespace App\Notifications;

use App\Models\Workflow;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class WorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string   $type,
        private string   $message,
        private Workflow $workflow,
        private ?string  $comment = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'        => $this->type,
            'message'     => $this->message,
            'comment'     => $this->comment,
            'workflow_id' => $this->workflow->id,
            'document_id' => $this->workflow->document_id,
            'document'    => $this->workflow->document?->name,
            'icon'        => match($this->type) {
                'approved'  => '✅',
                'rejected'  => '❌',
                'submitted' => '📋',
                'cancelled' => '🚫',
                default     => '🔔',
            },
        ];
    }
}