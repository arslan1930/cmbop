<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Support\SiteTag;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteTagTest extends TestCase
{
    #[DataProvider('normalizeProvider')]
    public function test_normalize(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, SiteTag::normalize($input));
    }

    public static function normalizeProvider(): array
    {
        return [
            'empty' => ['', null],
            'none' => ['none', null],
            'sponsored' => ['sponsored', SiteTag::SPONSORED],
            'partner' => ['partner_material', SiteTag::PARTNER],
            'prefer' => ['as_you_prefer', SiteTag::AS_YOU_PREFER],
            'array' => [['sponsored'], SiteTag::SPONSORED],
            'unknown' => ['guest', null],
            'bool' => [true, null],
        ];
    }

    public function test_labels_and_form_options(): void
    {
        $this->assertSame('Sponsored', SiteTag::label('sponsored'));
        $this->assertSame('Partner article', SiteTag::label('partner_material'));
        $this->assertSame('As you prefer', SiteTag::label('as_you_prefer'));
        $this->assertNull(SiteTag::label(null));
        $this->assertSame('No tags', SiteTag::NONE_LABEL);
        $this->assertSame('Listing tag', SiteTag::DETAILS_HEADING);
        $this->assertSame('No listing disclosure tag.', SiteTag::NONE_CHIP_TITLE);

        $publisher = SiteTag::publisherFormOptions();
        $this->assertSame('No tags', $publisher['']);
        $this->assertSame('Partner article', $publisher['partner_material']);

        $staff = SiteTag::staffFormOptions();
        $this->assertSame(['as_you_prefer', 'sponsored', 'partner_material'], array_keys($staff));
        $this->assertArrayNotHasKey('', $staff);
    }

    public function test_from_flags_uses_sponsored_then_partner_priority(): void
    {
        $this->assertSame(SiteTag::SPONSORED, SiteTag::fromFlags(true, true, true));
        $this->assertSame(SiteTag::PARTNER, SiteTag::fromFlags(false, true, true));
        $this->assertSame(SiteTag::AS_YOU_PREFER, SiteTag::fromFlags(false, false, true));
        $this->assertNull(SiteTag::fromFlags(false, false, false));
        $this->assertSame(3, SiteTag::flagCount(true, true, true));
    }

    public function test_apply_exclusive_clears_other_flags(): void
    {
        $site = new Site([
            'sponsored' => true,
            'partner_material' => true,
            'as_you_prefer' => true,
        ]);

        SiteTag::applyExclusive($site, 'partner_material');

        $this->assertFalse((bool) $site->sponsored);
        $this->assertTrue((bool) $site->partner_material);
        $this->assertFalse((bool) $site->as_you_prefer);
        $this->assertSame('partner_material', $site->tagValue());
        $this->assertSame('Partner article', $site->tagLabel());
    }

    public function test_apply_exclusive_none_clears_all_flags(): void
    {
        $site = new Site([
            'sponsored' => true,
            'partner_material' => true,
            'as_you_prefer' => true,
        ]);

        SiteTag::applyExclusive($site, '');

        $this->assertFalse((bool) $site->sponsored);
        $this->assertFalse((bool) $site->partner_material);
        $this->assertFalse((bool) $site->as_you_prefer);
        $this->assertNull($site->tagValue());
        $this->assertSame('No tags', $site->tagLabel(SiteTag::NONE_LABEL));
    }

    public function test_apply_from_request_radio_wins_over_checkboxes(): void
    {
        $site = new Site;
        $request = Request::create('/publisher/sites', 'POST', [
            'site_tag' => 'sponsored',
            'partner_material' => '1',
            'as_you_prefer' => '1',
        ]);

        SiteTag::applyFromRequest($site, $request);

        $this->assertSame('sponsored', $site->tagValue());
        $this->assertFalse((bool) $site->partner_material);
    }

    public function test_legacy_checkbox_request_stays_exclusive(): void
    {
        $site = new Site;
        $request = Request::create('/publisher/sites', 'POST', [
            'sponsored' => '1',
            'partner_material' => '1',
        ]);

        SiteTag::applyFromRequest($site, $request);

        $this->assertSame('sponsored', $site->tagValue());
        $this->assertFalse((bool) $site->partner_material);
    }

    public function test_staff_default_blank_is_as_you_prefer(): void
    {
        $site = new Site;
        SiteTag::applyStaffDefault($site, '');

        $this->assertSame('as_you_prefer', $site->tagValue());
    }

    public function test_exclusive_attribute_patch_collapses_multi_flags(): void
    {
        $patched = SiteTag::exclusiveAttributePatch([
            'site_name' => 'Example',
            'sponsored' => true,
            'partner_material' => true,
            'as_you_prefer' => true,
        ]);

        $this->assertTrue($patched['sponsored']);
        $this->assertFalse($patched['partner_material']);
        $this->assertFalse($patched['as_you_prefer']);
        $this->assertSame('Example', $patched['site_name']);
    }

    public function test_catalog_filter_from_input(): void
    {
        $this->assertSame('sponsored', SiteTag::catalogFilterFromInput('', '1'));
        $this->assertSame('partner_material', SiteTag::catalogFilterFromInput('partner_material', '1'));
        $this->assertSame('none', SiteTag::catalogFilterFromInput('none'));
        $this->assertSame('as_you_prefer', SiteTag::catalogFilterFromInput('as_you_prefer'));
        $this->assertNull(SiteTag::catalogFilterFromInput('guest'));
        $this->assertSame('Sponsored', SiteTag::catalogFilterLabel('sponsored'));
        $this->assertSame('No tags', SiteTag::catalogFilterLabel('none'));
        $this->assertSame('All tags', SiteTag::catalogFilterOptions()['']);
    }
}
