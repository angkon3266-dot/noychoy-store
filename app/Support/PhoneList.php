<?php

namespace App\Support;

/**
 * Reads a pasted list of Bangladeshi mobile numbers.
 *
 * The owner pastes numbers out of a chat, a spreadsheet or her own head, so
 * one line can be "Nadia 01712345678", "+880 1712-345678", "8801712345678,
 * 01812345678" or "01712 345678" — the same number a dozen ways. Everything
 * here is judged after `bd_phone()` canonicalises it, and a token that cannot
 * be read is handed back rather than dropped: a number silently skipped is a
 * customer who was promised something and does not get it (or, for the block
 * list, one who was meant to be refused and is not).
 *
 * Extracted from CouponController on 2026-09-18 so the blocked-numbers screen
 * reads its box exactly the same way.
 */
class PhoneList
{
    /**
     * @return array{0: array<string, ?string>, 1: list<string>} [canonical phone => name, unreadable]
     */
    public static function read(string $text): array
    {
        $numbers = [];
        $unreadable = [];
        $isMobile = fn (string $digits) => (bool) preg_match('/^01[3-9]\d{8}$/', bd_phone($digits));
        $isDigits = fn (string $token) => (bool) preg_match('/^[+(]*\d[\d().+-]*$/', $token);

        foreach (preg_split('/[\r\n,;]+/', $text) as $entry) {
            // "Nadia-01712345678" is a name and a number, not one word. Marks
            // count as letters: a Bengali name can end in a vowel sign.
            $spaced = preg_replace('/(?<=[\pL\pM])[-–—]*(?=[+(]?\d)|(?<=\d)(?=[\pL\pM])/u', ' ', $entry) ?? $entry;
            $tokens = preg_split('/[\s|:–—]+/u', trim($spaced), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            $found = [];
            $words = [];
            $leftBefore = count($unreadable);

            for ($i = 0, $n = count($tokens); $i < $n; $i++) {
                if (! $isDigits($tokens[$i])) {
                    $words[] = $tokens[$i];

                    continue;
                }

                // Join this digit group with the ones after it until they make
                // a number, giving up once longer than any number can be. This
                // is what lets "+880 1712 345678" and "01712 345678" read as
                // one number rather than three unreadable scraps.
                $joined = '';
                for ($j = $i; $j < $n && $isDigits($tokens[$j]); $j++) {
                    $joined .= $tokens[$j];

                    if ($isMobile($joined)) {
                        $found[] = bd_phone($joined);
                        $i = $j;

                        continue 2;
                    }
                    if (strlen(preg_replace('/\D/', '', $joined)) > 14) {
                        break;
                    }
                }

                $unreadable[] = $tokens[$i];
            }

            if ($found === [] && $words !== [] && count($unreadable) === $leftBefore) {
                $unreadable[] = trim($entry);
            }

            // The separators are already split out above; trimming the dashes
            // here as well would be byte-wise, and can cut a Bengali letter in half.
            $name = count($found) === 1 ? trim(implode(' ', $words), " \t-") : '';

            foreach ($found as $phone) {
                $numbers[$phone] = $name !== '' ? mb_substr($name, 0, 120) : ($numbers[$phone] ?? null);
            }
        }

        return [$numbers, array_values(array_unique($unreadable))];
    }
}
