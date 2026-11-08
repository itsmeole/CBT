<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { EditorContent, Node, mergeAttributes, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import Subscript from '@tiptap/extension-subscript'
import Superscript from '@tiptap/extension-superscript'
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table'
import { renderLatex } from '@/lib/katex'

/**
 * Editor soal (FR-FE-101). Hanya menghasilkan tag yang diizinkan sanitizer BE
 * (p, br, strong, em, u, sub, sup, ul, ol, li, table, img, audio). Komponen ini dimuat lazy
 * hanya di halaman staf agar tidak masuk bundle siswa (NFR-FE-02).
 */
const props = withDefaults(defineProps<{ modelValue: string; placeholder?: string }>(), {
  placeholder: 'Tuliskan teks di sini...'
})
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

/** Audio hasil unggah media: node blok sederhana agar tidak dibuang oleh skema editor. */
const AudioNode = Node.create({
  name: 'audio',
  group: 'block',
  atom: true,
  addAttributes() {
    return { src: { default: null } }
  },
  parseHTML() {
    return [{ tag: 'audio[src]' }]
  },
  renderHTML({ HTMLAttributes }) {
    return ['audio', mergeAttributes(HTMLAttributes, { controls: 'controls' })]
  }
})

function normalisasi(html: string): string {
  return html === '<p></p>' ? '' : html
}

const editor = useEditor({
  content: props.modelValue,
  extensions: [
    StarterKit.configure({ link: false, heading: false, blockquote: false, codeBlock: false, code: false, horizontalRule: false, strike: false }),
    Subscript,
    Superscript,
    Image.configure({ inline: false }),
    Table.configure({ resizable: false }),
    TableRow,
    TableHeader,
    TableCell,
    AudioNode
  ],
  editorProps: {
    attributes: {
      class: 'min-h-32 max-w-none p-3 text-sm leading-relaxed text-slate-800 focus:outline-none',
      dir: 'auto',
      'aria-label': props.placeholder
    }
  },
  onUpdate: ({ editor: e }) => emit('update:modelValue', normalisasi(e.getHTML()))
})

watch(
  () => props.modelValue,
  (nilai) => {
    if (editor.value && normalisasi(editor.value.getHTML()) !== nilai) {
      editor.value.commands.setContent(nilai, { emitUpdate: false })
    }
  }
)

onBeforeUnmount(() => editor.value?.destroy())

// ---------- Rumus dengan pratinjau KaTeX ----------
const tampilRumus = ref(false)
const rumus = ref('')
const rumusDisplay = ref(false)
const pratinjauRumus = computed(() => (rumus.value.trim() ? renderLatex(rumus.value.trim(), rumusDisplay.value) : ''))

function sisipkanRumus() {
  const isi = rumus.value.trim()
  if (!isi || !editor.value) return
  const pembatas = rumusDisplay.value ? '$$' : '$'
  editor.value.chain().focus().insertContent(`${pembatas}${isi}${pembatas}`).run()
  rumus.value = ''
  tampilRumus.value = false
}

defineExpose({
  insertImage(src: string) {
    editor.value?.chain().focus().setImage({ src, alt: 'Gambar soal' }).run()
  },
  insertAudio(src: string) {
    editor.value?.chain().focus().insertContent({ type: 'audio', attrs: { src } }).run()
  }
})

const tombol = [
  { label: 'B', judul: 'Tebal', aksi: 'toggleBold', aktif: 'bold', kelas: 'font-bold' },
  { label: 'I', judul: 'Miring', aksi: 'toggleItalic', aktif: 'italic', kelas: 'italic' },
  { label: 'U', judul: 'Garis bawah', aksi: 'toggleUnderline', aktif: 'underline', kelas: 'underline' },
  { label: 'x₂', judul: 'Subskrip', aksi: 'toggleSubscript', aktif: 'subscript', kelas: '' },
  { label: 'x²', judul: 'Superskrip', aksi: 'toggleSuperscript', aktif: 'superscript', kelas: '' },
  { label: '• List', judul: 'Daftar poin', aksi: 'toggleBulletList', aktif: 'bulletList', kelas: '' },
  { label: '1. List', judul: 'Daftar bernomor', aksi: 'toggleOrderedList', aktif: 'orderedList', kelas: '' }
] as const

function jalankan(aksi: (typeof tombol)[number]['aksi']) {
  const chain = editor.value?.chain().focus() as unknown as Record<string, () => { run: () => boolean }> | undefined
  chain?.[aksi]?.().run()
}
</script>

<template>
  <div class="rounded-xl border border-slate-300 bg-white focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-100">
    <div class="flex flex-wrap items-center gap-1 border-b border-slate-200 bg-slate-50 p-1.5" role="toolbar" aria-label="Pemformatan teks">
      <button
        v-for="t in tombol"
        :key="t.aksi"
        type="button"
        :title="t.judul"
        :aria-label="t.judul"
        :aria-pressed="editor?.isActive(t.aktif) ?? false"
        class="min-h-8 min-w-8 rounded-lg px-2 text-xs hover:bg-slate-200"
        :class="[t.kelas, editor?.isActive(t.aktif) ? 'bg-brand-100 text-brand-700' : 'text-slate-700']"
        @click="jalankan(t.aksi)"
      >
        {{ t.label }}
      </button>
      <span class="mx-1 h-5 w-px bg-slate-300" aria-hidden="true"></span>
      <button type="button" class="min-h-8 rounded-lg px-2 text-xs text-slate-700 hover:bg-slate-200" @click="editor?.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()">Tabel</button>
      <template v-if="editor?.isActive('table')">
        <button type="button" class="min-h-8 rounded-lg px-2 text-xs text-slate-700 hover:bg-slate-200" @click="editor?.chain().focus().addRowAfter().run()">+ Baris</button>
        <button type="button" class="min-h-8 rounded-lg px-2 text-xs text-slate-700 hover:bg-slate-200" @click="editor?.chain().focus().addColumnAfter().run()">+ Kolom</button>
        <button type="button" class="min-h-8 rounded-lg px-2 text-xs text-red-700 hover:bg-red-50" @click="editor?.chain().focus().deleteTable().run()">Hapus tabel</button>
      </template>
      <span class="mx-1 h-5 w-px bg-slate-300" aria-hidden="true"></span>
      <button type="button" class="min-h-8 rounded-lg px-2 text-xs font-semibold text-brand-700 hover:bg-brand-50" @click="tampilRumus = !tampilRumus">Σ Rumus</button>
    </div>

    <div v-if="tampilRumus" class="space-y-2 border-b border-slate-200 bg-brand-50/40 p-3">
      <label class="block text-xs font-semibold text-slate-700">
        Rumus
        <input v-model="rumus" class="input mt-1 font-mono text-sm" placeholder="contoh: \frac{a}{b} + \sqrt{x^2}" @keydown.enter.prevent="sisipkanRumus" />
      </label>
      <label class="flex items-center gap-2 text-xs text-slate-700">
        <input v-model="rumusDisplay" type="checkbox" class="h-4 w-4" /> Tampilkan sebagai blok terpisah
      </label>
      <div v-if="pratinjauRumus" class="rounded-lg border border-slate-200 bg-white p-2" v-html="pratinjauRumus"></div>
      <div class="flex gap-2">
        <button type="button" class="btn-primary !min-h-0 !py-1.5 text-xs" :disabled="!rumus.trim()" @click="sisipkanRumus">Sisipkan</button>
        <button type="button" class="btn-secondary !min-h-0 !py-1.5 text-xs" @click="tampilRumus = false">Tutup</button>
      </div>
    </div>

    <EditorContent :editor="editor" />
  </div>
</template>

<style scoped>
:deep(.tiptap table) {
  border-collapse: collapse;
  margin: 0.5rem 0;
  width: 100%;
}
:deep(.tiptap th),
:deep(.tiptap td) {
  border: 1px solid #cbd5e1;
  padding: 0.35rem 0.5rem;
  vertical-align: top;
}
:deep(.tiptap th) {
  background: #f1f5f9;
  font-weight: 600;
}
:deep(.tiptap img) {
  max-width: 100%;
  height: auto;
  border-radius: 0.5rem;
}
:deep(.tiptap ul) {
  list-style: disc;
  padding-left: 1.25rem;
}
:deep(.tiptap ol) {
  list-style: decimal;
  padding-left: 1.25rem;
}
</style>
