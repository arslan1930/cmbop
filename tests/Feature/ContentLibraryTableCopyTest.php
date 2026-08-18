<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

/**
 * Approved-table leftover copy: one expiry policy line, labeled scores.
 */
class ContentLibraryTableCopyTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    public function test_approved_list_shows_expiry_policy_once_and_labeled_scores(): void
    {
        $advertiser = $this->advertiser();
        $first = $this->createApprovedSubmission($advertiser);
        $first->update(['title' => 'First Copy Piece']);
        $second = $this->createApprovedSubmission($advertiser);
        $second->update([
            'title' => 'Second Copy Piece',
            'uniqueness_score' => 25,
            'quality_score' => 92,
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('First Copy Piece')
            ->assertSee('Second Copy Piece')
            ->assertSee('Uniqueness · Quality')
            ->assertSee('Unique 85%')
            ->assertSee('Quality 80%')
            ->assertSee('Unique 25%')
            ->assertSee('Quality 92%')
            ->assertSee('Advisory — still orderable')
            ->assertSee('library-table-note', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Unused originals are removed after expiry; preview stays.'));
        $this->assertSame(2, substr_count($html, 'Expires in'));
        $this->assertDoesNotMatchRegularExpression(
            '/library-expiry-hint[^>]*>[^<]*unused originals are removed after expiry/i',
            $html
        );
        $this->assertStringNotContainsString('>85%</td>', $html);
        $this->assertStringNotContainsString('85% · 80%', preg_replace('/\s+/', ' ', $html) ?? $html);
    }

    public function test_missing_score_is_labeled_dash_not_bare_percent(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update([
            'title' => 'Partial Score Piece',
            'uniqueness_score' => null,
            'quality_score' => 77,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Partial Score Piece')
            ->assertSee('Unique —')
            ->assertSee('Quality 77%')
            ->assertDontSee('Advisory — still orderable');
    }

    public function test_live_search_fragment_keeps_labeled_scores_and_one_policy_line(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Live Copy Playbook']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.results', ['q' => 'Live Copy']))
            ->assertOk()
            ->assertSee('Live Copy Playbook')
            ->assertSee('Uniqueness · Quality')
            ->assertSee('Unique 85%')
            ->assertSee('Quality 80%')
            ->assertDontSee('<html', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Unused originals are removed after expiry; preview stays.'));
        $this->assertStringContainsString('library-table-note', $html);
    }

    public function test_processing_chip_omits_approved_expiry_policy_note(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Still Available Piece']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('library-table-note', $html);
        $this->assertStringNotContainsString('Unused originals are removed after expiry; preview stays.', $html);
    }

    public function test_uses_filename_as_title_treats_blank_and_docx_matches(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);

        $article->update(['title' => '', 'original_filename' => 'MtKTDFjD7N2Zgfx3lv3I (1).docx']);
        $this->assertTrue($article->fresh()->usesFilenameAsTitle());

        $article->update(['title' => 'MtKTDFjD7N2Zgfx3lv3I (1).docx']);
        $this->assertTrue($article->fresh()->usesFilenameAsTitle());

        $article->update(['title' => 'MtKTDFjD7N2Zgfx3lv3I (1)']);
        $this->assertTrue($article->fresh()->usesFilenameAsTitle());

        $article->update(['title' => 'Germany article']);
        $this->assertFalse($article->fresh()->usesFilenameAsTitle());
    }

    public function test_filename_leftover_title_shows_untitled_and_rename(): void
    {
        $advertiser = $this->advertiser();
        $leftover = $this->createApprovedSubmission($advertiser);
        $leftover->update([
            'title' => 'MtKTDFjD7N2Zgfx3lv3I (1).docx',
            'original_filename' => 'MtKTDFjD7N2Zgfx3lv3I (1).docx',
        ]);
        $named = $this->createApprovedSubmission($advertiser);
        $named->update(['title' => 'Germany article']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Untitled')
            ->assertSee('MtKTDFjD7N2Zgfx3lv3I (1).docx')
            ->assertSee('Germany article')
            ->assertSee('Rename')
            ->getContent();

        $leftoverStart = strpos($html, 'library-row-'.$leftover->id);
        $leftoverEnd = strpos($html, '</tr>', $leftoverStart);
        $leftoverRow = substr($html, $leftoverStart, $leftoverEnd - $leftoverStart);
        $this->assertStringContainsString('Untitled', $leftoverRow);
        $this->assertStringContainsString('library-filename-hint', $leftoverRow);
        $this->assertStringContainsString('library-rename-link', $leftoverRow);
        $this->assertStringContainsString('toggleLibraryTitleEdit('.$leftover->id, $leftoverRow);

        $namedStart = strpos($html, 'library-row-'.$named->id);
        $namedEnd = strpos($html, '</tr>', $namedStart);
        $namedRow = substr($html, $namedStart, $namedEnd - $namedStart);
        $this->assertStringContainsString('Germany article', $namedRow);
        $this->assertStringNotContainsString('Untitled', $namedRow);
        $this->assertStringNotContainsString('library-filename-hint', $namedRow);
        $this->assertStringNotContainsString('library-rename-link', $namedRow);
    }

    public function test_approved_chip_uses_approved_clock_not_uploaded(): void
    {
        $this->freezeTime();
        $advertiser = $this->advertiser();
        $fresh = $this->createApprovedSubmission($advertiser);
        $fresh->update(['title' => 'Clock Fresh Piece']);
        $yesterday = $this->createApprovedSubmission($advertiser);
        $yesterday->update([
            'title' => 'Clock Yesterday Piece',
            'evaluated_at' => now()->subDay(),
        ]);
        $stale = $this->createApprovedSubmission($advertiser);
        $stale->update([
            'title' => 'Clock Stale Piece',
            'evaluated_at' => now()->subDays(ContentSubmission::JUST_APPROVED_DAYS + 1)->startOfDay(),
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('Clock Fresh Piece')
            ->assertSee('Clock Yesterday Piece')
            ->assertSee('Clock Stale Piece')
            ->getContent();

        $freshRow = $this->libraryRowHtml($html, $fresh->id);
        $this->assertStringContainsString('Just approved', $freshRow);
        $this->assertStringContainsString('Approved today', $freshRow);
        $this->assertStringContainsString('library-status-time', $freshRow);
        $this->assertStringNotContainsString('library-just-approved-hint', $freshRow);
        $this->assertStringNotContainsString('Uploaded', $freshRow);

        $yesterdayRow = $this->libraryRowHtml($html, $yesterday->id);
        $this->assertStringContainsString('Approved yesterday', $yesterdayRow);
        $this->assertStringNotContainsString('Just approved', $yesterdayRow);
        $this->assertStringNotContainsString('Uploaded', $yesterdayRow);

        $staleRow = $this->libraryRowHtml($html, $stale->id);
        $this->assertStringNotContainsString('Just approved', $staleRow);
        $this->assertStringNotContainsString('Approved today', $staleRow);
        $this->assertStringNotContainsString('Approved yesterday', $staleRow);
        $this->assertStringNotContainsString('Uploaded', $staleRow);
    }

    public function test_search_still_matches_filename_leftover_title(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update([
            'title' => '',
            'original_filename' => 'summer-guide.docx',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.results', ['q' => 'summer-guide']))
            ->assertOk()
            ->assertSee('Untitled')
            ->assertSee('summer-guide.docx');
    }

    private function libraryRowHtml(string $html, int $id): string
    {
        $start = strpos($html, 'library-row-'.$id);
        $this->assertNotFalse($start);
        $end = strpos($html, '</tr>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
