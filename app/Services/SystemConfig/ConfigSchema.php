<?php

namespace App\Services\SystemConfig;

/**
 * Declarative schema for every editable configuration field — the single source
 * of truth used by the UI, validation, encryption, masking and runtime
 * application. Adding a new setting is a one-line change here.
 *
 * Each field:
 *   key        unique dotted id (also the system_configs.key within its section)
 *   label      UI label
 *   type       text|password|number|bool|select|email|textarea
 *   config     Laravel config() dot-path to override at runtime (null = not
 *              applied to runtime config; e.g. the DB section lives in .env)
 *   env        the .env key it falls back to (for display/reference)
 *   sensitive  true = stored encrypted + masked in the UI
 *   options    for select types
 *   coming_soon true = shown but not yet wired to a live service
 *
 * Section meta:
 *   env_managed true  = persisted to .env via the Test→Apply→Rollback wizard
 *                       (database connection only — it cannot bootstrap from DB)
 *   test        a ConnectionTester group key, or null
 */
class ConfigSchema
{
    /** @return array<string, array> */
    public function sections(): array
    {
        return [
            'general' => [
                'label' => 'General',
                'description' => 'Application name, URL and environment.',
                'fields' => [
                    ['key' => 'app.name', 'label' => 'App name', 'type' => 'text', 'config' => 'app.name', 'env' => 'APP_NAME'],
                    ['key' => 'app.url', 'label' => 'App URL', 'type' => 'text', 'config' => 'app.url', 'env' => 'APP_URL'],
                    ['key' => 'app.env', 'label' => 'Environment', 'type' => 'select', 'config' => 'app.env', 'env' => 'APP_ENV', 'options' => ['production', 'staging', 'local']],
                    ['key' => 'app.debug', 'label' => 'Debug mode', 'type' => 'bool', 'config' => 'app.debug', 'env' => 'APP_DEBUG'],
                    ['key' => 'app.timezone', 'label' => 'Timezone', 'type' => 'text', 'config' => 'app.timezone', 'env' => 'APP_TIMEZONE'],
                    ['key' => 'app.locale', 'label' => 'Locale', 'type' => 'text', 'config' => 'app.locale', 'env' => 'APP_LOCALE'],
                ],
            ],

            'database' => [
                'label' => 'Database',
                'description' => 'Primary database connection. Changes are tested before they are applied and rolled back automatically on failure.',
                'env_managed' => true,
                'test' => 'database',
                'fields' => [
                    ['key' => 'db.host', 'label' => 'Host', 'type' => 'text', 'config' => null, 'env' => 'DB_HOST'],
                    ['key' => 'db.port', 'label' => 'Port', 'type' => 'number', 'config' => null, 'env' => 'DB_PORT'],
                    ['key' => 'db.database', 'label' => 'Database', 'type' => 'text', 'config' => null, 'env' => 'DB_DATABASE'],
                    ['key' => 'db.username', 'label' => 'Username', 'type' => 'text', 'config' => null, 'env' => 'DB_USERNAME'],
                    ['key' => 'db.password', 'label' => 'Password', 'type' => 'password', 'config' => null, 'env' => 'DB_PASSWORD', 'sensitive' => true],
                ],
            ],

            'mail' => [
                'label' => 'Mail (SMTP)',
                'description' => 'Outgoing email settings.',
                'test' => 'smtp',
                'fields' => [
                    ['key' => 'mail.mailer', 'label' => 'Mailer', 'type' => 'select', 'config' => 'mail.default', 'env' => 'MAIL_MAILER', 'options' => ['smtp', 'log', 'sendmail']],
                    ['key' => 'mail.host', 'label' => 'Host', 'type' => 'text', 'config' => 'mail.mailers.smtp.host', 'env' => 'MAIL_HOST'],
                    ['key' => 'mail.port', 'label' => 'Port', 'type' => 'number', 'config' => 'mail.mailers.smtp.port', 'env' => 'MAIL_PORT'],
                    ['key' => 'mail.username', 'label' => 'Username', 'type' => 'text', 'config' => 'mail.mailers.smtp.username', 'env' => 'MAIL_USERNAME'],
                    ['key' => 'mail.password', 'label' => 'Password', 'type' => 'password', 'config' => 'mail.mailers.smtp.password', 'env' => 'MAIL_PASSWORD', 'sensitive' => true],
                    ['key' => 'mail.encryption', 'label' => 'Encryption', 'type' => 'select', 'config' => 'mail.mailers.smtp.encryption', 'env' => 'MAIL_ENCRYPTION', 'options' => ['tls', 'ssl', 'none']],
                    ['key' => 'mail.from_address', 'label' => 'From address', 'type' => 'email', 'config' => 'mail.from.address', 'env' => 'MAIL_FROM_ADDRESS'],
                    ['key' => 'mail.from_name', 'label' => 'From name', 'type' => 'text', 'config' => 'mail.from.name', 'env' => 'MAIL_FROM_NAME'],
                ],
            ],

            'queue' => [
                'label' => 'Queue',
                'description' => 'Background job driver.',
                'test' => 'queue',
                'fields' => [
                    ['key' => 'queue.default', 'label' => 'Queue driver', 'type' => 'select', 'config' => 'queue.default', 'env' => 'QUEUE_CONNECTION', 'options' => ['database', 'redis', 'sync']],
                ],
            ],

            'cache' => [
                'label' => 'Cache',
                'description' => 'Cache store.',
                'fields' => [
                    ['key' => 'cache.default', 'label' => 'Cache driver', 'type' => 'select', 'config' => 'cache.default', 'env' => 'CACHE_STORE', 'options' => ['database', 'redis', 'file', 'array']],
                ],
            ],

            'redis' => [
                'label' => 'Redis',
                'description' => 'Redis server (used by cache/queue when selected).',
                'test' => 'redis',
                'fields' => [
                    ['key' => 'redis.host', 'label' => 'Host', 'type' => 'text', 'config' => 'database.redis.default.host', 'env' => 'REDIS_HOST'],
                    ['key' => 'redis.port', 'label' => 'Port', 'type' => 'number', 'config' => 'database.redis.default.port', 'env' => 'REDIS_PORT'],
                    ['key' => 'redis.password', 'label' => 'Password', 'type' => 'password', 'config' => 'database.redis.default.password', 'env' => 'REDIS_PASSWORD', 'sensitive' => true],
                ],
            ],

            'sms' => [
                'label' => 'SMS (KhudeBarta)',
                'description' => 'SMS gateway credentials. Also editable under Integrations.',
                'test' => 'sms',
                'fields' => [
                    ['key' => 'sms.api_key', 'label' => 'API key', 'type' => 'password', 'config' => 'sms.api_key', 'env' => 'KHUDEBARTA_API_KEY', 'sensitive' => true],
                    ['key' => 'sms.secret_key', 'label' => 'Secret key', 'type' => 'password', 'config' => 'sms.secret_key', 'env' => 'KHUDEBARTA_SECRET_KEY', 'sensitive' => true],
                    ['key' => 'sms.sender_id', 'label' => 'Sender ID', 'type' => 'text', 'config' => 'sms.sender_id', 'env' => 'KHUDEBARTA_CALLER_ID'],
                    ['key' => 'sms.base_url', 'label' => 'Base URL', 'type' => 'text', 'config' => 'sms.base_url', 'env' => 'KHUDEBARTA_BASE_URL'],
                ],
            ],

            'payment' => [
                'label' => 'Payment',
                'description' => 'Payment gateways (wiring coming soon — values are stored securely).',
                'fields' => [
                    ['key' => 'payment.sslcommerz_store_id', 'label' => 'SSLCommerz Store ID', 'type' => 'text', 'config' => 'payment.sslcommerz.store_id', 'env' => 'SSLCZ_STORE_ID', 'coming_soon' => true],
                    ['key' => 'payment.sslcommerz_store_password', 'label' => 'SSLCommerz Store Password', 'type' => 'password', 'config' => 'payment.sslcommerz.store_password', 'sensitive' => true, 'coming_soon' => true],
                    ['key' => 'payment.bkash_key', 'label' => 'bKash App Key', 'type' => 'text', 'config' => 'payment.bkash.key', 'coming_soon' => true],
                    ['key' => 'payment.bkash_secret', 'label' => 'bKash App Secret', 'type' => 'password', 'config' => 'payment.bkash.secret', 'sensitive' => true, 'coming_soon' => true],
                    ['key' => 'payment.nagad_merchant_id', 'label' => 'Nagad Merchant ID', 'type' => 'text', 'config' => 'payment.nagad.merchant_id', 'coming_soon' => true],
                    ['key' => 'payment.stripe_key', 'label' => 'Stripe Publishable Key', 'type' => 'text', 'config' => 'payment.stripe.key', 'coming_soon' => true],
                    ['key' => 'payment.stripe_secret', 'label' => 'Stripe Secret Key', 'type' => 'password', 'config' => 'payment.stripe.secret', 'sensitive' => true, 'coming_soon' => true],
                    ['key' => 'payment.paypal_client_id', 'label' => 'PayPal Client ID', 'type' => 'text', 'config' => 'payment.paypal.client_id', 'coming_soon' => true],
                    ['key' => 'payment.paypal_secret', 'label' => 'PayPal Secret', 'type' => 'password', 'config' => 'payment.paypal.secret', 'sensitive' => true, 'coming_soon' => true],
                ],
            ],

            'storage' => [
                'label' => 'Storage',
                'description' => 'File storage disk (Public, S3, Cloudflare R2).',
                'test' => 'storage',
                'fields' => [
                    ['key' => 'storage.default', 'label' => 'Default disk', 'type' => 'select', 'config' => 'filesystems.default', 'env' => 'FILESYSTEM_DISK', 'options' => ['public', 'local', 's3']],
                    ['key' => 'storage.s3_key', 'label' => 'S3/R2 Access Key', 'type' => 'password', 'config' => 'filesystems.disks.s3.key', 'env' => 'AWS_ACCESS_KEY_ID', 'sensitive' => true],
                    ['key' => 'storage.s3_secret', 'label' => 'S3/R2 Secret', 'type' => 'password', 'config' => 'filesystems.disks.s3.secret', 'env' => 'AWS_SECRET_ACCESS_KEY', 'sensitive' => true],
                    ['key' => 'storage.s3_region', 'label' => 'Region', 'type' => 'text', 'config' => 'filesystems.disks.s3.region', 'env' => 'AWS_DEFAULT_REGION'],
                    ['key' => 'storage.s3_bucket', 'label' => 'Bucket', 'type' => 'text', 'config' => 'filesystems.disks.s3.bucket', 'env' => 'AWS_BUCKET'],
                    ['key' => 'storage.s3_endpoint', 'label' => 'Endpoint (R2/custom)', 'type' => 'text', 'config' => 'filesystems.disks.s3.endpoint', 'env' => 'AWS_ENDPOINT'],
                ],
            ],

            'google' => [
                'label' => 'Google',
                'description' => 'Analytics & OAuth.',
                'fields' => [
                    ['key' => 'google.analytics_id', 'label' => 'GA4 Measurement ID', 'type' => 'text', 'config' => 'services.google.analytics_id', 'env' => 'GOOGLE_ANALYTICS_ID'],
                    ['key' => 'google.oauth_client_id', 'label' => 'OAuth Client ID', 'type' => 'text', 'config' => 'services.google.client_id', 'env' => 'GOOGLE_CLIENT_ID'],
                    ['key' => 'google.oauth_client_secret', 'label' => 'OAuth Client Secret', 'type' => 'password', 'config' => 'services.google.client_secret', 'env' => 'GOOGLE_CLIENT_SECRET', 'sensitive' => true],
                ],
            ],

            'meta' => [
                'label' => 'Meta',
                'description' => 'Meta App credentials for "Connect with Facebook" (Production OAuth). Set these here instead of .env.',
                'test' => 'meta',
                'fields' => [
                    ['key' => 'meta.app_id', 'label' => 'App ID', 'type' => 'text', 'config' => 'meta.oauth.app_id', 'env' => 'META_APP_ID'],
                    ['key' => 'meta.app_secret', 'label' => 'App Secret', 'type' => 'password', 'config' => 'meta.oauth.app_secret', 'env' => 'META_APP_SECRET', 'sensitive' => true],
                    ['key' => 'meta.login_config_id', 'label' => 'Login for Business Config ID', 'type' => 'text', 'config' => 'meta.oauth.config_id', 'env' => 'META_LOGIN_CONFIG_ID'],
                    ['key' => 'meta.webhook_secret', 'label' => 'Webhook Verify Token', 'type' => 'password', 'config' => 'meta.webhook_verify_token', 'env' => 'META_WEBHOOK_VERIFY_TOKEN', 'sensitive' => true],
                ],
            ],

            'security' => [
                'label' => 'Security',
                'description' => 'Session and password policy.',
                'fields' => [
                    ['key' => 'security.session_lifetime', 'label' => 'Session lifetime (minutes)', 'type' => 'number', 'config' => 'session.lifetime', 'env' => 'SESSION_LIFETIME'],
                    ['key' => 'security.session_secure', 'label' => 'Secure (HTTPS-only) cookies', 'type' => 'bool', 'config' => 'session.secure', 'env' => 'SESSION_SECURE_COOKIE'],
                    ['key' => 'security.password_min', 'label' => 'Minimum password length', 'type' => 'number', 'config' => 'security.password_min', 'env' => null],
                ],
            ],

            'seo' => [
                'label' => 'SEO',
                'description' => 'Default meta tags & indexing.',
                'fields' => [
                    ['key' => 'seo.default_title', 'label' => 'Default meta title', 'type' => 'text', 'config' => 'seo.default_title', 'env' => null],
                    ['key' => 'seo.default_description', 'label' => 'Default meta description', 'type' => 'textarea', 'config' => 'seo.default_description', 'env' => null],
                    ['key' => 'seo.robots', 'label' => 'Robots policy', 'type' => 'select', 'config' => 'seo.robots', 'env' => null, 'options' => ['index', 'noindex']],
                    ['key' => 'seo.sitemap_enabled', 'label' => 'Sitemap enabled', 'type' => 'bool', 'config' => 'seo.sitemap_enabled', 'env' => null],
                ],
            ],
        ];
    }

    public function hasSection(string $section): bool
    {
        return isset($this->sections()[$section]);
    }

    public function section(string $section): ?array
    {
        return $this->sections()[$section] ?? null;
    }

    /** All fields flattened, indexed by key. */
    public function fields(): array
    {
        $out = [];
        foreach ($this->sections() as $sectionKey => $section) {
            foreach ($section['fields'] as $field) {
                $field['section'] = $sectionKey;
                $out[$field['key']] = $field;
            }
        }

        return $out;
    }

    public function field(string $key): ?array
    {
        return $this->fields()[$key] ?? null;
    }

    /** Keys of sensitive fields (encrypted + masked). */
    public function sensitiveKeys(): array
    {
        return array_keys(array_filter($this->fields(), fn ($f) => ! empty($f['sensitive'])));
    }
}
