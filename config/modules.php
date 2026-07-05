<?php

/*
|--------------------------------------------------------------------------
| Marketing & Commerce Hub — module registry
|--------------------------------------------------------------------------
|
| Each entry is a self-contained module service provider. Adding a module is a
| one-line change here (plus its own app/Modules/<Name>/ directory) — the OAuth
| flow, permission registry and hub UI are all data-driven, so nothing else in
| the platform changes.
|
*/

return [
    'providers' => [
        App\Modules\Commerce\CommerceServiceProvider::class,
        App\Modules\Publishing\PublishingServiceProvider::class,
        App\Modules\Inbox\InboxServiceProvider::class,
        App\Modules\Analytics\AnalyticsServiceProvider::class,
        App\Modules\Ai\AiServiceProvider::class,
        App\Modules\Automation\AutomationServiceProvider::class,
    ],
];
