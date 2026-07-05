<?php

namespace App\Modules\Ai;

use App\Support\Modules\GenericModuleManifest;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleServiceProvider;

/**
 * AI Assistant module — captions, hashtags, ad copy, reply suggestions, product
 * descriptions, SEO and summaries. No Meta permissions. Registered but not yet
 * built; when built it will bind the AiAssistant contract for other modules.
 */
class AiServiceProvider extends ModuleServiceProvider
{
    protected function manifest(): ModuleManifest
    {
        return new GenericModuleManifest([
            'key' => 'ai',
            'name' => 'AI Assistant',
            'description' => 'Generate captions, hashtags, ad copy, replies, product descriptions and SEO.',
            'icon' => 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z',
            'provider' => 'internal',
            'scopes' => [],
            'permissions' => ['ai.access'],
            'route' => null,
            'available' => false,
        ]);
    }
}
