<?php

namespace App\Notifications;

use App\Models\DeletionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DeletionRequestNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string          $type,
        private string          $message,
        private DeletionRequest $deletionRequest,
        private ?string         $comment = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'               => $this->type,
            'message'            => $this->message,
            'comment'            => $this->comment,
            'deletion_request_id'=> $this->deletionRequest->id,
            'document_id'        => $this->deletionRequest->document_id,
            'document'           => $this->deletionRequest->document?->name,
            'icon'               => match($this->type) {
                'approved' => '✅',
                'rejected' => '❌',
                'pending'  => '📋',
                default    => '🔔',
            },
        ];
    }
}