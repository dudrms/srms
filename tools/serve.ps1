# 개발용 서버(PHP 내장 서버). 운영에서는 Apache/Nginx 를 사용한다.
param([int]$Port = 8080)
$root = Split-Path -Parent $PSScriptRoot
$php = Join-Path $root '.tools\php\php.exe'
if (-not (Test-Path $php)) { $php = 'php' }
$ini = Join-Path $root '.tools\php\php.ini'
$args = @('-S', "127.0.0.1:$Port", '-t', (Join-Path $root 'public'))
if (Test-Path $ini) { $args = @('-c', $ini) + $args }
& $php @args
