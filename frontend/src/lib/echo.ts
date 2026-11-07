import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { apiClient } from '@/api/client'

declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}

let instance: Echo<'reverb'> | null = null

type KirimOtorisasi = (url: string, body: { socket_id: string; channel_name: string }) => Promise<{ data: unknown }>

/**
 * Otorisasi channel private. Autentikasi aplikasi memakai token Bearer di memori (bukan cookie),
 * jadi permintaan `/broadcasting/auth` harus lewat klien HTTP aplikasi agar header Authorization ikut terkirim.
 */
export function buatAuthorizer(endpoint: string, kirim: KirimOtorisasi) {
  return (channel: { name: string }) => ({
    authorize: (socketId: string, callback: (error: Error | null, data: unknown) => void) => {
      kirim(endpoint, { socket_id: socketId, channel_name: channel.name })
        .then((res) => callback(null, res.data))
        .catch((err: unknown) => callback(err instanceof Error ? err : new Error('Otorisasi channel gagal.'), null))
    }
  })
}

/**
 * Klien realtime ke Laravel Reverb. Dibuat lazy dan hanya saat dibutuhkan (dashboard pengawas),
 * supaya tidak membebani bundle siswa.
 */
export function getEcho(): Echo<'reverb'> {
  if (instance) return instance

  window.Pusher = Pusher
  const origin = new URL(import.meta.env.VITE_API_BASE_URL, window.location.origin).origin
  const endpoint = `${origin}/broadcasting/auth`

  instance = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT),
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: endpoint,
    authorizer: buatAuthorizer(endpoint, (url, body) => apiClient.post(url, body))
  } as ConstructorParameters<typeof Echo<'reverb'>>[0])

  return instance
}

export function disconnectEcho(): void {
  instance?.disconnect()
  instance = null
}
