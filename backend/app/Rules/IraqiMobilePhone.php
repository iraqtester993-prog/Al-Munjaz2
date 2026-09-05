<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A local Iraqi mobile number used for customer-facing account records.
 *
 * Account sign-in keeps accepting older numbers so legacy accounts are not
 * locked out, while every new or changed account number must use this format.
 */
final class IraqiMobilePhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\A(?:077|078)[0-9]{8}\z/', $value)) {
            $fail(__('The phone number must be exactly 11 digits and start with 077 or 078.'));
        }
    }
}
