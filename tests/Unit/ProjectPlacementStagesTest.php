<?php

namespace Tests\Unit;

use App\Models\Project;
use Tests\TestCase;

class ProjectPlacementStagesTest extends TestCase
{
    public function test_host_from_url_strips_scheme_www_and_path(): void
    {
        $this->assertSame('acme.example', Project::hostFromUrl('https://www.acme.example/blog/post'));
        $this->assertSame('acme.example', Project::hostFromUrl('http://acme.example'));
        $this->assertSame('acme.example', Project::hostFromUrl('acme.example/path'));
        $this->assertSame('', Project::hostFromUrl(null));
        $this->assertSame('', Project::hostFromUrl('   '));
    }

    public function test_stage_bucket_maps_advertiser_stages(): void
    {
        $this->assertSame('not_started', Project::stageBucket('awaiting_payment'));
        $this->assertSame('not_started', Project::stageBucket('scheduled'));
        $this->assertSame('not_started', Project::stageBucket('paid'));
        $this->assertSame('in_progress', Project::stageBucket('processing'));
        $this->assertSame('in_review', Project::stageBucket('review'));
        $this->assertSame('waiting_approval', Project::stageBucket('url_delivered'));
        $this->assertSame('needs_improvements', Project::stageBucket('revision'));
        $this->assertSame('needs_improvements', Project::stageBucket('content_revision'));
        $this->assertSame('completed', Project::stageBucket('completed'));
        $this->assertSame('rejected', Project::stageBucket('cancelled'));
        $this->assertSame('rejected', Project::stageBucket('refunded'));
        $this->assertSame('rejected', Project::stageBucket('payment_failed'));
        $this->assertNull(Project::stageBucket('unknown'));
    }

    public function test_generate_slug_includes_user_id_so_names_can_repeat_across_advertisers(): void
    {
        $this->assertSame('acme-client-7', Project::generateSlug('Acme Client', 7));
        $this->assertSame('acme-client-9', Project::generateSlug('Acme Client', 9));
        $this->assertSame('acme-client-7', Project::generateSlug('Acme-Client', 7));
    }

    public function test_needs_you_count_is_live_url_review_plus_content_revisions(): void
    {
        $counts = Project::emptyStageCounts();
        $this->assertSame(0, Project::needsYouCountFrom($counts));

        $counts['in_review'] = 2;
        $this->assertSame(0, Project::needsYouCountFrom($counts));

        $counts['waiting_approval'] = 1;
        $counts['needs_improvements'] = 3;
        $this->assertSame(1, Project::needsYouCountFrom($counts));

        $counts['content_revision'] = 3;
        $this->assertSame(4, Project::needsYouCountFrom($counts));
    }

    public function test_sanitize_host_for_like_strips_wildcards(): void
    {
        $this->assertSame('acme.example', Project::sanitizeHostForLike('https://www.acme.example/path'));
        $this->assertSame('acme.example', Project::sanitizeHostForLike('%acme.example_'));
        $this->assertSame('', Project::sanitizeHostForLike('%'));
    }

    public function test_host_like_patterns_cover_scheme_path_query_and_hash(): void
    {
        $this->assertSame([
            'acme.example/%',
            'http://acme.example/%',
            'http://acme.example',
            'http://acme.example?%',
            'http://acme.example#%',
            'http://acme.example:%',
            'http://%:%@acme.example/%',
            'http://%:%@acme.example',
            'http://%:%@acme.example?%',
            'http://%:%@acme.example#%',
            'http://%:%@acme.example:%',
            'https://acme.example/%',
            'https://acme.example',
            'https://acme.example?%',
            'https://acme.example#%',
            'https://acme.example:%',
            'https://%:%@acme.example/%',
            'https://%:%@acme.example',
            'https://%:%@acme.example?%',
            'https://%:%@acme.example#%',
            'https://%:%@acme.example:%',
        ], Project::hostLikePatterns('acme.example'));

        $this->assertSame([
            '%://%/%@acme.example%',
            '%://%?%@acme.example%',
            '%://%#%@acme.example%',
        ], Project::hostLikeFalseUserinfoPatterns('acme.example'));
    }

    public function test_stage_filter_keys_include_needs_you(): void
    {
        $this->assertContains('needs_you', Project::stageFilterKeys());
        $this->assertTrue(Project::isKnownStageFilter('waiting_approval'));
        $this->assertFalse(Project::isKnownStageFilter('not-a-stage'));
        $this->assertSame('Needs you', Project::stageLabel('needs_improvements'));
        $this->assertSame('Needs attention', Project::stageLabel('needs_you'));
    }
}
