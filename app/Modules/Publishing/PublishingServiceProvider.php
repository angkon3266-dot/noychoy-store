<?php

namespace App\Modules\Publishing;

use App\Support\Modules\GenericModuleManifest;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Social Publishing module — Facebook Page + Instagram posting, scheduling,
 * content calendar. Registered (scopes declared) but not yet built.
 */
class PublishingServiceProvider extends ModuleServiceProvider
{
    protected function manifest(): ModuleManifest
    {
        return new GenericModuleManifest([
            'key' => 'publishing',
            'name' => 'Social Publishing',
            'description' => 'Publish and schedule posts, carousels, reels and stories to Facebook & Instagram.',
            'icon' => 'M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5',
            'provider' => 'meta',
            'scopes' => ['pages_manage_posts', 'pages_read_engagement', 'instagram_basic', 'instagram_content_publish'],
            'config_id' => config('meta.module_login.publishing'),
            'permissions' => ['publishing.access'],
            'route' => null,
            'available' => false,
        ]);
    }
}
