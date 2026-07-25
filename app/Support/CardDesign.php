<?php

namespace App\Support;

/**
 * Design tokens for the printed thank-you card. Everything is admin-editable
 * under Appearance → Cards & print; this turns those settings into the concrete
 * CSS the print sheet uses, so the preview and the print stay in sync.
 */
class CardDesign
{
    /** Font stacks offered in the picker. */
    public const FONTS = [
        'serif' => "Georgia, 'Times New Roman', serif",
        'garamond' => "'EB Garamond', Garamond, Georgia, serif",
        'sans' => "'Helvetica Neue', Helvetica, Arial, sans-serif",
        'geometric' => "'Century Gothic', 'Futura', 'Trebuchet MS', sans-serif",
        'script' => "'Segoe Script', 'Brush Script MT', cursive",
        'mono' => "'Courier New', Courier, monospace",
    ];

    public static function fontStack(): string
    {
        $key = (string) theme('card_font', 'serif');

        if ($key === 'custom' && filled(theme('card_font_custom'))) {
            return (string) theme('card_font_custom');
        }

        return self::FONTS[$key] ?? self::FONTS['serif'];
    }

    /**
     * Base type size in points for a card of $w × $h mm. Scales with the card so
     * a 40 mm and a 100 mm card both read well, then the admin's scale applies.
     */
    public static function baseSize(int $w, int $h): float
    {
        $scale = max(50, min(200, (int) theme('card_font_scale', 100))) / 100;

        return round(min($w, $h) * 0.16 * $scale, 2);
    }

    /**
     * CSS for the card itself. $prefix scopes the rules (e.g. '.sheet ') so the
     * same generator can style a preview without leaking into the page chrome.
     */
    public static function css(int $w, int $h, string $prefix = ''): string
    {
        $base = self::baseSize($w, $h);
        $font = self::fontStack();
        $lh = max(100, min(250, (int) theme('card_line_height', 150))) / 100;
        $ls = (int) theme('card_letter_spacing', 0) / 100;
        $gap = max(0, min(20, (float) theme('card_gap', 4)));
        $pad = max(0, min(25, (float) theme('card_padding', 6)));
        $align = in_array(theme('card_align'), ['left', 'center', 'right'], true) ? theme('card_align') : 'center';
        $valign = match (theme('card_valign')) {
            'top' => 'flex-start',
            'bottom' => 'flex-end',
            default => 'center',
        };
        $flexAlign = match ($align) {
            'left' => 'flex-start',
            'right' => 'flex-end',
            default => 'center',
        };
        $border = in_array(theme('card_border'), ['none', 'dashed', 'dotted', 'solid', 'double'], true)
            ? theme('card_border') : 'dashed';
        $bw = max(1, min(6, (int) theme('card_border_width', 1)));
        $inset = max(0, min(10, (float) theme('card_border_inset', 2)));
        $logoH = max(5, min(60, (int) theme('card_logo_height', 18)));
        $upper = theme('card_uppercase') ? 'uppercase' : 'none';

        $borderRule = $border === 'none'
            ? 'border: 0;'
            : 'border: '.$bw.'px '.$border.' '.self::e(theme('card_border_color', '#c9ad74')).';';

        // Heredocs interpolate variables only, so every computed value is a var.
        $bg = self::e(theme('card_bg', '#ffffff'));
        $fg = self::e(theme('card_text_color', '#161618'));
        $msgSize = self::pt($base);
        $brandSize = self::pt($base + 2.5);
        $longSize = self::pt($base * 0.9);
        $xlongSize = self::pt($base * 0.79);

        return <<<CSS
        {$prefix}.card {
            width: {$w}mm; height: {$h}mm; padding: {$pad}mm;
            background: {$bg};
            color: {$fg};
            font-family: {$font};
            display: flex; flex-direction: column;
            align-items: {$flexAlign}; justify-content: {$valign};
            text-align: {$align};
            break-inside: avoid; page-break-inside: avoid; overflow: hidden; position: relative;
        }
        {$prefix}.card .frame {
            position: absolute; inset: {$inset}mm; pointer-events: none;
            {$borderRule}
        }
        {$prefix}.card .logo {
            max-height: {$logoH}%; max-width: 70%; object-fit: contain; margin-bottom: {$gap}mm;
        }
        {$prefix}.card .brand {
            font-size: {$brandSize}; letter-spacing: 0.06em; text-transform: uppercase;
            margin-bottom: {$gap}mm;
        }
        {$prefix}.card .msg {
            font-size: {$msgSize}; line-height: {$lh}; letter-spacing: {$ls}em;
            text-transform: {$upper}; white-space: pre-line; width: 100%; outline: none;
        }
        {$prefix}.card .msg.long { font-size: {$longSize}; }
        {$prefix}.card .msg.xlong { font-size: {$xlongSize}; }
        CSS;
    }

    /** Character budget before the message steps down a size on this card. */
    public static function textBudget(int $w, int $h): int
    {
        $scale = max(50, min(200, (int) theme('card_font_scale', 100))) / 100;

        return max(40, (int) ($w * $h / 26 / ($scale * $scale)));
    }

    protected static function pt(float $n): string
    {
        return round($n, 2).'pt';
    }

    /** Escape a colour/token before it lands inside a CSS declaration. */
    protected static function e(?string $value): string
    {
        return preg_replace('/[^A-Za-z0-9#(),.% -]/', '', (string) $value) ?: 'inherit';
    }
}
