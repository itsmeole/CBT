<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '@/api/client'
import { berandaUntukRole } from '@/router/guard'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const username = ref('')
const password = ref('')
const showPassword = ref(false)
const loading = ref(false)
const errorMessage = ref('')

async function submit() {
  if (!username.value.trim() || !password.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    await auth.login(username.value.trim(), password.value)
    if (auth.role) {
      await router.replace({ name: berandaUntukRole(auth.role) })
    }
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'Terjadi kesalahan saat masuk. Silakan coba lagi.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div
    class="relative min-h-screen w-full overflow-hidden bg-cover bg-center bg-no-repeat font-sans flex flex-col justify-between"
    style="background-image: url('/login_bg.svg'); background-size: cover; background-position: center;"
  >
    <!-- ========================================== -->
    <!-- DESKTOP / TABLET LAYOUT (>= md)            -->
    <!-- ========================================== -->
    <div class="relative z-10 hidden md:flex min-h-screen w-full items-center justify-between pl-8 lg:pl-20 pr-0">
      <!-- Left: 3D Illustration of Books & Graduation Cap (Stationary) -->
      <div class="flex-1 flex items-center justify-center p-6 lg:p-12 select-none pointer-events-none">
        <img
          src="/illustration_books.png"
          alt="3D Buku dan Topi Wisuda"
          class="max-w-[420px] lg:max-w-[540px] xl:max-w-[620px] w-full h-auto drop-shadow-2xl"
        />
      </div>

      <!-- Right: White Login Panel Card -->
      <div class="w-full max-w-[460px] lg:max-w-[500px] h-screen bg-white rounded-l-[44px] lg:rounded-l-[56px] shadow-2xl flex flex-col justify-between px-8 sm:px-12 lg:px-14 py-8 lg:py-10 transition-all">
        <div class="my-auto w-full">
          <!-- Title & Subtitle -->
          <h1 class="text-3xl lg:text-4xl font-extrabold text-[#112340] tracking-tight">
            Selamat Datang!
          </h1>
          <p class="text-slate-400 text-sm mt-1.5 mb-8 font-normal">
            Log in untuk akses akun anda
          </p>

          <!-- Form -->
          <form @submit.prevent="submit" class="space-y-5">
            <!-- Email / Username Input -->
            <div class="space-y-1.5">
              <label for="username" class="block text-sm font-medium text-slate-700">
                Username :
              </label>
              <input
                id="username"
                v-model="username"
                type="text"
                placeholder="Masukan Username"
                autocomplete="username"
                required
                class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-800 text-sm placeholder-slate-400 transition-all focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100"
              />
            </div>

            <!-- Password Input with Toggle Visibility -->
            <div class="space-y-1.5">
              <label for="password" class="block text-sm font-medium text-slate-700">
                Password :
              </label>
              <div class="relative">
                <input
                  id="password"
                  v-model="password"
                  :type="showPassword ? 'text' : 'password'"
                  placeholder="Masukkan kata sandi"
                  autocomplete="current-password"
                  required
                  class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 pr-12 text-slate-800 text-sm placeholder-slate-400 transition-all focus:border-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-100"
                />
                <button
                  type="button"
                  @click="showPassword = !showPassword"
                  class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-600 focus:outline-none transition-colors"
                  :aria-label="showPassword ? 'Sembunyikan password' : 'Lihat password'"
                >
                  <!-- Eye Open Icon -->
                  <svg
                    v-if="!showPassword"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.8"
                    stroke="currentColor"
                    class="h-5 w-5"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
                    />
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                    />
                  </svg>
                  <!-- Eye Slash Icon -->
                  <svg
                    v-else
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.8"
                    stroke="currentColor"
                    class="h-5 w-5"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"
                    />
                  </svg>
                </button>
              </div>
            </div>

            <!-- Error Banner -->
            <div
              v-if="errorMessage"
              role="alert"
              class="rounded-xl bg-red-50 border border-red-200 px-3.5 py-2.5 text-xs font-semibold text-status-danger flex items-center gap-2"
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 shrink-0 text-red-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
              </svg>
              <span>{{ errorMessage }}</span>
            </div>

            <!-- Submit Button with Gradient -->
            <div class="pt-3">
              <button
                type="submit"
                :disabled="loading"
                class="w-full rounded-2xl bg-gradient-to-r from-[#598bfc] to-[#7ba7fc] hover:from-[#497df5] hover:to-[#6b9af7] text-white font-bold py-3.5 text-base shadow-lg shadow-blue-500/25 active:scale-[0.99] transition-all disabled:opacity-60 disabled:cursor-not-allowed cursor-pointer"
              >
                {{ loading ? 'Memproses…' : 'Login' }}
              </button>
            </div>

            <!-- Bantuan Lupa Akun Desktop -->
            <div class="pt-4 text-center">
              <p class="text-xs text-slate-500">
                Lupa username atau kata sandi?
                <span class="text-slate-700 font-medium">Hubungi pengawas atau operator ujian.</span>
              </p>
            </div>
          </form>
        </div>

        <!-- Keterangan Tahun di Paling Bawah Layar Desktop -->
        <footer class="mt-auto pt-4 text-center shrink-0">
          <p class="text-[11px] text-slate-400 font-medium">
            © {{ new Date().getFullYear() }} ICBT • Integrated Computer Based Testing
          </p>
        </footer>
      </div>
    </div>

    <!-- ========================================== -->
    <!-- MOBILE LAYOUT (< md)                       -->
    <!-- 1/3 Atas: Icon (Stationary)                -->
    <!-- 2/3 Bawah: Laci / Form Drawer              -->
    <!-- ========================================== -->
    <div class="relative z-10 flex md:hidden h-screen max-h-screen w-full flex-col justify-between overflow-hidden">
      <!-- 1/3 Bagian Atas: Area Icon Buku & Toga (Stationary, Tanpa Animasi Melayang) -->
      <div class="h-[34vh] w-full flex items-center justify-center p-4 select-none pointer-events-none">
        <img
          src="/illustration_books.png"
          alt="3D Buku dan Topi Wisuda"
          class="max-h-[26vh] max-w-[260px] w-auto h-auto object-contain drop-shadow-xl"
        />
      </div>

      <!-- 2/3 Bagian Bawah: Laci / Form Drawer -->
      <div
        class="h-[66vh] w-full bg-white rounded-t-[36px] shadow-[0_-16px_48px_rgba(0,0,0,0.18)] px-6 pt-3.5 pb-3 flex flex-col justify-between overflow-y-auto animate-slide-up"
      >
        <div>
          <!-- Drawer Pull Handle Bar -->
          <div class="w-12 h-1.5 bg-slate-300 rounded-full mx-auto mb-4 shrink-0"></div>

          <div class="w-full">
            <!-- Title & Subtitle -->
            <div class="mb-4">
              <h1 class="text-2xl font-extrabold text-[#112340] tracking-tight">
                Selamat Datang!
              </h1>
              <p class="text-slate-400 text-xs mt-1 font-normal">
                Log in untuk akses akun anda
              </p>
            </div>

            <!-- Form Mobile -->
            <form @submit.prevent="submit" class="space-y-4">
              <!-- Email / Username -->
              <div class="space-y-1">
                <label for="username-mobile" class="block text-xs font-semibold text-slate-700">
                  Username :
                </label>
                <input
                  id="username-mobile"
                  v-model="username"
                  type="text"
                  placeholder="Masukkan Username"
                  autocomplete="username"
                  required
                  class="w-full min-h-touch rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-slate-800 text-base placeholder-slate-400 transition-all focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </div>

              <!-- Password with Toggle -->
              <div class="space-y-1">
                <label for="password-mobile" class="block text-xs font-semibold text-slate-700">
                  Password :
                </label>
                <div class="relative">
                  <input
                    id="password-mobile"
                    v-model="password"
                    :type="showPassword ? 'text' : 'password'"
                    placeholder="Masukkan kata sandi"
                    autocomplete="current-password"
                    required
                    class="w-full min-h-touch rounded-xl border border-slate-200 bg-white px-4 py-2.5 pr-12 text-slate-800 text-base placeholder-slate-400 transition-all focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                  />
                  <button
                    type="button"
                    @click="showPassword = !showPassword"
                    class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-600 focus:outline-none min-h-touch min-w-touch justify-center"
                    :aria-label="showPassword ? 'Sembunyikan password' : 'Lihat password'"
                  >
                    <!-- Eye Open Icon -->
                    <svg
                      v-if="!showPassword"
                      xmlns="http://www.w3.org/2000/svg"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke-width="1.8"
                      stroke="currentColor"
                      class="h-5 w-5"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
                      />
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                      />
                    </svg>
                    <!-- Eye Slash Icon -->
                    <svg
                      v-else
                      xmlns="http://www.w3.org/2000/svg"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke-width="1.8"
                      stroke="currentColor"
                      class="h-5 w-5"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"
                      />
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Error Alert -->
              <div
                v-if="errorMessage"
                role="alert"
                class="rounded-xl bg-red-50 border border-red-200 px-3 py-2 text-xs font-semibold text-status-danger flex items-center gap-2"
              >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 shrink-0 text-red-600">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
                <span>{{ errorMessage }}</span>
              </div>

              <!-- Submit Button Mobile -->
              <div class="pt-2">
                <button
                  type="submit"
                  :disabled="loading"
                  class="w-full min-h-touch rounded-2xl bg-gradient-to-r from-[#598bfc] to-[#7ba7fc] hover:from-[#497df5] hover:to-[#6b9af7] text-white font-bold py-3.5 text-base shadow-lg shadow-blue-500/25 active:scale-[0.98] transition-all disabled:opacity-60 cursor-pointer"
                >
                  {{ loading ? 'Memproses…' : 'Login' }}
                </button>
              </div>

              <!-- Bantuan Lupa Akun Mobile -->
              <div class="pt-4 text-center">
                <p class="text-xs text-slate-500 leading-relaxed">
                  Lupa username atau kata sandi?
                  <br />
                  <span class="font-medium text-slate-700">Silakan hubungi pengawas ruang atau operator ujian.</span>
                </p>
              </div>
            </form>
          </div>
        </div>

        <!-- Keterangan Tahun di Paling Bawah Layar Mobile -->
        <footer class="mt-auto pt-4 pb-2 text-center shrink-0">
          <p class="text-[11px] text-slate-400 font-medium">
            © {{ new Date().getFullYear() }} ICBT • Integrated Computer Based Testing
          </p>
        </footer>
      </div>
    </div>
  </div>
</template>

<style scoped>
@keyframes slideUp {
  from {
    transform: translateY(100%);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}

.animate-slide-up {
  animation: slideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
</style>
