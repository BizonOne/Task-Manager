<?php

namespace Tests;

use App\Support\Brand;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Brand memoises the settings table in a static, which survives
        // RefreshDatabase — so branding set by one test would leak into the
        // next. Start every test from a clean read.
        Brand::forget();
    }
}
