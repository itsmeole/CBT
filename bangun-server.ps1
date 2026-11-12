<#
.SYNOPSIS
  Menyiapkan CBT untuk diunggah ke satu situs di server (frontend dan API di domain yang sama).

.DESCRIPTION
  1. Membangun frontend dengan alamat API relatif (/api/v1), jadi tidak perlu tahu domain.
  2. Menyalin hasil build ke backend\public (index.html diganti nama menjadi app.html; Laravel yang menyajikannya).
  3. Dengan -Paket: membuat cbt-server.zip berisi backend siap unggah (tanpa vendor, .env, tes, dan berkas pengembangan).

.EXAMPLE
  .\bangun-server.ps1           # build dan salin ke backend\public
  .\bangun-server.ps1 -Paket    # ditambah cbt-server.zip di folder ini
#>
param([switch]$Paket)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$frontend = Join-Path $root 'frontend'
$publik = Join-Path $root 'backend\public'

Write-Host '1/3 Membangun frontend...'
$env:VITE_API_BASE_URL = '/api/v1'
$envLokal = Join-Path $frontend '.env.local'
$cadangan = $null
if (Test-Path $envLokal) {
    # .env.local dari uji LAN (mengarah ke IP laptop) akan menimpa; singkirkan sementara.
    $cadangan = "$envLokal.bak"
    Move-Item $envLokal $cadangan -Force
}
try {
    Push-Location $frontend
    npm run build
    if ($LASTEXITCODE -ne 0) { throw 'Build frontend gagal.' }
}
finally {
    Pop-Location
    if ($cadangan) { Move-Item $cadangan $envLokal -Force }
}

Write-Host '2/3 Menyalin ke backend\public...'
$dist = Join-Path $frontend 'dist'
# Hapus hasil build lama agar berkas asset yang sudah tidak dipakai tidak menumpuk.
Remove-Item (Join-Path $publik 'assets') -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $publik 'app.html') -Force -ErrorAction SilentlyContinue
Get-ChildItem $dist | Where-Object { $_.Name -ne 'index.html' } | ForEach-Object {
    Copy-Item $_.FullName $publik -Recurse -Force
}
Copy-Item (Join-Path $dist 'index.html') (Join-Path $publik 'app.html') -Force
Write-Host '   OK: public\app.html dan public\assets diperbarui.'

if ($Paket) {
    Write-Host '3/3 Membuat cbt-server.zip...'
    $zip = Join-Path $root 'cbt-server.zip'
    Remove-Item $zip -Force -ErrorAction SilentlyContinue
    $kecualikan = 'vendor', 'node_modules', 'tests', 'backups', '.git', '.claude', '.ai', '.phpunit.cache'
    $backend = Join-Path $root 'backend'
    $sementara = Join-Path $env:TEMP 'cbt-paket'
    Remove-Item $sementara -Recurse -Force -ErrorAction SilentlyContinue
    New-Item -ItemType Directory $sementara | Out-Null
    # robocopy lebih cepat dan mudah mengecualikan folder/berkas.
    & robocopy $backend $sementara /E /XD $kecualikan 'logs' /XF '.env' '.env.backup' '*.log' 'database.sqlite' '*.xlsx' /NFL /NDL /NJH /NJS /NP | Out-Null
    # storage harus ada kerangkanya meski isinya kosong.
    foreach ($d in 'framework\cache', 'framework\sessions', 'framework\views', 'logs', 'app\public', 'app\private') {
        New-Item -ItemType Directory (Join-Path $sementara "storage\$d") -Force | Out-Null
    }
    # Dibuat lewat .NET agar jalur memakai garis miring (/): Compress-Archive bawaan PowerShell 5.1 memakai
    # garis miring terbalik yang dapat merusak struktur folder saat diekstrak di Linux.
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $arsip = [System.IO.Compression.ZipFile]::Open($zip, 'Create')
    try {
        $dasar = (Resolve-Path $sementara).Path.TrimEnd([char]92) + [char]92
        Get-ChildItem $sementara -Recurse -File | ForEach-Object {
            $relatif = $_.FullName.Substring($dasar.Length).Replace([char]92, [char]47)
            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($arsip, $_.FullName, $relatif, 'Optimal')
        }
    }
    finally { $arsip.Dispose() }
    Remove-Item $sementara -Recurse -Force
    Write-Host "   OK: $zip"
}
else {
    Write-Host '3/3 Lewati paket (pakai -Paket untuk membuat cbt-server.zip).'
}

Write-Host ''
Write-Host 'Selesai. Di server: jalankan composer install --no-dev, atur .env, php artisan migrate --force, lalu php artisan config:cache.'
