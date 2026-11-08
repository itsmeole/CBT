<script setup lang="ts">
import { reactive, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { PATH_IKON, anakAktif, itemAktif, tabDefault, type MenuItem } from '@/lib/navigasi'

const props = defineProps<{ items: MenuItem[]; collapsed: boolean }>()
const emit = defineEmits<{ (e: 'navigate'): void }>()

const route = useRoute()

/** Status buka/tutup dropdown. Grup yang sedang aktif otomatis terbuka. */
const terbuka = reactive<Record<string, boolean>>({})

watch(
  () => [route.path, props.items] as const,
  ([path]) => {
    for (const item of props.items) {
      if (item.anak && itemAktif(item, path)) terbuka[item.to] = true
    }
  },
  { immediate: true }
)

/** ID aman untuk atribut aria-controls (tanpa karakter `/`). */
function idSubmenu(item: MenuItem): string {
  return 'submenu' + item.to.replace(/\W+/g, '-')
}

function toggle(item: MenuItem) {
  terbuka[item.to] = !terbuka[item.to]
}

function linkInduk(item: MenuItem) {
  return item.anak ? { path: item.to, query: { tab: tabDefault(item) } } : item.to
}

const kelasItem = (aktif: boolean, collapsed: boolean) => [
  'group relative flex w-full items-center rounded-xl text-sm transition-all',
  collapsed ? 'justify-center p-3' : 'gap-3 px-3.5 py-2.5',
  aktif ? 'bg-brand-600 text-white font-semibold shadow-xs' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
]
</script>

<template>
  <nav class="space-y-1.5 p-3" aria-label="Menu utama">
    <template v-for="item in items" :key="item.to">
      <!-- Item biasa, atau grup saat sidebar diciutkan (ikon saja, menuju sub-menu pertama) -->
      <RouterLink
        v-if="!item.anak || collapsed"
        :to="linkInduk(item)"
        :class="kelasItem(itemAktif(item, route.path), collapsed)"
        :title="collapsed ? item.nama : undefined"
        @click="emit('navigate')"
      >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="PATH_IKON[item.ikon]" />
        </svg>
        <span v-if="!collapsed" class="truncate">{{ item.nama }}</span>
        <div
          v-if="collapsed"
          class="pointer-events-none absolute left-full z-50 ml-2 hidden whitespace-nowrap rounded-lg bg-slate-900 px-2.5 py-1 text-xs font-medium text-white shadow-lg group-hover:block"
        >
          {{ item.nama }}
        </div>
      </RouterLink>

      <!-- Grup dropdown -->
      <div v-else>
        <button
          type="button"
          :class="[...kelasItem(false, false), itemAktif(item, route.path) ? '!bg-brand-50 !text-brand-700 font-semibold' : '']"
          :aria-expanded="!!terbuka[item.to]"
          :aria-controls="idSubmenu(item)"
          @click="toggle(item)"
        >
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" :d="PATH_IKON[item.ikon]" />
          </svg>
          <span class="flex-1 truncate text-left">{{ item.nama }}</span>
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="2"
            stroke="currentColor"
            class="h-4 w-4 shrink-0 transition-transform"
            :class="terbuka[item.to] ? 'rotate-180' : ''"
            aria-hidden="true"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
          </svg>
        </button>

        <!-- Animasi buka/tutup: tinggi bertransisi 0fr -> 1fr; saat tertutup konten disembunyikan dari fokus & pembaca layar. -->
        <div
          class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none"
          :class="terbuka[item.to] ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"
          :inert="!terbuka[item.to]"
          :data-terbuka="!!terbuka[item.to]"
        >
        <ul :id="idSubmenu(item)" class="ml-5 mt-1 min-h-0 space-y-0.5 overflow-hidden border-l border-slate-200 pl-2.5">
          <li v-for="anak in item.anak" :key="anak.tab">
            <RouterLink
              :to="{ path: item.to, query: { tab: anak.tab } }"
              class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] transition-colors"
              :class="
                anakAktif(item, anak, route.path, route.query.tab)
                  ? 'bg-brand-600 font-semibold text-white'
                  : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
              "
              :aria-current="anakAktif(item, anak, route.path, route.query.tab) ? 'page' : undefined"
              @click="emit('navigate')"
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 shrink-0" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="PATH_IKON[anak.ikon]" />
              </svg>
              <span class="truncate">{{ anak.nama }}</span>
            </RouterLink>
          </li>
        </ul>
        </div>
      </div>
    </template>
  </nav>
</template>
