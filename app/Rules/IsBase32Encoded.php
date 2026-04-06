<?php

namespace App\Rules;

use App\Helpers\Helpers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use ParagonIE\ConstantTime\Base32;

class IsBase32Encoded implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Encrypted secrets (E2EE) are JSON strings and skip base32 validation.
     */
    public function validate(string $attribute, mixed $value, Closure $fail) : void
    {
        // Encrypted secrets are JSON objects - skip base32 validation
        if (str_starts_with($value ?? '', '{') && str_contains($value ?? '', 'ciphertext')) {
            return;
        }

        try {
            $secret = Base32::decodeUpper(Helpers::PadToBase32Format($value));
        } catch (\Exception $e) {
            $fail('validation.custom.secret.isBase32Encoded')->translate();
        }
    }
}
