<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'

/** Dialog konfirmasi: meminta pengguna memasukkan ulang password akunnya sebelum aksi sensitif. */
withDefaults(
  defineProps<{
    judul?: string
    pesan?: string
    tombol?: string
    memproses?: boolean
    galat?: string
  }>(),
  { judul: 'Konfirmasi password', pesan: 'Masukkan password akun Anda untuk melanjutkan.', tombol: 'Lanjutkan', memproses: false, galat: '' }
)

const emit = defineEmits<{ (e: 'konfirmasi', password: string): void; (e: 'batal'): void }>()

const password = ref('')
const lihat = ref(false)
const kotak = ref<HTMLInputElement | null>(null)

function kirim() {
  if (password.value) emit('konfirmasi', password.value)
}

function saatKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('batal')
}

onMounted(() => {
  window.addEventListener('keydown', saatKeydown)
  kotak.value?.focus()
})
onUnmounted(() => window.removeEventListener('keydown', saatKeydown))
</script>

<template>
  <div class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs" @click.self="!memproses && emit('batal')">
    <form class="w-full max-w-sm space-y-4 rounded-2xl bg-white p-5 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="judul-konfirmasi-password" @submit.prevent="kirim">
      <div>
        <h2 id="judul-konfirmasi-password" class="text-base font-bold tracking-tight text-slate-900">{{ judul }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ pesan }}</p>
      </div>

      <label class="block text-sm font-medium text-slate-700">
        Password Anda
        <div class="relative mt-1">
          <input
            ref="kotak"
            v-model="password"
            :type="lihat ? 'text' : 'password'"
            class="input pr-16"
            autocomplete="current-password"
            required
          />
          <button type="button" class="absolute inset-y-0 right-2 px-2 text-xs font-semibold text-slate-500 hover:text-slate-800" @click="lihat = !lihat">
            {{ lihat ? 'Sembunyi' : 'Lihat' }}
          </button>
        </div>
      </label>

      <p v-if="galat" class="rounded-xl bg-red-50 p-3 text-sm text-red-700" role="alert">{{ galat }}</p>

      <div class="flex gap-2">
        <button type="button" class="btn-secondary flex-1" :disabled="memproses" @click="emit('batal')">Batal</button>
        <button type="submit" class="btn-primary flex-1" :disabled="memproses || !password">{{ memproses ? 'Memproses...' : tombol }}</button>
      </div>
    </form>
  </div>
</template>
