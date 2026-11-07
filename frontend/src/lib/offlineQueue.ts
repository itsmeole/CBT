import { openDB, type DBSchema, type IDBPDatabase } from 'idb'

/**
 * Antrean offline berbasis IndexedDB.
 *
 * Aturan (Rules FE bagian 2 dan 3):
 * - Hanya menyimpan antrean jawaban dan event lock, TIDAK PERNAH konten soal.
 * - Item baru dihapus hanya setelah server mengonfirmasi penyimpanan.
 * - Event lock selalu dikirim lebih dulu daripada jawaban.
 *
 * Bila IndexedDB tidak tersedia (mis. mode privat di sebagian browser), antrean beralih ke memori
 * agar jawaban tetap terkirim selama halaman terbuka.
 */
export interface JawabanAntrean {
  /** ID soal dari server */
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

export interface ItemBerkey<T> {
  key: number
  item: T
}

interface CbtDB extends DBSchema {
  jawaban: { key: number; value: JawabanAntrean }
  lock: { key: number; value: LockAntrean }
}

type NamaAntrean = 'jawaban' | 'lock'

let dbPromise: Promise<IDBPDatabase<CbtDB>> | null = null
let idbRusak = false
let kunciMemori = 0
const memori = {
  jawaban: new Map<number, JawabanAntrean>(),
  lock: new Map<number, LockAntrean>()
}

function db(): Promise<IDBPDatabase<CbtDB>> {
  dbPromise ??= openDB<CbtDB>('cbt-offline', 1, {
    upgrade(database) {
      database.createObjectStore('jawaban', { autoIncrement: true })
      database.createObjectStore('lock', { autoIncrement: true })
    }
  })
  return dbPromise
}

/** Jalankan operasi di IndexedDB; bila gagal, tandai rusak dan pakai cadangan memori seterusnya. */
async function coba<T>(operasi: (database: IDBPDatabase<CbtDB>) => Promise<T>, cadangan: () => T): Promise<T> {
  if (!idbRusak) {
    try {
      return await operasi(await db())
    } catch {
      idbRusak = true
      dbPromise = null
    }
  }
  return cadangan()
}

async function tambah(nama: NamaAntrean, item: JawabanAntrean | LockAntrean): Promise<void> {
  await coba(
    async (database) => {
      await database.add(nama, item as never)
    },
    () => {
      memori[nama].set(++kunciMemori, item as never)
    }
  )
}

async function ambilBerkey<T>(nama: NamaAntrean): Promise<ItemBerkey<T>[]> {
  return coba(
    async (database) => {
      const [keys, items] = await Promise.all([database.getAllKeys(nama), database.getAll(nama)])
      return keys.map((key, i) => ({ key: key as number, item: items[i] as T }))
    },
    () => [...memori[nama].entries()].map(([key, item]) => ({ key, item: item as T }))
  )
}

async function hapus(nama: NamaAntrean, keys: number[]): Promise<void> {
  await coba(
    async (database) => {
      await Promise.all(keys.map((key) => database.delete(nama, key)))
    },
    () => {
      keys.forEach((key) => memori[nama].delete(key))
    }
  )
}

async function kosongkan(nama: NamaAntrean): Promise<void> {
  await coba(
    async (database) => {
      await database.clear(nama)
    },
    () => {
      memori[nama].clear()
    }
  )
}

export const antrekanJawaban = (item: JawabanAntrean): Promise<void> => tambah('jawaban', item)
export const antrekanLock = (item: LockAntrean): Promise<void> => tambah('lock', item)

export const ambilJawabanBerkey = (): Promise<ItemBerkey<JawabanAntrean>[]> => ambilBerkey<JawabanAntrean>('jawaban')
export const ambilLockBerkey = (): Promise<ItemBerkey<LockAntrean>[]> => ambilBerkey<LockAntrean>('lock')

/** Hapus hanya item yang sudah dikonfirmasi server; item baru yang masuk saat request berjalan tetap aman. */
export const hapusJawaban = (keys: number[]): Promise<void> => hapus('jawaban', keys)
export const hapusLock = (keys: number[]): Promise<void> => hapus('lock', keys)

export async function ambilJawaban(): Promise<JawabanAntrean[]> {
  return (await ambilJawabanBerkey()).map((x) => x.item)
}

export async function ambilLock(): Promise<LockAntrean[]> {
  return (await ambilLockBerkey()).map((x) => x.item)
}

/** Panggil hanya setelah server mengonfirmasi jawaban tersimpan. */
export const kosongkanJawaban = (): Promise<void> => kosongkan('jawaban')
export const kosongkanLock = (): Promise<void> => kosongkan('lock')

/** Dipanggil saat ujian selesai untuk membersihkan semua antrean lokal. */
export async function bersihkanSemua(): Promise<void> {
  await Promise.all([kosongkan('jawaban'), kosongkan('lock')])
}
