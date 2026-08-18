<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ContentLibraryLiveSearchTest extends TestCase
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

    public function test_results_endpoint_requires_auth(): void
    {
        $this->get(route('advertiser.content-library.results'))
            ->assertRedirect();
    }

    public function test_results_endpoint_returns_fragment_not_full_layout(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Growth Playbook']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.results', ['q' => 'Growth']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Growth Playbook')
            ->assertSee('library-status-row', false)
            ->assertDontSee('<html', false)
            ->getContent();

        $this->assertStringContainsString('library-table', $html);
    }

    public function test_results_array_q_does_not_500(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Array Query Playbook']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.results', [
                'q' => ['Array Query'],
            ]))
            ->assertOk()
            ->assertSee('Array Query Playbook')
            ->assertDontSee('Array to string conversion', false);
    }

    public function test_index_array_filters_do_not_500(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Array Filter Piece']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'status' => ['approved'],
                'availability' => ['available'],
                'language' => ['en'],
                'country' => ['us'],
                'q' => ['Array Filter'],
            ]))
            ->assertOk()
            ->assertSee('Array Filter Piece')
            ->assertDontSee('Array to string conversion', false);
    }

    public function test_index_array_upload_and_edit_do_not_500(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'upload' => ['1'],
                'edit' => ['99'],
            ]))
            ->assertOk()
            ->assertSee('Content Library')
            ->assertDontSee('Array to string conversion', false)
            ->assertDontSee('must be of type string', false);
    }

    public function test_index_array_page_does_not_500(): void
    {
        $advertiser = $this->advertiser();
        $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', [
                'page' => ['2'],
            ]))
            ->assertOk()
            ->assertSee('Content Library')
            ->assertDontSee('Array to string conversion', false);
    }

    public function test_index_has_catalog_search_chrome(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library'))
            ->assertOk()
            ->assertSee('for="librarySearchInput">Search</label>', false)
            ->assertSee('id="librarySearchClear"', false)
            ->assertSee('id="librarySearchStatus"', false)
            ->assertSee('id="libraryLiveRegion"', false)
            ->assertDontSee('data-slb-live-search="form"', false)
            ->getContent();
        $this->assertTrue(
            str_contains($html, 'libraryResultsUrl: "/advertiser/content-library/results"')
            || str_contains($html, 'libraryResultsUrl: "\/advertiser\/content-library\/results"'),
            'library results URL should be a same-origin relative path'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/libraryResultsUrl:\s*["\']https?:/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/libraryIndexUrl:\s*["\']https?:/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/libraryUpdateUrl:\s*["\']https?:/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/uploadUrl:\s*["\']https?:/',
            $html
        );
        $this->assertStringNotContainsString('onchange="this.form.submit()"', $html);
        $this->assertStringContainsString('action="/advertiser/content-library"', $html);
    }

    public function test_word_and_requires_every_token_on_title_or_filename(): void
    {
        $advertiser = $this->advertiser();

        $both = $this->createApprovedSubmission($advertiser);
        $both->update([
            'title' => 'Growth Playbook',
            'original_filename' => 'article.docx',
        ]);

        $oneWord = $this->createApprovedSubmission($advertiser);
        $oneWord->update([
            'title' => 'Growth Guide',
            'original_filename' => 'notes.docx',
        ]);

        $filenameHit = $this->createApprovedSubmission($advertiser);
        $filenameHit->update([
            'title' => 'Alpha Draft',
            'original_filename' => 'summer-guide.docx',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['q' => 'Growth Playbook']))
            ->assertOk()
            ->assertSee('Growth Playbook')
            ->assertDontSee('Growth Guide')
            ->assertDontSee('Alpha Draft');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['q' => 'Growth']))
            ->assertOk()
            ->assertSee('Growth Playbook')
            ->assertSee('Growth Guide')
            ->assertDontSee('Alpha Draft');

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.results', ['q' => 'summer guide']))
            ->assertOk()
            ->assertSee('Alpha Draft')
            ->assertDontSee('Growth Playbook')
            ->assertDontSee('Growth Guide');
    }

    public function test_single_page_still_shows_catalog_pager_count(): void
    {
        $advertiser = $this->advertiser();
        for ($i = 1; $i <= 5; $i++) {
            $article = $this->createApprovedSubmission($advertiser, null, $i);
            $article->update(['title' => 'Pager Five '.$i]);
        }

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.results'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-pagination__meta', $html);
        $this->assertStringContainsString('Showing', $html);
        $this->assertStringContainsString('1–5', $html);
        $this->assertStringContainsString('of <strong>5</strong>', $html);
        $this->assertStringContainsString('articles', $html);
        $this->assertStringContainsString('Page 1 of 1', $html);
        $this->assertStringContainsString('catalog-pagination__links', $html);
        $this->assertStringContainsString('aria-label="Library pages"', $html);
        $this->assertStringContainsString('href="/advertiser/content-library?', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/library-status-box[^>]+href="https?:/',
            $html
        );
    }

    public function test_page_two_keeps_search_query_on_pager_links(): void
    {
        $advertiser = $this->advertiser();
        for ($i = 1; $i <= 21; $i++) {
            $article = $this->createApprovedSubmission($advertiser, null, $i);
            $article->update(['title' => 'PagerUnique '.$i]);
        }

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.results', [
                'q' => 'PagerUnique',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertSee('PagerUnique 1')
            ->getContent();

        $this->assertStringContainsString('21–21', $html);
        $this->assertStringContainsString('of <strong>21</strong>', $html);
        $this->assertStringContainsString('Page 2 of 2', $html);
        $this->assertStringContainsString('q=PagerUnique', $html);
        $this->assertStringContainsString('page=1', $html);
        $this->assertStringContainsString(route('advertiser.content-library', absolute: false), $html);
        $this->assertStringNotContainsString('/content-library/results?', $html);
    }

    public function test_results_fragment_row_actions_use_same_origin_paths(): void
    {
        $advertiser = $this->advertiser();
        $article = $this->createApprovedSubmission($advertiser);
        $article->update(['title' => 'Orderable Piece']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.results'))
            ->assertOk()
            ->assertSee('Orderable Piece')
            ->getContent();

        $orderPath = route('advertiser.content-library.order', $article, false);
        $this->assertStringContainsString('href="'.$orderPath.'"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/href="https?:[^"]*content-library\/'.$article->id.'\/order"/',
            $html
        );
    }

    public function test_library_js_keeps_ui_filter_keys_and_modifier_clicks(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/content-library.js'));

        $this->assertStringContainsString('function libraryModifiedClick', $js);
        $this->assertStringContainsString('if (libraryModifiedClick(e)) return;', $js);
        $this->assertStringContainsString("if (availability === 'published') availability = 'completed';", $js);
        $this->assertStringNotContainsString("if (availability === 'completed') availability = 'published';", $js);
        $this->assertStringContainsString("detail.reason === 'input'", $js);
        $this->assertStringContainsString("detail.reason === 'enter'", $js);
        $this->assertStringContainsString("detail.reason === 'clear'", $js);
        $this->assertStringContainsString("'completed', 'evaluating'", $js);
        $this->assertStringContainsString('function libraryFilenameAsTitle', $js);
        $this->assertStringContainsString('function paintLibraryTitleCell', $js);
        $this->assertStringContainsString('paintLibraryTitleCell(id, data.submission || {}, title);', $js);
        $this->assertStringContainsString('display.textContent = shown;', $js);
        $this->assertStringContainsString("leftover ? 'Untitled'", $js);
        $this->assertStringContainsString('fetchLibraryResults(librarySearchParamsFromForm()', $js);
        $this->assertStringNotContainsString("|| 'Article';", $js);
    }
}
