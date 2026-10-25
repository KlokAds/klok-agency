<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // The team's email is their sign-in name, changed only on the Users page. A client keeps
        // their own: name, email, phone and company.
        if (! $this->user()?->isClient()) {
            return ['name' => ['required', 'string', 'max:120']];
        }

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'phone' => ['nullable', 'string', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'company' => ['nullable', 'string', 'max:120'],
        ];
    }
}
