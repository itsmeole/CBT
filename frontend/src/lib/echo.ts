import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}

let instance: Echo<'reverb'> | null = null

/**
 * Klien realtime ke Laravel Reverb. Dibuat lazy dan hanya saat dibutuhkan (dashboard pengawas,
 * event siswa), supaya tidak membebani bundle awal. Otorisasi channel private memakai cookie Sanctum.
 */
export function getEcho(): Echo<'reverb'> {
  if (instance) return instance

  window.Pusher = Pusher
  const origin = new URL(import.meta.env.VITE_API_BASE_URL, window.location.origin).origin

  instance = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT),
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${origin}/broadcasting/auth`,
    withCredentials: true
  } as ConstructorParameters<typeof Echo<'reverb'>>[0])

  return instance
}

export function disconnectEcho(): void {
  instance?.disconnect()
  instance = null
}

