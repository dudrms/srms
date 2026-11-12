# 모든 PHP 파일에 문법 검사(php -l)를 수행한다.
$root = Split-Path -Parent $PSScriptRoot
$php = Join-Path $root '.tools\php\php.exe'
if (-not (Test-Path $php)) { $php = 'php' }

$files = Get-ChildItem -Path $root -Recurse -Filter *.php -File |
    Where-Object { $_.FullName -notmatch '\\(\.tools|vendor|node_modules)\\' }

$failed = 0
foreach ($f in $files) {
    $out = & $php -l $f.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        $failed++
        Write-Host "FAIL $($f.FullName)" -ForegroundColor Red
        Write-Host $out
    }
}
Write-Host ("checked {0} files, {1} failed" -f $files.Count, $failed)
exit $(if ($failed -gt 0) { 1 } else { 0 })
