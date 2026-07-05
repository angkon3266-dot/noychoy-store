<?php

namespace App\Modules\Inbox;

use App\Support\Modules\GenericModuleManifest;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Unified Inbox module — Messenger, Instagram DM, comments & mentions with
 * reply/assign/notes. Registered (scopes declared) but not yet built.
 */
class InboxServiceProvider extends ModuleServiceProvider
{
    protected function manifest(): ModuleManifest
    {
        return new GenericModuleManifest([
            'key' => 'inbox',
            'name' => 'Unified Inbox',
            'description' => 'Messenger, Instagram DMs, comments & mentions in one shared inbox.',
            'icon' => 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
            'provider' => 'meta',
            'scopes' => ['pages_messaging', 'instagram_manage_messages', 'instagram_manage_comments'],
            'config_id' => env('META_LOGIN_CONFIG_ID_INBOX'),
            'permissions' => ['inbox.access'],
            'route' => null,
            'available' => false,
        ]);
    }
}
