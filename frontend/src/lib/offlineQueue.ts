import { openDB, type DBSchema, type IDBPDatabase } from 'idb'

/**
 * Antrean offline berbasis IndexedDB.
 *
 * Aturan (Rules FE bagian 2 dan 3):
 * - Hanya menyimpan antrean jawaban dan event lock, TIDAK PERNAH konten soal.
 * - Item baru dihapus hanya setelah server mengonfirmasi penyimpanan.
 * - Event lock selalu dikirim lebih dulu daripada jawaban.
 */
export interface JawabanAntrean {
  /** ID soal (UUID dari server) */
  soalId: string
  jawaban: unknown
  /** Nomor urut naik per peserta; server menolak nomor yang lebih lama. */
  clientSeq: number
  waktuMs: number
}

export interface LockAntrean {
  pemicu: string
  waktuMs: number
}

interface CbtDB extends DBSchema {
  jawaban: { key: number; value: JawabanAntrean }
  lock: { key: number; value: LockAntrean }
}

let dbPromise: Promise<IDBPDatabase<CbtDB>> | null = null

function db(): Promise<IDBPDatabase<CbtDB>> {
  dbPromise ??= openDB<CbtDB>('cbt-offline', 1, {
    upgrade(database) {
      database.createObjectStore('jawaban', { autoIncrement: true })
      database.createObjectStore('lock', { autoIncrement: true })
    }
  })
  return dbPromise
}

export async function antrekanJawaban(item: JawabanAntrean): Promise<void> {
  await (await db()).add('jawaban', item)
}

export async function antrekanLock(item: LockAntrean): Promise<void> {
  await (await db()).add('lock', item)
}

export async function ambilJawaban(): Promise<JawabanAntrean[]> {
  return (await db()).getAll('jawaban')
}

export async function ambilLock(): Promise<LockAntrean[]> {
  return (await db()).getAll('lock')
}

/** Panggil hanya setelah server mengonfirmasi jawaban tersimpan. */
export async function kosongkanJawaban(): Promise<void> {
  await (await db()).clear('jawaban')
}

export async function kosongkanLock(): Promise<void> {
  await (await db()).clear('lock')
}

/** Dipanggil saat ujian selesai untuk membersihkan semua antrean lokal. */
export async function bersihkanSemua(): Promise<void> {
  const database = await db()
  await Promise.all([database.clear('jawaban'), database.clear('lock')])
}

