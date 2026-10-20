<?php

namespace Tests;

use App\Models\Site;
use App\Services\Partners\PartnerSandbox;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Site::forgetColumnCache();
        PartnerSandbox::forgetColumnCache();
    }
}
