#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Automated LiteCMS build pipeline using Claude Code CLI.

.DESCRIPTION
    Repeatedly invokes Claude Code with 0_run.md until all chunks in STATUS.md
    are complete. Each invocation handles one step (plan/prompt/test/execute)
    for one chunk. Runs fully unattended with no manual confirmations.

.PARAMETER MaxRuns
    Safety limit on total invocations. Default 100.

.PARAMETER MaxStuck
    Stop after this many consecutive runs with no progress. Default 3.

.PARAMETER MaxTurns
    Max agentic turns per Claude invocation. Default 0 (unlimited).

.EXAMPLE
    .\run-pipeline.ps1
    .\run-pipeline.ps1 -MaxRuns 50 -MaxStuck 5
#>

param(
    [int]$MaxRuns   = 100,
    [int]$MaxStuck  = 3,
    [int]$MaxTurns  = 0
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Continue"

$projectRoot = $PSScriptRoot
$statusFile  = Join-Path $projectRoot "STATUS.md"
$promptFile  = Join-Path $projectRoot "0_run.md"
$logDir      = Join-Path (Join-Path $projectRoot "logs") "pipeline"

# ── Preflight checks ──────────────────────────────────────────────

if (-not (Get-Command claude -ErrorAction SilentlyContinue)) {
    Write-Host "ERROR: 'claude' not found in PATH. Install Claude Code CLI first." -ForegroundColor Red
    exit 1
}
if (-not (Test-Path $statusFile)) {
    Write-Host "ERROR: STATUS.md not found at $statusFile" -ForegroundColor Red
    exit 1
}
if (-not (Test-Path $promptFile)) {
    Write-Host "ERROR: 0_run.md not found at $promptFile" -ForegroundColor Red
    exit 1
}

New-Item -ItemType Directory -Path $logDir -Force | Out-Null

# ── Helper: parse STATUS.md ───────────────────────────────────────

function Get-ProjectStatus {
    $content = Get-Content $statusFile -Raw

    $completed = 0; $total = 0
    if ($content -match "Progress:\s*(\d+)/(\d+)") {
        $completed = [int]$Matches[1]
        $total     = [int]$Matches[2]
    }

    # Extract next step from the "Next Steps" section
    $nextInfo = ""
    if ($content -match "Step (\d) \(([^)]+)\).+follow ``([^``]+)``") {
        $nextInfo = "Step $($Matches[1]): $($Matches[2])"
    }
    elseif ($content -match "All chunks complete") {
        $nextInfo = "DONE"
    }
    elseif ($content -match "Step 4 failing") {
        $nextInfo = "FIX FAILING"
    }

    # Use file hash to detect any change at all
    $hash = (Get-FileHash $statusFile -Algorithm MD5).Hash

    return @{
        Completed = $completed
        Total     = $total
        NextInfo  = $nextInfo
        Hash      = $hash
    }
}

# ── Main loop ─────────────────────────────────────────────────────

Push-Location $projectRoot

$run       = 0
$stuckRuns = 0

Write-Host "=== LiteCMS Automated Pipeline ===" -ForegroundColor Cyan
Write-Host "Project root : $projectRoot"
Write-Host "Max runs     : $MaxRuns"
Write-Host "Stuck limit  : $MaxStuck"
Write-Host ""

try {
    while ($run -lt $MaxRuns) {

        # ── Check current state ──
        $before = Get-ProjectStatus

        if ($before.Completed -eq $before.Total -and $before.Total -gt 0) {
            Write-Host "`nAll $($before.Total) chunks complete! Pipeline finished." -ForegroundColor Green
            exit 0
        }

        $run++
        $timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
        $logFile   = Join-Path $logDir "run-${run}_${timestamp}.log"

        Write-Host "`n------------------------------------------------" -ForegroundColor DarkGray
        Write-Host "Run $run  |  $($before.Completed)/$($before.Total) done  |  $($before.NextInfo)" -ForegroundColor Yellow
        Write-Host "------------------------------------------------" -ForegroundColor DarkGray

        # ── Invoke Claude Code CLI ──
        $prompt = Get-Content $promptFile -Raw

        $claudeArgs = @("--print", "--dangerously-skip-permissions", "--verbose",
                        "--output-format", "stream-json")
        if ($MaxTurns -gt 0) {
            $claudeArgs += "--max-turns"
            $claudeArgs += $MaxTurns.ToString()
        }
        $claudeArgs += $prompt

        ">>> Run $run started at $(Get-Date -Format o)" | Out-File $logFile -Encoding utf8

        & claude @claudeArgs 2>&1 | ForEach-Object {
            $line = $_
            $line | Out-File $logFile -Append -Encoding utf8
            # Parse stream-json lines for human-readable progress
            if ($line -match '"type"\s*:\s*"assistant"') {
                # Assistant text output
                if ($line -match '"content"\s*:\s*"([^"]*)"') {
                    Write-Host "  [claude] $($Matches[1])" -ForegroundColor Gray
                }
            }
            elseif ($line -match '"tool"\s*:\s*"(\w+)"') {
                $tool = $Matches[1]
                $ts = Get-Date -Format "HH:mm:ss"
                Write-Host "  [$ts] tool: $tool" -ForegroundColor DarkCyan
            }
            elseif ($line -match '"type"\s*:\s*"result"') {
                Write-Host "  [result] Claude finished this turn" -ForegroundColor DarkGray
            }
            else {
                # Log everything else silently (still goes to log file)
            }
        }

        $claudeExit = $LASTEXITCODE
        ">>> Claude exited with code $claudeExit" | Out-File $logFile -Append -Encoding utf8

        if ($claudeExit -ne 0) {
            Write-Host "Claude exited with code $claudeExit" -ForegroundColor Red
        }

        # ── Refresh STATUS.md via test runner ──
        Write-Host "`nRefreshing STATUS.md..." -ForegroundColor DarkGray
        & php (Join-Path $projectRoot "tests" "run-all.php") --quick 2>&1 |
            Tee-Object -FilePath $logFile -Append

        # ── Detect progress ──
        $after = Get-ProjectStatus

        if ($after.Hash -eq $before.Hash) {
            $stuckRuns++
            Write-Host "No change detected. Stuck $stuckRuns/$MaxStuck" -ForegroundColor Red
            if ($stuckRuns -ge $MaxStuck) {
                Write-Host "`nPipeline stuck after $MaxStuck consecutive runs with no progress." -ForegroundColor Red
                Write-Host "Last log: $logFile"
                exit 1
            }
        }
        else {
            $stuckRuns = 0
            if ($after.Completed -gt $before.Completed) {
                Write-Host "Chunk completed! $($before.Completed) -> $($after.Completed)/$($after.Total)" -ForegroundColor Green
            }
            else {
                Write-Host "Step progressed: $($before.NextInfo) -> $($after.NextInfo)" -ForegroundColor Green
            }
        }
    }

    Write-Host "`nReached max runs ($MaxRuns). Stopping." -ForegroundColor Yellow
    exit 1
}
finally {
    Pop-Location
}
