<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $username = 'siswa1', string $password = 'password')
    {
        return $this->postJson('/api/v1/auth/login', compact('username', 'password'));
    }

    public function test_login_success_and_me_and_logout(): void
    {
        User::factory()->create(['username' => 'siswa1']);

        $token = $this->login()->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.username', 'siswa1');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_wrong_password_and_inactive_user_rejected(): void
    {
        User::factory()->create(['username' => 'siswa1']);
        User::factory()->create(['username' => 'off', 'aktif' => false]);

        $this->login('siswa1', 'salah')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->login('off')->assertStatus(401);
    }

    public function test_login_accepts_student_nis_and_teacher_nip(): void
    {
        $tahunAjaran = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $tahunAjaran->id]);
        $siswaUser = User::factory()->create(['role' => 'siswa', 'username' => 'login-siswa']);
        Siswa::create(['user_id' => $siswaUser->id, 'nis' => '2026001', 'kelas_id' => $kelas->id]);
        $guruUser = User::factory()->role('guru')->create(['username' => 'login-guru']);
        Guru::create(['user_id' => $guruUser->id, 'nip' => '198501010001']);

        $this->login('2026001')->assertOk()->assertJsonPath('user.username', 'login-siswa');
        $this->login('198501010001')->assertOk()->assertJsonPath('user.username', 'login-guru');
    }

    public function test_superadmin_can_create_and_deactivate_user_without_hard_deleting_it(): void
    {
        $tahunAjaran = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $tahunAjaran->id]);
        $admin = User::factory()->role('superadmin')->create();

        $created = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'username' => 'managed-siswa',
            'name' => 'Siswa Tes',
            'password' => 'secret123',
            'role' => 'siswa',
            'nis' => '998877',
            'kelas_id' => $kelas->id,
        ])->assertCreated();

        $userId = $created->json('data.id');
        $this->login('998877', 'secret123')->assertOk();

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$userId}")->assertOk();
        $this->assertDatabaseHas('users', ['id' => $userId, 'aktif' => false]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $userId]);
        $this->login('998877', 'secret123')->assertUnauthorized();
    }

    public function test_superadmin_can_persist_student_login_policy(): void
    {
        $admin = User::factory()->role('superadmin')->create();
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/settings', ['login_policy' => 'tolak'])
            ->assertOk()
            ->assertJsonPath('data.login_policy', 'tolak');

        User::factory()->create(['username' => 'siswa1']);
        $this->login()->assertOk();
        $this->login()->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_IN_USE');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.login_policy', 'tolak');
    }

    public function test_second_device_rejected_under_tolak_policy(): void
    {
        config(['cbt.login_policy' => 'tolak']);
        User::factory()->create(['username' => 'siswa1', 'role' => 'siswa']);

        $this->login()->assertOk();
        $this->login()->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_IN_USE');
    }

    public function test_non_siswa_roles_allow_multiple_active_sessions(): void
    {
        config(['cbt.login_policy' => 'tolak']);
        User::factory()->create(['username' => 'operator1', 'role' => 'operator']);
        User::factory()->create(['username' => 'guru1', 'role' => 'guru']);
        User::factory()->create(['username' => 'admin1', 'role' => 'superadmin']);

        // Operator can login multiple times
        $this->login('operator1')->assertOk();
        $this->login('operator1')->assertOk();

        // Guru can login multiple times
        $this->login('guru1')->assertOk();
        $this->login('guru1')->assertOk();

        // Superadmin can login multiple times
        $this->login('admin1')->assertOk();
        $this->login('admin1')->assertOk();
    }

    public function test_second_device_revokes_first_under_kick_policy(): void
    {
        config(['cbt.login_policy' => 'kick']);
        User::factory()->create(['username' => 'siswa1']);

        $this->login()->assertOk();
        $this->login()->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rate_limited_after_five_attempts(): void
    {
        User::factory()->create(['username' => 'siswa1']);

        foreach (range(1, 5) as $_) {
            $this->login('siswa1', 'salah')->assertStatus(401);
        }
        $this->login('siswa1', 'salah')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_role_middleware_denies_other_roles(): void
    {
        Route::middleware(['auth:sanctum', 'role:operator'])->get('/api/_cek', fn () => 'ok');
        $siswa = User::factory()->create();
        $op = User::factory()->role('operator')->create();

        $this->actingAs($siswa, 'sanctum')->getJson('/api/_cek')->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($op, 'sanctum')->getJson('/api/_cek')->assertOk();
    }
}
