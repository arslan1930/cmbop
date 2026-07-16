<?php

namespace Tests\Unit;

use App\Services\ContentUpload\ArticleEvaluationService;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentModeration\ContentQualityAnalyzer;
use Mockery;
use PHPUnit\Framework\TestCase;

class ArticleEvaluationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_identical_shingle_sets_have_full_jaccard_similarity(): void
    {
        $service = new ArticleEvaluationService(
            Mockery::mock(ContentModerationService::class),
            new ContentQualityAnalyzer(),
        );

        $text = 'Digital marketing strategies help brands grow organic traffic with useful content and clear calls to action for readers every week.';

        $ref = new \ReflectionClass($service);
        $jaccard = $ref->getMethod('jaccard');
        $jaccard->setAccessible(true);
        $shingles = $ref->getMethod('shingles');
        $shingles->setAccessible(true);

        $set = $shingles->invoke($service, $text, 3);
        $this->assertNotEmpty($set);
        $this->assertSame(1.0, $jaccard->invoke($service, $set, $set));
    }

    public function test_unrelated_texts_have_low_jaccard_similarity(): void
    {
        $service = new ArticleEvaluationService(
            Mockery::mock(ContentModerationService::class),
            new ContentQualityAnalyzer(),
        );

        $ref = new \ReflectionClass($service);
        $jaccard = $ref->getMethod('jaccard');
        $jaccard->setAccessible(true);
        $shingles = $ref->getMethod('shingles');
        $shingles->setAccessible(true);

        $a = $shingles->invoke($service, 'Garden tomatoes need sun water and rich soil for a healthy summer harvest.', 3);
        $b = $shingles->invoke($service, 'Quantum computing research explores qubit coherence and error correction techniques.', 3);

        $this->assertLessThan(0.2, $jaccard->invoke($service, $a, $b));
    }
}
