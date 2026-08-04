<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Settings are memoised in a STATIC property (Setting::$memo) so one request
     * reads them once instead of ~90 times. A static outlives the container, so
     * without this a test that only *reads* settings sees whatever the previous
     * test wrote — the database is refreshed between tests, the memo is not.
     *
     * `Setting::put()` already clears it, which is why this only ever bit tests
     * that read without writing first, and why it stayed hidden for so long.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Setting::flushMemo();
    }
}
