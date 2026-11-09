<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { ApiError, apiClient } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import type { AuthUser } from '@/types/auth'

const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

const auth = useAuthStore()
const user = auth.user as AuthUser
const adalahGuru = computed(() => user.role === 'guru')

const form = reactive({
  nama: user.nama ?? '',
  username: user.username ?? '',
  email: user.email ?? '',
  nip: user.nip ?? '',
  password_saat_ini: '',
  password: '',
  password_confirmation: ''
})

const menyimpan = ref(false)
const pesanUmum = ref('')
const galat = ref<Record<string, string>>({})
const lihatPassword = ref(false)

/** Password saat ini hanya diminta bila username atau password diganti. */
const perluPasswordSaatIni = computed(() => form.username.trim() !== user.username || form.password !== '')

// Animasi: di HP panel naik dari bawah seperti laci, di layar lebar muncul memudar.
const tampil = ref(false)
let timerTutup: ReturnType<typeof setTimeout> | null = null

function tutupDenganAnimasi() {
  tampil.value = false
  timerTutup = setTimeout(() => emit('close'), 250)
}

function tutup() {
  if (!menyimpan.value) tutupDenganAnimasi()
}

function saatKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') tutup()
}

onMounted(() => {
  window.addEventListener('keydown', saatKeydown)
  requestAnimationFrame(() => requestAnimationFrame(() => (tampil.value = true)))
})
onUnmounted(() => {
  window.removeEventListener('keydown', saatKeydown)
  if (timerTutup) clearTimeout(timerTutup)
})

async function simpan() {
  menyimpan.value = true
  pesanUmum.value = ''
  galat.value = {}
  try {
    const { data } = await apiClient.put('/profil', {
      nama: form.nama.trim(),
      username: form.username.trim(),
      email: form.email.trim() || null,
      ...(adalahGuru.value ? { nip: form.nip.trim() || null } : {}),
      password_saat_ini: form.password_saat_ini || undefined,
      password: form.password || undefined,
      password_confirmation: form.password ? form.password_confirmation : undefined
    })
    auth.setUser(data.user)
    emit('saved')
    tutupDenganAnimasi()
  } catch (err) {
    if (err instanceof ApiError && err.details) {
      for (const [kolom, pesan] of Object.entries(err.details)) galat.value[kolom] = pesan[0] ?? ''
    }
    pesanUmum.value = err instanceof Error ? err.message : 'Profil gagal disimpan.'
  } finally {
    menyimpan.value = false
  }
}
</script>

<template>
  <div
    class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-0 backdrop-blur-xs transition-opacity duration-300 ease-out sm:items-center sm:p-4"
    :class="tampil ? 'opacity-100' : 'opacity-0'"
    @click.self="tutup"
  >
    <form
      class="max-h-[92dvh] w-full max-w-md space-y-4 overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl transition-all duration-300 ease-out sm:rounded-2xl"
      :class="tampil ? 'translate-y-0 opacity-100' : 'max-sm:translate-y-full sm:translate-y-3 sm:opacity-0'"
      role="dialog"
      aria-modal="true"
      aria-labelledby="judul-profil"
      @submit.prevent="simpan"
    >
      <div class="flex items-center justify-between">
        <h2 id="judul-profil" class="text-lg font-bold tracking-tight text-slate-900">Profil saya</h2>
        <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup" @click="tutup">✕</button>
      </div>

      <div v-if="pesanUmum && Object.keys(galat).length === 0" class="rounded-xl bg-red-50 p-3 text-sm text-red-700" role="alert">{{ pesanUmum }}</div>

      <fieldset class="space-y-3">
        <legend class="mb-1 text-xs font-semibold text-slate-500">Data diri</legend>
        <label class="block text-sm font-medium text-slate-700">
          Nama lengkap
          <input v-model="form.nama" class="input mt-1" required maxlength="100" autocomplete="name" />
          <span v-if="galat.nama" class="mt-1 block text-xs text-red-600">{{ galat.nama }}</span>
        </label>
        <label class="block text-sm font-medium text-slate-700">
          Email <span class="font-normal text-slate-400">(opsional)</span>
          <input v-model="form.email" type="email" class="input mt-1" maxlength="255" autocomplete="email" />
          <span v-if="galat.email" class="mt-1 block text-xs text-red-600">{{ galat.email }}</span>
        </label>
        <label v-if="adalahGuru" class="block text-sm font-medium text-slate-700">
          NIP <span class="font-normal text-slate-400">(opsional)</span>
          <input v-model="form.nip" class="input mt-1" maxlength="50" inputmode="numeric" />
          <span v-if="galat.nip" class="mt-1 block text-xs text-red-600">{{ galat.nip }}</span>
        </label>
      </fieldset>

      <fieldset class="space-y-3">
        <legend class="mb-1 text-xs font-semibold text-slate-500">Akun</legend>
        <label class="block text-sm font-medium text-slate-700">
          Username
          <input v-model="form.username" class="input mt-1" required minlength="3" maxlength="50" autocomplete="username" autocapitalize="none" />
          <span v-if="galat.username" class="mt-1 block text-xs text-red-600">{{ galat.username }}</span>
        </label>
        <label class="block text-sm font-medium text-slate-700">
          Password baru <span class="font-normal text-slate-400">(kosongkan bila tidak diganti)</span>
          <input v-model="form.password" :type="lihatPassword ? 'text' : 'password'" class="input mt-1" minlength="8" autocomplete="new-password" />
          <span v-if="galat.password" class="mt-1 block text-xs text-red-600">{{ galat.password }}</span>
        </label>
        <label v-if="form.password" class="block text-sm font-medium text-slate-700">
          Ulangi password baru
          <input v-model="form.password_confirmation" :type="lihatPassword ? 'text' : 'password'" class="input mt-1" autocomplete="new-password" />
        </label>
        <label v-if="form.password" class="flex items-center gap-2 text-xs text-slate-600">
          <input v-model="lihatPassword" type="checkbox" class="h-4 w-4 rounded" /> Tampilkan password
        </label>
        <label v-if="perluPasswordSaatIni" class="block text-sm font-medium text-slate-700">
          Password saat ini
          <input v-model="form.password_saat_ini" type="password" class="input mt-1" required autocomplete="current-password" />
          <span v-if="galat.password_saat_ini" class="mt-1 block text-xs text-red-600">{{ galat.password_saat_ini }}</span>
          <span v-else class="mt-1 block text-xs text-slate-400">Diperlukan untuk mengganti username atau password.</span>
        </label>
      </fieldset>

      <div class="flex gap-2 pt-1">
        <button type="button" class="btn-secondary flex-1" :disabled="menyimpan" @click="tutup">Batal</button>
        <button type="submit" class="btn-primary flex-1" :disabled="menyimpan">{{ menyimpan ? 'Menyimpan...' : 'Simpan' }}</button>
      </div>
    </form>
  </div>
</template>
