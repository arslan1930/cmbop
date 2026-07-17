<?php

namespace App\Mail;

use App\Models\ContentSubmission;

class ContentEvaluationResult extends PlatformMailable
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public ContentSubmission $submission,
        public array $result,
    ) {
        parent::__construct();
        $this->notificationType = 'content_evaluation_result';
        $this->recipientUser = $submission->user;
        $this->dedupeKey = 'content_eval:' . $submission->id . ':' . ($result['moderation_status'] ?? 'unknown');
    }

    public function build()
    {
        $approved = (bool) ($this->result['approved'] ?? false);
        $subject = $approved
            ? 'Your article was approved for publication'
            : 'Article evaluation update: action needed';

        return $this->subject($subject)
            ->markdown('emails.content-evaluation-result')
            ->with([
                'submission' => $this->submission,
                'result' => $this->result,
                'approved' => $approved,
                'firstName' => $this->firstName($this->submission->user),
                'libraryUrl' => url('/advertiser/content-library'),
                'brand' => $this->brand(),
            ]);
    }
}
