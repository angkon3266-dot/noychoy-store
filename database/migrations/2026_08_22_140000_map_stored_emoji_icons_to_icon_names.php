<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Trust badges and the homepage feature strip used to be free-text emoji
 * fields, so every existing store has emoji saved in them. Changing the
 * shipped defaults to icon names does nothing for those stores — the stored
 * value wins — so map the emoji anyone would plausibly have chosen onto the
 * equivalent mark in the storefront's own icon set.
 *
 * Anything unrecognised is left exactly as it is: it still renders (the icon
 * component falls back to showing the raw value), and guessing wrong would be
 * worse than leaving the owner one field to re-pick.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private array $map = [
        '🚚' => 'truck', '🚛' => 'truck', '📦' => 'bag', '🛍️' => 'bag', '🛍' => 'bag',
        '💵' => 'cash', '💰' => 'cash', '💸' => 'cash', '🤑' => 'cash',
        '💎' => 'diamond', '💍' => 'diamond',
        '✨' => 'sparkle', '⭐' => 'sparkle', '🌟' => 'sparkle', '★' => 'sparkle',
        '🔒' => 'shieldCheck', '🛡️' => 'shieldCheck', '🛡' => 'shieldCheck', '✅' => 'check',
        '✓' => 'check', '✔️' => 'check', '✔' => 'check', '☑️' => 'check',
        '↩️' => 'check', '↩' => 'check', '🔄' => 'check',
        '🎁' => 'gift', '🎀' => 'gift',
        '📞' => 'phone', '☎️' => 'phone', '📱' => 'phone',
        '🎧' => 'chat', '💬' => 'chat', '🗨️' => 'chat',
        '✉️' => 'mail', '📧' => 'mail', '💌' => 'mail',
        '❤️' => 'heart', '♥️' => 'heart', '💖' => 'heart',
        '🏷️' => 'tag', '🏷' => 'tag',
        '📅' => 'calendar', '🗓️' => 'calendar',
        '🎖️' => 'medal', '🏅' => 'medal', '🥇' => 'medal',
        '🇧🇩' => 'globe', '🌍' => 'globe', '🌏' => 'globe',
        '📍' => 'pin', '💡' => 'bulb', '✍️' => 'pen',
    ];

    public function up(): void
    {
        $this->remap('theme', 'trust_badges');
        $this->remap('home_content', 'feature_strip');
        $this->remap('home_content', 'gift_promises');
    }

    private function remap(string $settingKey, string $listKey): void
    {
        $setting = Setting::get($settingKey);

        if (! is_array($setting) || ! isset($setting[$listKey]) || ! is_array($setting[$listKey])) {
            return;
        }

        $changed = false;
        foreach ($setting[$listKey] as $i => $row) {
            $icon = is_array($row) ? ($row['icon'] ?? null) : null;

            if (is_string($icon) && isset($this->map[trim($icon)])) {
                $setting[$listKey][$i]['icon'] = $this->map[trim($icon)];
                $changed = true;
            }
        }

        if ($changed) {
            Setting::put($settingKey, $setting);
        }
    }

    public function down(): void
    {
        // One-way on purpose: the emoji carried no more meaning than the icon
        // name does, and restoring them would undo a deliberate brand fix.
    }
};
