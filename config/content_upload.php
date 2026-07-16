<?php

/**
 * Native content upload settings (Microsoft Word .docx only).
 * Admin overrides are merged via content_moderation_settings key "upload_config".
 */
return [

    'enabled' => env('CONTENT_UPLOAD_ENABLED', true),

    /** Only Microsoft Word documents are accepted */
    'preferred_extension' => 'docx',

    'allowed_extensions' => ['docx'],

    'allowed_mimes' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/octet-stream',
    ],

    /** Max upload size in kilobytes (5120 = 5 MB) */
    'max_kilobytes' => (int) env('CONTENT_UPLOAD_MAX_KB', 5120),

    'disk' => env('CONTENT_UPLOAD_DISK', 'local'),

    'directory' => 'content-uploads',

    /** Retention before automatic purge */
    'retention_months' => 6,

    'scheduling' => [
        'enabled' => env('CONTENT_SCHEDULING_ENABLED', true),
        'max_months' => 3,
        'default_timezone' => 'UTC',
        'reminder_hours_before' => 24,
        /**
         * Scheduled orders are charged immediately and publishers are notified
         * right away — they must publish on the scheduled date.
         */
        'charge_in_advance' => true,
        'notify_publisher_immediately' => true,
    ],

    'evaluation' => [
        /** Minimum uniqueness score (0–100) required for publication approval */
        'min_uniqueness' => (int) env('CONTENT_MIN_UNIQUENESS', 50),
        /** Minimum overall quality score (0–100) */
        'min_quality' => (int) env('CONTENT_MIN_QUALITY', 50),
        /** How many prior corpus articles to compare against */
        'corpus_limit' => 200,
        /** Optional OpenAI key for advanced uniqueness/quality narrative */
        'openai_api_key' => env('OPENAI_API_KEY'),
        'openai_model' => env('OPENAI_CONTENT_MODEL', 'gpt-4o-mini'),
    ],

    'anchor_text' => [
        'max_length' => 120,
        'min_length' => 1,
    ],

    'feature_image' => [
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ],

    'help' => [
        'preferred_format' => 'Please upload your article as a Microsoft Word (.docx) document only. Other formats are not accepted.',
        'before_upload' => 'Supported format: Microsoft Word (.docx) only. Maximum size: 5 MB. Your article will be evaluated for uniqueness, quality, and content compliance before you can place an order.',
        'anchor_text' => 'Enter the exact anchor text that should appear in the article.',
        'target_url' => 'Enter the website URL that the anchor text should link to.',
        'feature_image' => 'If you would like the publisher to use a featured image, provide a royalty-free image URL from platforms such as Pixabay, Pexels, Unsplash, or similar sources.',
        'compliance_reject' => 'This article contains content that violates our publishing guidelines.' . "\n\n"
            . 'Please upload a revised document before continuing.',
        'uniqueness_reject' => 'Uniqueness is below 50%. Please improve the article and resubmit a more original version before placing an order.',
        'quality_reject' => 'Content quality is below the required threshold. Please improve the article and resubmit.',
    ],
];
