# artisan-helper.ps1
# Robust Laravel Artisan runner for this sandbox.
# Root cause fix: the sandbox env block contains a ~300KB var (ACC_PRODUCT_CONFIG_V3)
# and duplicate keys, both of which crash PHP's proc_open on Windows. We strip
# oversized vars via .NET (avoids the PowerShell Env: provider dup-key crash) before
# launching php.exe.
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$ArtisanArgs
)

$phpdir = "C:\Users\86155\.workbuddy\binaries\php\8.4"
$env:PATH = "$phpdir;" + $env:PATH

# Strip oversized env vars that overflow proc_open's stack buffer on Windows.
try {
    $ev = [Environment]::GetEnvironmentVariables()
    foreach ($k in @($ev.Keys)) {
        $v = $ev[$k]
        if ($v -ne $null -and $v.ToString().Length -gt 8192) {
            [Environment]::SetEnvironmentVariable($k, $null)
        }
    }
    [Environment]::SetEnvironmentVariable("ACC_PRODUCT_CONFIG_V3", $null)
} catch {
    # best-effort; continue even if cleanup partially fails
}

Set-Location "D:\03_DevData\Projects\ai-reading-companion"
& "$phpdir\php.exe" "artisan" @ArtisanArgs
exit $LASTEXITCODE
