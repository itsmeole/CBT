<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    title?: string
    message?: string
    confirmText?: string
    cancelText?: string
    loading?: boolean
  }>(),
  {
    title: 'Konfirmasi Keluar',
    message: 'Apakah Anda yakin ingin keluar dari sesi akun ini?',
    confirmText: 'Ya, Keluar',
    cancelText: 'Batal',
    loading: false
  }
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'confirm'): void
  (e: 'cancel'): void
}>()

function close() {
  if (props.loading) return
  emit('update:modelValue', false)
  emit('cancel')
}

function confirm() {
  emit('confirm')
}

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && props.modelValue && !props.loading) {
    close()
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
})
</script>

<template>
  <div
    v-if="modelValue"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs transition-opacity animate-in fade-in duration-150"
    @click.self="close"
  >
    <div
      class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl border border-slate-100 text-center space-y-4 animate-in zoom-in-95 duration-150"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="'confirm-title'"
    >
      <!-- Icon Alert/Logout Badge -->
      <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-red-50 text-red-600 border border-red-100/80 shadow-2xs">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
        </svg>
      </div>

      <!-- Text -->
      <div class="space-y-1">
        <h3 id="confirm-title" class="text-base font-bold text-slate-900">
          {{ title }}
        </h3>
        <p class="text-xs text-slate-500 leading-relaxed">
          {{ message }}
        </p>
      </div>

      <!-- Action Buttons -->
      <div class="flex items-center gap-2.5 pt-1">
        <button
          type="button"
          @click="close"
          :disabled="loading"
          class="flex-1 rounded-xl border border-slate-200 bg-white py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-slate-900 active:scale-[0.98] transition-all disabled:opacity-50"
        >
          {{ cancelText }}
        </button>
        <button
          type="button"
          @click="confirm"
          :disabled="loading"
          class="flex-1 rounded-xl bg-red-600 py-2.5 text-xs font-semibold text-white shadow-xs hover:bg-red-700 active:scale-[0.98] transition-all flex items-center justify-center gap-1.5 disabled:opacity-50"
        >
          <span v-if="loading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
          <span>{{ confirmText }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

