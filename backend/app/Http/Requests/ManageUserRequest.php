<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManageUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'superadmin';
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $userId = $this->route('id');
        $user = $isCreate || ! $userId ? null : User::with(['siswa', 'guru'])->find($userId);
        $role = $isCreate ? $this->input('role') : $user?->role;
        $siswaId = $user?->siswa?->id;
        $guruId = $user?->guru?->id;

        return [
            'username' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'max:100',
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:255'],
            'password' => [$isCreate ? 'required' : 'sometimes', 'string', 'min:6', 'max:255'],
            'role' => $isCreate ? ['required', Rule::in(User::ROLES)] : ['prohibited'],
            'aktif' => ['sometimes', 'boolean'],
            'nis' => $role === 'siswa'
                ? [Rule::requiredIf($isCreate), 'nullable', 'string', 'max:30', Rule::unique('siswa', 'nis')->ignore($siswaId)]
                : ['prohibited'],
            'kelas_id' => $role === 'siswa'
                ? [Rule::requiredIf($isCreate), 'nullable', 'exists:kelas,id']
                : ['prohibited'],
            'nip' => $role === 'guru'
                ? ['nullable', 'string', 'max:50', Rule::unique('guru', 'nip')->ignore($guruId)]
                : ['prohibited'],
        ];
    }
}
