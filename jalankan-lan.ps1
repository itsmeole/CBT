<#
.SYNOPSIS
  Menjalankan CBT untuk pengujian dari perangkat lain di jaringan yang sama (LAN).

.DESCRIPTION
  - Mendeteksi IP Wi-Fi/LAN laptop ini (atau pakai -Ip).
  - Menulis frontend\.env.local agar frontend memakai IP tersebut.
  - Membuka 5 jendela: server API, antrean, penjadwal (menyelesaikan ujian yang waktunya habis), Reverb (realtime), dan frontend (Vite).
  - Membuat aturan Windows Firewall bila dijalankan sebagai Administrator.

.EXAMPLE
  .\jalankan-lan.ps1            # mulai
  .\jalankan-lan.ps1 -Ip 192.168.1.20
  .\jalankan-lan.ps1 -Cepat    # frontend hasil build (jauh lebih cepat dimuat di HP daripada mode dev)
  .\jalankan-lan.ps1 -HanyaCek  # hanya menampilkan rencana, tanpa menjalankan apa pun
  .\jalankan-lan.ps1 -Bersihkan # hentikan proses, hapus .env.local dan aturan firewall
#>
param(
    [string]$Ip,
    [switch]$HanyaCek,
    [switch]$Cepat,
    [switch]$Bersihkan
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$backend = Join-Path $root 'backend'
$frontend = Join-Path $root 'frontend'
$envLocal = Join-Path $frontend '.env.local'
$berkasPid = Join-Path $env:TEMP 'cbt-lan.pids'
$aturan = 'CBT dev'
$portFrontend = 5173
$portApi = 8000
$portReverb = 8080

function Test-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal $id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-IpLan {
    $konfigurasi = Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -and $_.IPv4Address }
    $pilihan = $konfigurasi | Select-Object -First 1
    if (-not $pilihan) { throw 'Tidak menemukan koneksi jaringan aktif. Sambungkan ke Wi-Fi/LAN atau pakai -Ip.' }
    return $pilihan.IPv4Address[0].IPAddress
}

function Get-Php {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $laragon = Get-ChildItem 'C:\laragon\bin\php' -Directory -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Select-Object -First 1
    if ($laragon -and (Test-Path (Join-Path $laragon.FullName 'php.exe'))) { return (Join-Path $laragon.FullName 'php.exe') }
    throw 'PHP tidak ditemukan di PATH maupun C:\laragon\bin\php.'
}

if ($Bersihkan) {
    if (Test-Path $berkasPid) {
        foreach ($id in (Get-Content $berkasPid)) {
            if ($id -match '^\d+$') { & taskkill /PID $id /T /F 2>$null | Out-Null }
        }
        Remove-Item $berkasPid -Force
        Write-Host 'Proses server dihentikan.'
    }
    if (Test-Path $envLocal) { Remove-Item $envLocal -Force; Write-Host 'frontend\.env.local dihapus.' }
    if (Get-NetFirewallRule -DisplayName $aturan -ErrorAction SilentlyContinue) {
        if (Test-Admin) { Remove-NetFirewallRule -DisplayName $aturan; Write-Host 'Aturan firewall dihapus.' }
        else { Write-Warning "Aturan firewall '$aturan' masih ada. Hapus dengan PowerShell Administrator: Remove-NetFirewallRule -DisplayName '$aturan'" }
    }
    return
}

if (-not $Ip) { $Ip = Get-IpLan }
$php = Get-Php
$urlFrontend = "http://${Ip}:$portFrontend"

Write-Host ''
Write-Host "IP laptop      : $Ip"
Write-Host "PHP            : $php"
Write-Host "Alamat uji     : $urlFrontend   (buka di perangkat lain)"
Write-Host ''

if ($HanyaCek) {
    Write-Host '[HanyaCek] Tidak ada yang dijalankan. Rencana:'
    Write-Host "  - tulis $envLocal (API dan Reverb -> $Ip)"
    Write-Host "  - firewall port $portFrontend,$portApi,$portReverb (aturan '$aturan')"
    Write-Host '  - jendela: php artisan serve, queue:work, schedule:work, reverb:start, npm run dev --host'
    return
}

# 1. Frontend memakai IP LAN
$isi = @(
    "VITE_API_BASE_URL=http://${Ip}:$portApi/api/v1",
    "VITE_REVERB_HOST=$Ip"
)
Set-Content -Path $envLocal -Value $isi -Encoding ascii
Write-Host 'frontend\.env.local ditulis.'

