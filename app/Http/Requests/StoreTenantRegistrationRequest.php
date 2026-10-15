<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'laboratory_name' => ['required', 'string', 'max:150'],
            'admin_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:63',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn($this->reservedSlugs()),
                Rule::unique('tenants', 'id'),
                Rule::unique('domains', 'domain')
                    ->where('domain', $this->input('slug').'.'.config('tenancy.central_domain')),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'laboratory_name.required' => 'Informe o nome do laboratório.',
            'admin_name.required' => 'Informe o nome do administrador.',
            'email.required' => 'Informe um e-mail válido.',
            'slug.required' => 'Informe o endereço do laboratório.',
            'slug.regex' => 'O endereço deve conter apenas letras minúsculas, números e hífens.',
            'slug.not_in' => 'Este endereço está reservado e não pode ser utilizado.',
            'slug.unique' => 'Este endereço já está em uso.',
            'password.required' => 'Informe uma senha.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.confirmed' => 'A confirmação da senha não corresponde.',
        ];
    }

    /**
     * @return list<string>
     */
    private function reservedSlugs(): array
    {
        $centralDomainLabels = array_map(
            static fn (string $domain): string => explode('.', $domain)[0],
            config('tenancy.central_domains'),
        );

        return array_values(array_unique([
            'admin',
            'api',
            'app',
            'mail',
            'www',
            ...$centralDomainLabels,
        ]));
    }
}
