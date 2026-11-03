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
const loading = ref(false)
const errorMessage = ref('')

async function submit() {
  loading.value = true
  errorMessage.value = ''
  try {
    await auth.login(username.value.trim(), password.value)
    if (auth.role) await router.replace({ name: berandaUntukRole(auth.role) })
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'Terjadi kesalahan. Silakan coba lagi.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="mx-auto flex min-h-dvh max-w-sm flex-col justify-center gap-6 p-6">
    <header class="text-center">
      <h1 class="text-2xl font-bold">Ujian Sekolah</h1>
      <p class="text-slate-600">Masuk dengan akun Anda</p>
    </header>

    <form class="card flex flex-col gap-4" @submit.prevent="submit">
      <label class="flex flex-col gap-1 text-sm font-medium">
        Username (NIS / NIP)
        <input v-model="username" class="input" autocomplete="username" required />
      </label>

      <label class="flex flex-col gap-1 text-sm font-medium">
        Kata sandi
        <input v-model="password" type="password" class="input" autocomplete="current-password" required />
      </label>

      <p v-if="errorMessage" role="alert" class="text-sm font-medium text-status-danger">
        ⚠ {{ errorMessage }}
      </p>

      <button type="submit" class="btn-primary" :disabled="loading">
        {{ loading ? 'Memproses…' : 'Masuk' }}
      </button>
    </form>
  </main>
</template>