# 2. Firewall
if (-not (Get-NetFirewallRule -DisplayName $aturan -ErrorAction SilentlyContinue)) {
    if (Test-Admin) {
        New-NetFirewallRule -DisplayName $aturan -Direction Inbound -Protocol TCP -LocalPort $portFrontend, $portApi, $portReverb -Action Allow -Profile Any | Out-Null
        Write-Host "Aturan firewall '$aturan' dibuat."
    }
    else {
        Write-Warning 'Aturan firewall belum ada dan skrip tidak berjalan sebagai Administrator.'
        Write-Warning "Perangkat lain mungkin tidak bisa terhubung. Jalankan di PowerShell Administrator:"
        Write-Warning "New-NetFirewallRule -DisplayName '$aturan' -Direction Inbound -Protocol TCP -LocalPort $portFrontend,$portApi,$portReverb -Action Allow -Profile Any"
    }
}

# 2b. Server lama yang hanya mendengarkan localhost menghalangi akses dari perangkat lain: hentikan.
foreach ($port in $portFrontend, $portApi, $portReverb) {
    $lama = Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique
    foreach ($idLama in $lama) {
        $nama = (Get-Process -Id $idLama -ErrorAction SilentlyContinue).ProcessName
        Write-Host "Port $port dipakai proses lama '$nama' (PID $idLama); dihentikan agar bisa dimulai ulang untuk LAN."
        Stop-Process -Id $idLama -Force -ErrorAction SilentlyContinue
    }
}
Start-Sleep -Seconds 1

# 3. Jalankan server. FRONTEND_URL lewat variabel proses (mengalahkan nilai di backend\.env).
$env:FRONTEND_URL = $urlFrontend
& $php (Join-Path $backend 'artisan') config:clear | Out-Null

function Start-Jendela([string]$judul, [string]$folder, [string]$perintah) {
    $kode = "`$Host.UI.RawUI.WindowTitle = '$judul'; Set-Location '$folder'; $perintah"
    return Start-Process powershell -ArgumentList '-NoExit', '-Command', $kode -PassThru
}

$proses = @()
$proses += Start-Jendela 'CBT API' $backend "& '$php' artisan serve --host=0.0.0.0 --port=$portApi"
$proses += Start-Jendela 'CBT Antrean' $backend "& '$php' artisan queue:work --queue=default,reports"
$proses += Start-Jendela 'CBT Penjadwal' $backend "& '$php' artisan schedule:work"
$proses += Start-Jendela 'CBT Reverb' $backend "& '$php' artisan reverb:start --host=0.0.0.0 --port=$portReverb"
if ($Cepat) {
    $proses += Start-Jendela 'CBT Frontend' $frontend "npm run build; if (`$?) { npm run preview -- --host --port $portFrontend --strictPort }"
}
else {
    $proses += Start-Jendela 'CBT Frontend' $frontend 'npm run dev -- --host'
}
$proses | ForEach-Object { $_.Id } | Set-Content -Path $berkasPid

# Pemeriksaan mandiri: apakah frontend dan API terjangkau lewat IP LAN?
Write-Host ''
Write-Host 'Menunggu server siap (maks. 90 dtk)...'
$batas = (Get-Date).AddSeconds(90)
$hasil = @{}
while ((Get-Date) -lt $batas -and $hasil.Count -lt 2) {
    foreach ($cek in @(@('Frontend', $urlFrontend), @('API', "http://${Ip}:$portApi/api/v1/time"))) {
        if (-not $hasil.ContainsKey($cek[0])) {
            try { Invoke-WebRequest $cek[1] -UseBasicParsing -TimeoutSec 3 | Out-Null; $hasil[$cek[0]] = $true; Write-Host "  OK    $($cek[0]): $($cek[1])" } catch { }
        }
    }
    Start-Sleep -Seconds 2
}
foreach ($nama in 'Frontend', 'API') { if (-not $hasil.ContainsKey($nama)) { Write-Warning "$nama belum merespons lewat IP LAN. Lihat jendela 'CBT $nama' untuk pesan galat." } }

Write-Host ''
Write-Host "Selesai. Buka $urlFrontend di perangkat lain (satu jaringan Wi-Fi)."
Write-Host 'Di laptop ini, buka juga lewat alamat tersebut atau http://127.0.0.1:5173 (bukan localhost).'
Write-Host "Cek API dari HP: buka http://${Ip}:$portApi/api/v1/time (harus tampil angka waktu)."
Write-Host 'Berhenti dan bersihkan: .\jalankan-lan.ps1 -Bersihkan'
