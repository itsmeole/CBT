<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\LogAktivitas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfilTest extends TestCase
{
    use RefreshDatabase;

    private function masuk(string $role, array $atribut = []): User
    {
        $user = User::factory()->role($role)->create(array_merge(['username' => 'lama', 'password' => 'rahasia123'], $atribut));
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(array $ubah = []): array
    {
        return array_merge(['nama' => 'Nama Baru', 'username' => 'lama', 'email' => null], $ubah);
    }

    public function test_name_and_email_can_change_without_current_password(): void
    {
        $user = $this->masuk('operator');

        $this->putJson('/api/v1/profil', $this->payload(['email' => 'baru@sekolah.test']))
            ->assertOk()
            ->assertJsonPath('user.nama', 'Nama Baru')
            ->assertJsonPath('user.email', 'baru@sekolah.test');

        $this->assertSame('Nama Baru', $user->fresh()->name);
    }

    public function test_changing_username_requires_the_current_password(): void
    {
        $user = $this->masuk('operator');

        $this->putJson('/api/v1/profil', $this->payload(['username' => 'baru']))->assertStatus(422)->assertJsonPath('error.details.password_saat_ini.0', 'Password saat ini salah atau belum diisi.');
        $this->putJson('/api/v1/profil', $this->payload(['username' => 'baru', 'password_saat_ini' => 'salah']))->assertStatus(422);
        $this->assertSame('lama', $user->fresh()->username);

        $this->putJson('/api/v1/profil', $this->payload(['username' => 'baru', 'password_saat_ini' => 'rahasia123']))->assertOk()->assertJsonPath('user.username', 'baru');
        $this->assertSame('baru', $user->fresh()->username);
    }

    public function test_password_change_is_confirmed_hashed_and_revokes_other_sessions_only(): void
    {
        $user = $this->masuk('guru');
        $user->tokens()->create(['name' => 'perangkat-lain', 'token' => hash('sha256', 'x'), 'abilities' => ['*']]);

        $this->putJson('/api/v1/profil', $this->payload(['password' => 'baru12345', 'password_confirmation' => 'beda', 'password_saat_ini' => 'rahasia123']))->assertStatus(422);

        $this->putJson('/api/v1/profil', $this->payload(['password' => 'baru12345', 'password_confirmation' => 'baru12345', 'password_saat_ini' => 'rahasia123']))->assertOk();

        $this->assertTrue(Hash::check('baru12345', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->where('name', 'perangkat-lain')->count(), 'Sesi di perangkat lain dicabut.');
    }

    public function test_username_must_be_unique_and_well_formed(): void
    {
        User::factory()->role('guru')->create(['username' => 'dipakai']);
        $this->masuk('operator');

        $this->putJson('/api/v1/profil', $this->payload(['username' => 'dipakai', 'password_saat_ini' => 'rahasia123']))->assertStatus(422)->assertJsonPath('error.details.username.0', 'Username sudah dipakai pengguna lain.');
        $this->putJson('/api/v1/profil', $this->payload(['username' => 'ada spasi', 'password_saat_ini' => 'rahasia123']))->assertStatus(422);
    }

    public function test_teacher_can_edit_own_nip_but_not_take_another_teachers(): void
    {
        $lain = Guru::create(['user_id' => User::factory()->role('guru')->create()->id, 'nip' => '111']);
        $user = $this->masuk('guru');
        $guru = Guru::create(['user_id' => $user->id]);

        $this->putJson('/api/v1/profil', $this->payload(['nip' => '111']))->assertStatus(422)->assertJsonPath('error.details.nip.0', 'NIP sudah dipakai guru lain.');
        $this->putJson('/api/v1/profil', $this->payload(['nip' => '222']))->assertOk()->assertJsonPath('user.nip', '222');

        $this->assertSame('222', $guru->fresh()->nip);
        $this->assertSame('111', $lain->fresh()->nip);
    }

    public function test_students_cannot_use_the_profile_endpoint(): void
    {
        $this->masuk('siswa');

        $this->putJson('/api/v1/profil', $this->payload())->assertForbidden();
    }

    public function test_profile_change_is_audited_without_leaking_the_password(): void
    {
        $this->masuk('operator');

        $this->putJson('/api/v1/profil', $this->payload(['password' => 'baru12345', 'password_confirmation' => 'baru12345', 'password_saat_ini' => 'rahasia123']))->assertOk();

        $log = LogAktivitas::where('aksi', 'profil.diperbarui')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('baru12345', json_encode($log->toArray()) ?: '');
    }
}
