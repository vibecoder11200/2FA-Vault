<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        // A8: the exists-rule deliberately stays (upstream 2FAuth UX parity —
        // "unknown email" vs "wrong password" message). The route is
        // throttled (email+IP keyed), which bounds the enumeration oracle.
        return [
            'email' => [
                'required',
                'email',
                new \App\Rules\CaseInsensitiveEmailExists,
            ],
            'password' => 'required|string',
        ];
    }
}
