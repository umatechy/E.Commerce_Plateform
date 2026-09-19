<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Store-owner self-registration (SRS §5 Identity & Authentication
 * Requirements). Creates a User AND its first Store + owner membership
 * + default Owner role, in one guarded transaction (see AuthController).
 */
final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public endpoint — registration itself has no prior auth.
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'store_name' => ['required', 'string', 'max:255'],
        ];
    }
}
