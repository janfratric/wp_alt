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

        # Helper: safe property access for StrictMode compatibility
        function Get-Prop ($obj, [string]$name) {
            if ($null -eq $obj) { return $null }
            if ($obj -is [System.Management.Automation.PSCustomObject] -and
                $obj.PSObject.Properties.Match($name).Count -gt 0) {
                return $obj.$name
            }
            if ($obj -is [hashtable] -and $obj.ContainsKey($name)) {
                return $obj[$name]
            }
            return $null
        }

        function Truncate ([string]$s, [int]$max) {
            if ($s.Length -gt $max) { return $s.Substring(0, $max) + "..." }
            return $s
        }

        & claude @claudeArgs 2>&1 | ForEach-Object {
            $line = $_
            $line | Out-File $logFile -Append -Encoding utf8

            # Try parsing as JSON for structured output
            $json = $null
            try { $json = $line | ConvertFrom-Json -ErrorAction Stop } catch {}

            if ($null -eq $json) {
                $trimmed = "$line".Trim()
                if ($trimmed.Length -gt 0) {
                    Write-Host "  $trimmed" -ForegroundColor DarkGray
                }
                return
            }

            $ts = Get-Date -Format "HH:mm:ss"
            $msgType = Get-Prop $json "type"

            switch ($msgType) {
                "assistant" {
                    $msg = Get-Prop $json "message"
                    $contentBlocks = Get-Prop $msg "content"
                    if ($null -ne $contentBlocks) {
                        foreach ($block in $contentBlocks) {
                            $blockType = Get-Prop $block "type"
                            if ($blockType -eq "text") {
                                $text = Get-Prop $block "text"
                                if ($text) {
                                    foreach ($textLine in ($text -split "`n")) {
                                        Write-Host "  [claude] $textLine" -ForegroundColor White
                                    }
                                }
                            }
                            elseif ($blockType -eq "tool_use") {
                                $toolName = Get-Prop $block "name"
                                $toolId = Get-Prop $block "id"
                                $idSuffix = ""
                                if ($toolId) { $idSuffix = " ($(Truncate $toolId 8))" }
                                Write-Host "  [$ts] TOOL_CALL: $toolName$idSuffix" -ForegroundColor Cyan

                                $inp = Get-Prop $block "input"
                                if ($null -ne $inp) {
                                    switch ($toolName) {
                                        "Read" {
                                            $fp = Get-Prop $inp "file_path"
                                            if ($fp) { Write-Host "           file: $fp" -ForegroundColor DarkCyan }
                                        }
                                        "Write" {
                                            $fp = Get-Prop $inp "file_path"
                                            $ct = Get-Prop $inp "content"
                                            $len = if ($ct) { $ct.Length } else { 0 }
                                            if ($fp) { Write-Host "           file: $fp ($len chars)" -ForegroundColor DarkCyan }
                                        }
                                        "Edit" {
                                            $fp = Get-Prop $inp "file_path"
                                            $old = Get-Prop $inp "old_string"
                                            if ($fp) { Write-Host "           file: $fp" -ForegroundColor DarkCyan }
                                            if ($old) {
                                                $oldClean = (Truncate ($old -replace "`n", " ") 60)
                                                Write-Host "           old:  $oldClean" -ForegroundColor DarkGray
                                            }
                                        }
                                        "Bash" {
                                            $cmd = Get-Prop $inp "command"
                                            $desc = Get-Prop $inp "description"
                                            if ($cmd) { Write-Host "           cmd:  $(Truncate $cmd 120)" -ForegroundColor DarkCyan }
                                            if ($desc) { Write-Host "           desc: $desc" -ForegroundColor DarkGray }
                                        }
                                        "Glob" {
                                            $pat = Get-Prop $inp "pattern"
                                            if ($pat) { Write-Host "           pattern: $pat" -ForegroundColor DarkCyan }
                                        }
                                        "Grep" {
                                            $pat = Get-Prop $inp "pattern"
                                            $gp = Get-Prop $inp "path"
                                            if ($pat) { Write-Host "           pattern: $pat" -ForegroundColor DarkCyan }
                                            if ($gp) { Write-Host "           path: $gp" -ForegroundColor DarkCyan }
                                        }
                                        "Task" {
                                            $td = Get-Prop $inp "description"
                                            $tt = Get-Prop $inp "subagent_type"
                                            if ($td) { Write-Host "           desc: $td" -ForegroundColor DarkCyan }
                                            if ($tt) { Write-Host "           type: $tt" -ForegroundColor DarkCyan }
                                        }
                                        "TodoWrite" {
                                            $todos = Get-Prop $inp "todos"
                                            if ($todos) {
                                                foreach ($todo in $todos) {
                                                    $st = Get-Prop $todo "status"
                                                    $ct = Get-Prop $todo "content"
                                                    $icon = switch ($st) { "completed" { "[done]" } "in_progress" { "[>>>> ]" } default { "[    ]" } }
                                                    $color = switch ($st) { "completed" { "Green" } "in_progress" { "Yellow" } default { "Gray" } }
                                                    Write-Host "           $icon $ct" -ForegroundColor $color
                                                }
                                            }
                                        }
                                        default {
                                            $inpStr = ($inp | ConvertTo-Json -Depth 2 -Compress)
                                            Write-Host "           input: $(Truncate $inpStr 200)" -ForegroundColor DarkGray
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                "tool_result" {
                    $toolName = Get-Prop $json "tool_name"
                    if (-not $toolName) { $toolName = "?" }
                    $isError = (Get-Prop $json "is_error") -eq $true
                    $content = Get-Prop $json "content"
                    if ($isError) {
                        Write-Host "  [$ts] TOOL_ERR: $toolName" -ForegroundColor Red
                        if ($content) {
                            $errText = Truncate ("$content".Trim()) 300
                            foreach ($errLine in ($errText -split "`n" | Select-Object -First 5)) {
                                Write-Host "           $errLine" -ForegroundColor Red
                            }
                        }
                    } else {
                        $contentLen = if ($content) { "$content".Length } else { 0 }
                        Write-Host "  [$ts] TOOL_OK:  $toolName ($contentLen chars)" -ForegroundColor DarkGreen
                    }
                }
                "result" {
                    Write-Host "  [$ts] === Claude finished ===" -ForegroundColor Magenta
                    $resText = Get-Prop $json "result"
                    if ($resText) {
                        foreach ($resLine in ("$resText".Trim() -split "`n" | Select-Object -First 10)) {
                            Write-Host "  [result] $resLine" -ForegroundColor Gray
                        }
                    }
                    $cost = Get-Prop $json "cost_usd"
                    if ($cost) { Write-Host "  [cost] `$$cost" -ForegroundColor DarkYellow }
                    $dur = Get-Prop $json "duration_ms"
                    if ($dur) {
                        $mins = [Math]::Round($dur / 60000, 1)
                        Write-Host "  [time] $($mins)m" -ForegroundColor DarkYellow
                    }
                }
                "system" {
                    $sysMsg = Get-Prop $json "message"
                    if ($sysMsg) { Write-Host "  [$ts] [system] $sysMsg" -ForegroundColor DarkMagenta }
                }
                default {
                    if ($msgType) { Write-Host "  [$ts] [$msgType]" -ForegroundColor DarkGray }
                }
            }
        }

        $claudeExit = $LASTEXITCODE
        ">>> Claude exited with code $claudeExit" | Out-File $logFile -Append -Encoding utf8

        if ($claudeExit -ne 0) {
            Write-Host "Claude exited with code $claudeExit" -ForegroundColor Red
        }

        # ── Refresh STATUS.md via test runner ──
        Write-Host "`nRefreshing STATUS.md..." -ForegroundColor DarkGray
        & php (Join-Path (Join-Path $projectRoot "tests") "run-all.php") --quick 2>&1 |
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
