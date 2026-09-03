<?php

namespace App\Models\Traits;

use App\Facades\Settings;
use Illuminate\Support\Facades\Crypt;

trait CanEncryptField
{
    /**
     * Override to true in a model whose fields must ALWAYS be encrypted at
     * rest, regardless of the global useEncryption setting (C11).
     */
    protected function alwaysEncryptFields(): bool
    {
        return false;
    }

    /**
     * Returns an acceptable value
     */
    private function decryptOrReturn(mixed $value) : mixed
    {
        // Decipher when needed
        if (($this->alwaysEncryptFields() || Settings::get('useEncryption')) && $value) {
            try {
                return Crypt::decryptString($value);
            } catch (\Exception $ex) {
                // When encryption is mandatory for this model, a value that is
                // not a Crypt envelope is a pre-encryption legacy row written
                // before the always-on behavior — return it verbatim instead
                // of destroying it with an error placeholder.
                if ($this->alwaysEncryptFields() && is_string($value) && str_starts_with($value, '{')) {
                    return $value;
                }

                return __('error.indecipherable');
            }
        } else {
            return $value;
        }
    }

    /**
     * Encrypt a value
     */
    private function encryptOrReturn(mixed $value) : mixed
    {
        // should be replaced by laravel 8 attribute encryption casting
        return ($this->alwaysEncryptFields() || Settings::get('useEncryption')) ? Crypt::encryptString($value) : $value;
    }
}
