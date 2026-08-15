<?php

namespace App\Mail;

use App\Models\ProblemReport;
use App\Models\Suggestion;

class CommunityFeedbackReviewed extends PlatformMailable
{
    public function __construct(
        public ProblemReport|Suggestion $item,
        public string $kind,
    ) {
        parent::__construct();
        $this->item->loadMissing(['user']);
        $this->notificationType = 'community_feedback_reviewed';
        $this->recipientUser = $this->item->user;
        $this->skipUserPreference = $this->recipientUser === null;
        $this->dedupeKey = 'community-feedback-reviewed-'.$this->kind.'-'.$this->item->id.'-'.$this->item->status;
    }

    public function build()
    {
        $resolved = in_array($this->item->status, ['resolved', 'accepted'], true);
        $subjectLabel = $this->kind === 'problem'
            ? (string) ($this->item->subject ?: 'your report')
            : 'your suggestion';

        return $this->subject(
            ($resolved ? 'Update on ' : 'We reviewed ').$subjectLabel
        )
            ->markdown('emails.community-feedback-reviewed')
            ->with([
                'item' => $this->item,
                'kind' => $this->kind,
                'resolved' => $resolved,
                'subjectLabel' => $subjectLabel,
                'notes' => trim((string) ($this->item->admin_notes ?? '')),
                'actionUrl' => $this->publicRoute('home'),
            ]);
    }
}
