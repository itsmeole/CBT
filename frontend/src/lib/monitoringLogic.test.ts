import { describe, expect, it } from 'vitest'
import {
  alasanValid,
  petakanPeserta,
  ringkasanStatus,
  terapkanEvent,
  urutkanPeserta,
  type PesertaMentah
} from './monitoringLogic'

const mentah = (id: number, nama: string, status: PesertaMentah['status'], kunci = 0): PesertaMentah => ({
  id,
  nama,
  nis: String(id),
  kelas: 'X-1',
  status,
  terjawab: 0,
  jumlah_kunci: kunci,
  sisa_detik: 600
})

describe('monitoringLogic', () => {
  it('menandai peserta yang melebihi ambang kunci', () => {
    const hasil = petakanPeserta([mentah(1, 'A', 'mengerjakan', 3), mentah(2, 'B', 'mengerjakan', 1)], 3)
    expect(hasil.map((p) => p.melebihiAmbang)).toEqual([true, false])
    expect(petakanPeserta([mentah(1, 'A', 'mengerjakan', 9)], null)[0].melebihiAmbang).toBe(false)
  })

  it('menaruh siswa terkunci di urutan teratas', () => {
    const daftar = petakanPeserta(
      [mentah(1, 'Budi', 'mengerjakan'), mentah(2, 'Zaki', 'terkunci'), mentah(3, 'Ani', 'selesai')],
      null
    )
    expect(urutkanPeserta(daftar).map((p) => p.id)).toEqual([2, 1, 3])
  })

  it('event kunci mengubah status dan memicu alert; progress tidak', () => {
    const daftar = petakanPeserta([mentah(1, 'A', 'mengerjakan')], 3)
    const kunci = terapkanEvent(daftar, { jenis: 'kunci', pesertaId: 1, jumlahKunci: 1 })
    expect(kunci.daftar[0]).toMatchObject({ status: 'terkunci', jumlahKunci: 1 })
    expect(kunci.perluAlert).toBe(true)

    const progress = terapkanEvent(daftar, { jenis: 'progress', pesertaId: 1, terjawab: 5, sisaDetik: 100 })
    expect(progress.daftar[0]).toMatchObject({ terjawab: 5, sisaDetik: 100 })
    expect(progress.perluAlert).toBe(false)
  })

  it('event status ke terkunci hanya alert saat transisi baru', () => {
    const terkunci = petakanPeserta([mentah(1, 'A', 'terkunci')], null)
    expect(terapkanEvent(terkunci, { jenis: 'status', pesertaId: 1, status: 'terkunci' }).perluAlert).toBe(false)
  })

  it('event ambang menandai peringatan', () => {
    const daftar = petakanPeserta([mentah(1, 'A', 'mengerjakan', 2)], 3)
    const hasil = terapkanEvent(daftar, { jenis: 'ambang', pesertaId: 1, jumlahKunci: 3 })
    expect(hasil.daftar[0].melebihiAmbang).toBe(true)
  })

  it('alasan buka kunci minimal 5 karakter', () => {
    expect(alasanValid('abc')).toBe(false)
    expect(alasanValid('  abcd ')).toBe(false)
    expect(alasanValid('Layar HP mati')).toBe(true)
  })

  it('merangkum jumlah per status', () => {
    const daftar = petakanPeserta([mentah(1, 'A', 'terkunci'), mentah(2, 'B', 'terkunci'), mentah(3, 'C', 'selesai')], null)
    expect(ringkasanStatus(daftar)).toMatchObject({ terkunci: 2, selesai: 1, mengerjakan: 0 })
  })
})
