# 검증/교육용 데모 환경: 데모 DB 를 새로 만들고(기존 데모 DB 는 삭제) 개발 서버를 띄운다.
# 사용: powershell -File tools\demo.ps1 [-Port 8081] [-NoSamples]
# 개발 DB(storage/db/dev.sqlite)는 건드리지 않는다. 메일은 파일로 저장(storage/mail/*.eml).
param([int]$Port = 8081, [switch]$NoSamples)
$root = Split-Path -Parent $PSScriptRoot
$php = Join-Path $root '.tools\php\php.exe'
if (-not (Test-Path $php)) { $php = 'php' }
$ini = Join-Path $root '.tools\php\php.ini'
$phpArgs = @(); if (Test-Path $ini) { $phpArgs = @('-c', $ini) }

$env:DB_PATH = 'storage/db/demo.sqlite'
$env:MAIL_DRIVER = 'file'
Set-Location $root

$demoArgs = @('--reset'); if ($NoSamples) { $demoArgs += '--no-samples' }
& $php @phpArgs bin\demo.php @demoArgs
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php @phpArgs bin\cron.php | Out-Null

Write-Host "`n데모 서버: http://127.0.0.1:$Port  (중지: Ctrl+C)"
& $php @phpArgs -S "127.0.0.1:$Port" -t (Join-Path $root 'public')
