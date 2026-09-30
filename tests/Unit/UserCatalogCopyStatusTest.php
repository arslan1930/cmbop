<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserCatalogCopyStatusTest extends TestCase
{
    public function test_catalog_copy_status_label_matches_ladder(): void
    {
        $clean = new User;
        $this->assertNull($clean->catalogCopyStatusLabel());

        $warned = (new User)->forceFill(['catalog_copy_strike_count' => 1]);
        $this->assertSame(User::CATALOG_COPY_WARNED, $warned->catalogCopyStatus());
        $this->assertSame('Copy warned', $warned->catalogCopyStatusLabel());

        $post = (new User)->forceFill(['catalog_copy_strike_count' => 2]);
        $this->assertSame(User::CATALOG_COPY_POST_HIDE, $post->catalogCopyStatus());
        $this->assertSame('Hide served', $post->catalogCopyStatusLabel());

        $hidden = (new User)->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addHour(),
        ]);
        $this->assertTrue($hidden->inCatalogHideMode());
        $this->assertSame('Catalog hidden', $hidden->catalogCopyStatusLabel());
    }
}
