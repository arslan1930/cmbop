<?php

namespace Tests\Unit;

use App\Services\ContentUpload\ArticleDetectedLinks;
use App\Services\ContentUpload\ArticlePreviewHtml;
use PHPUnit\Framework\TestCase;

class ArticleDetectedLinksTest extends TestCase
{
    public function test_normalize_list_keeps_unique_https_links(): void
    {
        $links = ArticleDetectedLinks::normalizeList([
            ['anchor' => 'First', 'url' => 'https://example.com/a'],
            ['anchor' => 'Dup', 'url' => 'https://example.com/a'],
            ['anchor' => 'Second', 'url' => 'https://example.com/b'],
            ['anchor' => 'Bad', 'url' => 'http://insecure.example'],
            ['anchor' => '', 'url' => 'https://example.com/c'],
        ]);

        $this->assertCount(3, $links);
        $this->assertSame('First', $links[0]['anchor']);
        $this->assertSame('Second', $links[1]['anchor']);
        $this->assertSame('https://example.com/c', $links[2]['anchor']);
    }

    public function test_from_html_extracts_all_https_anchors(): void
    {
        $html = '<p>Try <a href="https://example.com/one">one</a> and '
            .'<a href="https://example.com/two">two tools</a> today.</p>';

        $links = ArticleDetectedLinks::fromHtml($html);

        $this->assertCount(2, $links);
        $this->assertSame('one', $links[0]['anchor']);
        $this->assertSame('https://example.com/one', $links[0]['url']);
        $this->assertSame('two tools', $links[1]['anchor']);
    }

    public function test_apply_to_html_rewrites_anchors_in_order(): void
    {
        $html = '<p><a href="https://example.com/old">old text</a> and '
            .'<a href="https://example.com/keep">keep</a></p>';
        $updated = ArticleDetectedLinks::applyToHtml($html, [
            ['anchor' => 'new text', 'url' => 'https://example.com/new'],
            ['anchor' => 'keep', 'url' => 'https://example.com/keep'],
        ]);

        $this->assertStringContainsString('href="https://example.com/new"', $updated);
        $this->assertStringContainsString('>new text</a>', $updated);
        $this->assertStringContainsString('href="https://example.com/keep"', $updated);
    }

    public function test_preview_html_strips_legacy_detected_link_footer(): void
    {
        $html = '<p>Body copy.</p><p class="article-detected-link">Detected link: <a href="https://x.com">x</a></p>';
        $clean = ArticlePreviewHtml::normalize($html);

        $this->assertStringContainsString('Body copy', $clean);
        $this->assertStringNotContainsString('article-detected-link', $clean);
        $this->assertStringNotContainsString('Detected link', $clean);
    }
}
