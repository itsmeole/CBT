/** Pesan baku bahasa Indonesia untuk kode error backend (BE.md bagian 7). */
const PESAN: Record<string, string> = {
  UNAUTHENTICATED: 'Sesi Anda berakhir. Silakan masuk kembali.',
  FORBIDDEN: 'Anda tidak memiliki akses ke halaman atau aksi ini.',
  VALIDATION_ERROR: 'Data yang dikirim belum sesuai. Periksa kembali isian Anda.',
  ACCOUNT_IN_USE: 'Akun sedang aktif di perangkat lain. Hubungi operator atau pengawas.',
  TOKEN_INVALID: 'Token ujian tidak valid. Periksa kembali token dari pengawas.',
  NOT_STARTED: 'Ujian belum dimulai. Mohon tunggu operator.',
  SESSION_LOCKED: 'Ujian terkunci, hubungi pengawas.',
  TIME_UP: 'Waktu ujian telah habis.',
  ALREADY_SUBMITTED: 'Ujian sudah dikumpulkan.',
  RATE_LIMITED: 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.',
  NETWORK_ERROR: 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.',
  UNKNOWN_ERROR: 'Terjadi kesalahan. Silakan coba lagi.'
}

export function pesanError(code: string): string {
  return PESAN[code] ?? PESAN.UNKNOWN_ERROR!
}

