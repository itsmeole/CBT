<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { renderMathInHtml } from '@/lib/katex'
import { kiriBerikutnya, kiriDariKanan, lepaskan, pasangkan, type PetaPasangan } from '@/lib/menjodohkan'

interface Butir {
  id: string | number
  konten: string
}

const props = defineProps<{ kiri: Butir[]; kanan: Butir[]; modelValue: PetaPasangan; nonaktif?: boolean }>()
const emit = defineEmits<{ (e: 'update:modelValue', nilai: PetaPasangan): void }>()

/** Item kiri yang sedang dipilih (menunggu pasangan di kolom kanan). */
const dipilih = ref<string | null>(null)

const kiriIds = computed(() => props.kiri.map((k) => k.id))

// Satu warna per pasangan agar pasangan mudah dikenali tanpa garis penghubung.
const WARNA = [
  { kotak: 'border-sky-500 bg-sky-50', lencana: 'bg-sky-600' },
  { kotak: 'border-emerald-500 bg-emerald-50', lencana: 'bg-emerald-600' },
  { kotak: 'border-amber-500 bg-amber-50', lencana: 'bg-amber-600' },
  { kotak: 'border-rose-500 bg-rose-50', lencana: 'bg-rose-600' },
  { kotak: 'border-violet-500 bg-violet-50', lencana: 'bg-violet-600' },
  { kotak: 'border-teal-500 bg-teal-50', lencana: 'bg-teal-600' }
]
const warnaKiri = (idx: number) => WARNA[idx % WARNA.length]

const indeksKiri = (id: string | number): number => props.kiri.findIndex((k) => String(k.id) === String(id))

/** Pasangan item kanan: indeks item kiri yang memilihnya, atau -1. */
function pasanganKanan(kananId: string | number): number {
  const kiriId = kiriDariKanan(props.modelValue, kananId)
  return kiriId === null ? -1 : indeksKiri(kiriId)
}

// Soal berganti: pilihan lama tidak berlaku lagi.
watch(
  () => props.kiri,
  () => (dipilih.value = null)
)

function ketukKiri(id: string | number) {
  if (props.nonaktif) return
  dipilih.value = dipilih.value === String(id) ? null : String(id)
}

function ketukKanan(id: string | number) {
  if (props.nonaktif) return
  if (dipilih.value === null) {
    // Tanpa item kiri terpilih: ketuk kanan yang sudah berpasangan untuk memilih ulang item kirinya.
    dipilih.value = kiriDariKanan(props.modelValue, id)
    return
  }
  const asal = dipilih.value
  const baru = pasangkan(props.modelValue, asal, id)
  emit('update:modelValue', baru)
  dipilih.value = kiriBerikutnya(kiriIds.value, baru, asal)
}

function lepas(kiriId: string | number) {
  if (props.nonaktif) return
  emit('update:modelValue', lepaskan(props.modelValue, kiriId))
}

const jumlahPasangan = computed(() => props.kiri.filter((k) => String(k.id) in props.modelValue).length)
</script>

<template>
  <div class="space-y-2">
    <div class="flex items-center justify-between text-xs">
      <p class="font-semibold text-slate-500">Ketuk item kiri, lalu ketuk pasangannya di kanan.</p>
      <p class="shrink-0 font-bold tabular-nums text-slate-600" role="status">{{ jumlahPasangan }}/{{ kiri.length }}</p>
    </div>

    <div class="grid grid-cols-2 gap-2.5">
      <ul class="space-y-2" aria-label="Item kiri">
        <li v-for="(k, i) in kiri" :key="k.id">
          <div
            class="flex min-h-touch items-stretch overflow-hidden rounded-xl border-2 transition-colors"
            :class="
              dipilih === String(k.id)
                ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-600/30'
                : String(k.id) in modelValue
                  ? warnaKiri(i).kotak
                  : 'border-slate-200 bg-white'
            "
          >
            <button
              type="button"
              class="flex flex-1 items-center gap-2 p-2.5 text-left active:bg-slate-100 disabled:cursor-not-allowed"
              :disabled="nonaktif"
              :aria-pressed="dipilih === String(k.id)"
              @click="ketukKiri(k.id)"
            >
              <span
                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-xs font-bold tabular-nums text-white"
                :class="String(k.id) in modelValue ? warnaKiri(i).lencana : 'bg-slate-500'"
                >{{ i + 1 }}</span
              >
              <span class="min-w-0 text-[15px] leading-snug text-slate-800" dir="auto" v-html="renderMathInHtml(k.konten)"></span>
            </button>
            <button
              v-if="String(k.id) in modelValue"
              type="button"
              class="flex w-9 shrink-0 items-center justify-center text-slate-500 hover:bg-white/60 hover:text-red-600"
              :disabled="nonaktif"
              aria-label="Lepas pasangan"
              title="Lepas pasangan"
              @click="lepas(k.id)"
            >
              ✕
            </button>
          </div>
        </li>
      </ul>

      <ul class="space-y-2" aria-label="Pilihan pasangan">
        <li v-for="r in kanan" :key="r.id">
          <button
            type="button"
            class="flex min-h-touch w-full items-center gap-2 rounded-xl border-2 p-2.5 text-left transition-colors disabled:cursor-not-allowed"
            :class="[
              pasanganKanan(r.id) >= 0 ? warnaKiri(pasanganKanan(r.id)).kotak : 'border-slate-200 bg-white active:bg-slate-100',
              dipilih !== null && pasanganKanan(r.id) < 0 ? '!border-dashed !border-brand-500' : ''
            ]"
            :disabled="nonaktif"
            @click="ketukKanan(r.id)"
          >
            <span
              v-if="pasanganKanan(r.id) >= 0"
              class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-xs font-bold tabular-nums text-white"
              :class="warnaKiri(pasanganKanan(r.id)).lencana"
              >{{ pasanganKanan(r.id) + 1 }}</span
            >
            <span class="min-w-0 text-[15px] leading-snug text-slate-800" dir="auto" v-html="renderMathInHtml(r.konten)"></span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
