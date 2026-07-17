<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Bangladeshi mobile number: optional +880/880 prefix, then 01[3-9] and eight
 * digits. The single source of truth for the format — controllers should pair
 * it with bd_phone() to canonicalise before storing/matching.
 */
class BdPhone implements ValidationRule
{
    public const PATTERN = '/^(\+?880|0)1[3-9]\d{8}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::PATTERN, preg_replace('/[\s-]+/', '', $value))) {
            $fail('Please enter a valid Bangladeshi mobile number (01XXXXXXXXX).');
        }
    }
}
