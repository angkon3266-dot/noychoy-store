<?php

namespace App\Modules\Analytics;

use App\Support\Modules\GenericModuleManifest;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Analytics module — READ-ONLY performance dashboards (revenue, ROAS, CTR,
 * reach, engagement, audience). Never manages ads. Registered but not yet built.
 */
class AnalyticsServiceProvider extends ModuleServiceProvider
{
    protected function manifest(): ModuleManifest
    {
        return new GenericModuleManifest([
            'key' => 'analytics',
            'name' => 'Analytics',
            'description' => 'Read-only performance: revenue, ROAS, reach, engagement & audience insights. No ad management.',
            'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
            'provider' => 'meta',
            'scopes' => ['ads_read', 'instagram_manage_insights', 'pages_read_engagement'],
            'config_id' => env('META_LOGIN_CONFIG_ID_ANALYTICS'),
            'permissions' => ['analytics.access'],
            'route' => null,
            'available' => false,
        ]);
    }
}
