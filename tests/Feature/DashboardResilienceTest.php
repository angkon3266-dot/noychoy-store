<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The dashboard's deep panels are decoration. A migration that hasn't applied,
 * or one odd row, must cost you that panel — not the whole page.
 */
class DashboardResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);
    }

    public function test_the_dashboard_loads_normally(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertOk();
    }

    public function test_it_still_loads_when_the_visits_table_is_absent(): void
    {
        // Exactly what a server looks like before the visits migration runs.
        Schema::drop('visits');

        $this->actingAs($this->admin())->get('/admin')->assertOk();
    }

    public function test_it_still_loads_when_order_attribution_columns_are_absent(): void
    {
        Schema::table('orders', function ($table) {
            $table->dropIndex(['created_at', 'source_channel']);
            $table->dropColumn(['source_channel', 'source_campaign']);
        });

        $this->actingAs($this->admin())->get('/admin')->assertOk();
    }
}
