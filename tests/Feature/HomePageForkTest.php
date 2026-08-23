<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\HomePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Which homepage gets served.
 *
 * This existed as the same condition written twice, phrased in terms of a Blade
 * FILE: the React homepage was selected by `view()->exists()` on a template it
 * never renders. Deleting that file during a dead-code sweep would have quietly
 * reverted the live homepage to the old Blade one — while the middleware's copy
 * of the condition, which had no existence check, kept telling the client to
 * navigate there as a single-page app. This test is why that cannot happen.
 */
class HomePageForkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_couture_setting_serves_the_react_homepage(): void
    {
        Setting::put('theme', ['homepage_template' => 'couture']);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Home'));
    }

    public function test_the_client_is_told_the_homepage_is_spa_navigable(): void
    {
        Setting::put('theme', ['homepage_template' => 'couture']);

        // SmartLink reads this to decide whether "/" can be an Inertia visit.
        // If it disagreed with the controller, clicking Home would ask for an
        // Inertia page from a Blade route.
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('chrome.inertiaHome', true));
    }

    public function test_another_template_serves_blade_and_says_so(): void
    {
        Setting::put('theme', ['homepage_template' => 'storefront']);

        $this->get('/')->assertOk();
        $this->assertFalse(HomePage::isReact('storefront'));
    }

    public function test_the_decision_does_not_depend_on_a_blade_file_existing(): void
    {
        // The trap: it used to. Keyed on the template name now, so the Blade
        // file can be deleted without changing which homepage is served.
        $this->assertTrue(HomePage::isReact('couture'));
        $this->assertFalse(HomePage::isReact('meridian'));
    }
}
