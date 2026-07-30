<#
.SYNOPSIS
    Build a distributable stemageddon-lms-{version}.zip on Windows.

.DESCRIPTION
    Windows-native equivalent of bin/package.sh, which needs `zip` and `rsync`
    (neither is on PATH on this box). The exclude list below mirrors package.sh
    exactly. lib/ is intentionally KEPT: it holds plugin-update-checker, which is
    what lets every install see updates in its normal Updates screen.

    Version is read from the SGLMS_VERSION constant so the zip can never
    disagree with the plugin header.

.EXAMPLE
    pwsh -File bin/package.ps1
#>

[CmdletBinding()]
param(
    [string] $OutDir
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$PluginSlug = 'stemageddon-lms'
$SrcDir     = Split-Path -Parent $PSScriptRoot          # .../stemageddon-lms
$ProjectDir = Split-Path -Parent $SrcDir                # .../stemageddon-lms-plugin
if (-not $OutDir) { $OutDir = $ProjectDir }

# --- Resolve the version from the source of truth -------------------------
$bootstrap = Join-Path $SrcDir "$PluginSlug.php"
if (-not (Test-Path $bootstrap)) {
    throw "Cannot find plugin bootstrap at $bootstrap"
}

$content = Get-Content -Raw -LiteralPath $bootstrap
$m = [regex]::Match($content, "define\(\s*'SGLMS_VERSION'\s*,\s*'([^']+)'\s*\)")
if (-not $m.Success) { throw 'Could not read SGLMS_VERSION from the bootstrap file.' }
$version = $m.Groups[1].Value

# Cross-check the plugin header so a half-done bump fails loudly here rather
# than shipping a zip whose header and constant disagree.
$h = [regex]::Match($content, '(?m)^\s*\*\s*Version:\s*(\S+)\s*$')
if (-not $h.Success) { throw 'Could not read the Version: header.' }
if ($h.Groups[1].Value -ne $version) {
    throw "Version mismatch: header=$($h.Groups[1].Value) constant=$version"
}

$readme = Join-Path $SrcDir 'readme.txt'
if (Test-Path $readme) {
    $r = [regex]::Match((Get-Content -Raw -LiteralPath $readme), '(?m)^Stable tag:\s*(\S+)\s*$')
    if ($r.Success -and $r.Groups[1].Value -ne $version) {
        throw "Version mismatch: readme.txt Stable tag=$($r.Groups[1].Value) constant=$version"
    }
}

Write-Host "Packaging $PluginSlug $version" -ForegroundColor Cyan

# --- Stage a pruned copy --------------------------------------------------
$stage    = Join-Path ([System.IO.Path]::GetTempPath()) ("sglms-pkg-" + [guid]::NewGuid().ToString('N'))
$stageDir = Join-Path $stage $PluginSlug
New-Item -ItemType Directory -Path $stageDir -Force | Out-Null

try {
    Copy-Item -Path (Join-Path $SrcDir '*') -Destination $stageDir -Recurse -Force

    # Mirrors the --exclude list in bin/package.sh.
    $excludeDirs = @('.git', '.github', 'node_modules', 'vendor', 'bin', 'docs', 'tests')
    $excludeFiles = @(
        '.gitignore', 'composer.json', 'composer.lock',
        'phpcs.xml.dist', '.phpcs.cache', '.phpunit.result.cache'
    )

    # Prune directories by name, deepest first so nested matches are removed
    # before their parents disappear from under them.
    Get-ChildItem -LiteralPath $stageDir -Recurse -Force -Directory |
        Where-Object { $excludeDirs -contains $_.Name } |
        Sort-Object { $_.FullName.Length } -Descending |
        ForEach-Object { Remove-Item -LiteralPath $_.FullName -Recurse -Force }

    # assets/src is a path-specific exclude, not a bare name.
    $assetsSrc = Join-Path $stageDir 'assets\src'
    if (Test-Path $assetsSrc) { Remove-Item -LiteralPath $assetsSrc -Recurse -Force }

    Get-ChildItem -LiteralPath $stageDir -Recurse -Force -File |
        Where-Object { $excludeFiles -contains $_.Name -or $_.Extension -eq '.zip' } |
        ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force }

    # --- Sanity checks before zipping ------------------------------------
    if (-not (Test-Path (Join-Path $stageDir 'lib'))) {
        throw 'lib/ is missing from the stage. The update checker must ship.'
    }
    if (Test-Path (Join-Path $stageDir 'bin')) {
        throw 'bin/ survived pruning.'
    }

    $phpCount = (Get-ChildItem -LiteralPath $stageDir -Recurse -Force -Filter '*.php').Count
    Write-Host "  staged $phpCount php files" -ForegroundColor DarkGray

    # --- Zip with a top-level plugin folder ------------------------------
    $outZip = Join-Path $OutDir "$PluginSlug-$version.zip"
    if (Test-Path $outZip) { Remove-Item -LiteralPath $outZip -Force }

    Compress-Archive -Path $stageDir -DestinationPath $outZip -CompressionLevel Optimal

    $sizeKb = [math]::Round((Get-Item -LiteralPath $outZip).Length / 1KB, 1)
    Write-Host "Built: $outZip ($sizeKb KB)" -ForegroundColor Green
    Write-Output $outZip
}
finally {
    if (Test-Path $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
}
