import { createPinia } from 'pinia'
import { createApp } from 'vue'
import 'katex/dist/katex.min.css'
import './style.css'
import App from './App.vue'
import { router } from './router'

createApp(App).use(createPinia()).use(router).mount('#app')

// Sel tabel yang terpotong "..." menampilkan isi lengkap sebagai tooltip saat disentuh/ditunjuk.
document.addEventListener(
  'pointerover',
  (e) => {
    const td = (e.target as HTMLElement | null)?.closest?.('td')
    if (td && !td.title && td.scrollWidth > td.clientWidth) td.title = td.textContent?.trim() ?? ''
  },
  { passive: true }
)
