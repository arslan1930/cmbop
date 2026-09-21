<?php

namespace Tests\Feature;

use App\Support\MarketingCssBundle;
use Tests\TestCase;

/**
 * Hover hints used to come from three different systems — GlassTip on the
 * catalog, Bootstrap tooltips on a handful of pages, and the grey OS tooltip
 * everywhere else. GlassTip is now the only one.
 */
class TooltipConsistencyTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function glassTipJs(): string
    {
        return file_get_contents(public_path('assets/js/glass-tip.js'));
    }

    public function test_no_view_still_uses_a_bootstrap_tooltip(): void
    {
        $offenders = array_values(array_filter(
            $this->bladeFiles(),
            fn (string $path): bool => str_contains(file_get_contents($path), 'data-bs-toggle="tooltip"')
        ));

        $this->assertSame([], $offenders, 'Bootstrap tooltips do not match the GlassTip styling: '
            .implode(', ', array_map('basename', $offenders)));
    }

    public function test_nothing_initialises_bootstrap_tooltips(): void
    {
        $offenders = array_values(array_filter(
            $this->bladeFiles(),
            fn (string $path): bool => str_contains(file_get_contents($path), 'bootstrap.Tooltip')
        ));

        $this->assertSame([], $offenders, 'Found a Bootstrap tooltip initialiser in: '
            .implode(', ', array_map('basename', $offenders)));
    }

    public function test_every_layout_loads_glass_tip(): void
    {
        $layouts = [
            'advertiser/layouts/app.blade.php',
            'publisher/layouts/app.blade.php',
            'admin/layouts/app.blade.php',
            'marketing/layouts/app.blade.php',
            'layouts/app.blade.php',
        ];

        foreach ($layouts as $layout) {
            $markup = file_get_contents(resource_path('views/'.$layout));

            if ($layout === 'layouts/app.blade.php') {
                $this->assertStringContainsString('MarketingCssBundle', $markup);
                $this->assertContains('glass-tip.css', MarketingCssBundle::FILES);
            } else {
                $this->assertStringContainsString('assets/css/glass-tip.css', $markup, "{$layout} must load the tooltip styles.");
            }
            $this->assertStringContainsString('assets/js/glass-tip.js', $markup, "{$layout} must load the tooltip script.");
        }
    }

    public function test_glass_tip_adopts_native_title_attributes(): void
    {
        $js = $this->glassTipJs();

        // Without this sweep the ~120 remaining title attributes would still
        // render as the grey OS tooltip.
        $this->assertStringContainsString("querySelectorAll('[title]')", $js);
        $this->assertStringContainsString('function adoptTitle', $js);
        $this->assertStringContainsString("removeAttribute('title')", $js);
    }

    public function test_glass_tip_leaves_non_tooltip_titles_alone(): void
    {
        $js = $this->glassTipJs();

        // An iframe title names the frame and an input title can carry
        // constraint-validation copy; stealing either changes behaviour.
        foreach (['IFRAME', 'INPUT', 'SELECT', 'TEXTAREA', 'OPTION'] as $tag) {
            $this->assertMatchesRegularExpression(
                '/'.$tag.':\s*1/',
                $js,
                "glass-tip.js must skip <{$tag} title>."
            );
        }

        $this->assertStringContainsString("hasAttribute('data-no-tip')", $js, 'Views need an opt-out hook.');
        $this->assertStringContainsString('#catalogResults, .catalog-results-card', $js);
    }

    public function test_glass_tip_rebinds_after_dynamic_rendering(): void
    {
        $js = $this->glassTipJs();

        // Orders, wallet history and the content library build their rows in JS.
        $this->assertStringContainsString('MutationObserver', $js);
        $this->assertStringContainsString("attributeFilter: ['title']", $js);
    }

    public function test_adopted_titles_do_not_hijack_clicks_or_roles(): void
    {
        $js = $this->glassTipJs();

        $this->assertStringContainsString('if (isAutoAdopted(trigger)) return true;', $js);
        $this->assertStringContainsString('!isAutoAdopted(el) && !NATIVELY_INTERACTIVE[el.tagName]', $js);
    }

    public function test_glass_tip_asset_is_not_duplicated(): void
    {
        // A second copy under public/js drifted out of sync and was never loaded.
        $this->assertFileDoesNotExist(public_path('js/glass-tip.js'));
    }

    public function test_catalog_action_tips_open_away_from_add_to_cart(): void
    {
        $js = $this->glassTipJs();

        $this->assertStringContainsString("closest('.catalog-row-actions, .catalog-td-action, .catalog-card-buy')", $js);
        $this->assertStringContainsString("return 'left'", $js);
        $this->assertStringContainsString('.buy-now, .btn-claim-site, .favorite-btn, .blacklist-btn', $js);
        $this->assertStringContainsString('overflow += 500', $js);
        $this->assertStringContainsString("pointerEvents = isHoverOnlyTip(trigger) ? 'none' : ''", $js);

        $markup = file_get_contents(resource_path('views/advertiser/partials/catalog-results.blade.php'));
        $this->assertGreaterThanOrEqual(2, substr_count($markup, 'class="btn-icon-quiet blacklist-btn'));
        $this->assertGreaterThanOrEqual(2, substr_count($markup, 'class="btn-icon-quiet favorite-btn'));

        preg_match_all('/class="btn-icon-quiet blacklist-btn[\s\S]*?<\/button>/', $markup, $blacklistBtns);
        $this->assertNotEmpty($blacklistBtns[0]);
        foreach ($blacklistBtns[0] as $button) {
            $this->assertStringContainsString('data-glass-tip-placement="left"', $button);
        }

        preg_match_all('/class="btn-icon-quiet favorite-btn[\s\S]*?<\/button>/', $markup, $favoriteBtns);
        $this->assertNotEmpty($favoriteBtns[0]);
        foreach ($favoriteBtns[0] as $button) {
            $this->assertStringContainsString('data-glass-tip-placement="left"', $button);
        }
    }
}
