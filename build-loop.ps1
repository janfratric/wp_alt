# =============================================================================
# LiteCMS — Autonomous Build Loop (PowerShell)
#
# Detects project state via test suites, identifies the next unblocked chunk(s),
# and optionally spawns Claude agents to implement them.
#
# Usage:
#   .\build-loop.ps1              # Show status and next steps (dry run)
#   .\build-loop.ps1 --run        # Auto-invoke claude for the next chunk
#   .\build-loop.ps1 --parallel   # Auto-invoke claude for all unblocked chunks
#   .\build-loop.ps1 --status     # Show status only
# =============================================================================

param(
    [string]$Mode = "--status"
)

$ErrorActionPreference = "Stop"

$PROJECT_ROOT = Split-Path -Parent $MyInvocation.MyCommand.Path
$TESTS_DIR    = Join-Path $PROJECT_ROOT "tests"
$CHUNKS_DIR   = Join-Path $PROJECT_ROOT "chunks"
$LOCK_DIR     = Join-Path $PROJECT_ROOT "current_tasks"
$STATUS_FILE  = Join-Path $PROJECT_ROOT "STATUS.md"

# --- Dependency graph: chunk -> prerequisites ---
$CHUNK_ORDER = @("1.1","1.2","1.3","2.1","2.2","2.4","2.3","3.1","3.2","4.1","4.2","5.1","5.2","5.3")

$CHUNK_DEPS = @{
    "1.1" = @()
    "1.2" = @("1.1")
    "1.3" = @("1.2")
    "2.1" = @("1.3")
    "2.2" = @("2.1")
    "2.3" = @("2.2")
    "2.4" = @("2.1")
    "3.1" = @("2.1")
    "3.2" = @("3.1")
    "4.1" = @("2.2","2.3","2.4")
    "4.2" = @("4.1")
    "5.1" = @("4.2")
    "5.2" = @("5.1")
    "5.3" = @("5.2")
}

$CHUNK_NAMES = @{
    "1.1" = "Scaffolding & Core Framework"
    "1.2" = "Database Layer & Migrations"
    "1.3" = "Authentication System"
    "2.1" = "Admin Layout & Dashboard"
    "2.2" = "Content CRUD"
    "2.3" = "Media Management"
    "2.4" = "User Management"
    "3.1" = "Template Engine & Front Controller"
    "3.2" = "Public Templates & Styling"
    "4.1" = "Claude API Client & Backend"
    "4.2" = "AI Chat Panel Frontend"
    "5.1" = "Custom Content Types"
    "5.2" = "Settings Panel"
    "5.3" = "Final Polish & Docs"
}

# --- State detection ---

function Test-ChunkComplete {
    param([string]$Chunk)
    $testFile = Join-Path $TESTS_DIR "chunk-${Chunk}-verify.php"
    if (-not (Test-Path $testFile)) { return $false }
    try {
        $null = & php $testFile 2>&1
        return ($LASTEXITCODE -eq 0)
    } catch {
        return $false
    }
}

function Test-ChunkLocked {
    param([string]$Chunk)
    $lockFile = Join-Path $LOCK_DIR "chunk-${Chunk}.lock"
    return (Test-Path $lockFile)
}

function Test-PrereqsMet {
    param([string]$Chunk)
    $deps = $CHUNK_DEPS[$Chunk]
    if ($null -eq $deps -or $deps.Count -eq 0) { return $true }
    foreach ($dep in $deps) {
        if (-not (Test-ChunkComplete $dep)) { return $false }
    }
    return $true
}

# --- Lock file management ---

function Lock-Chunk {
    param([string]$Chunk, [string]$AgentId = "manual")
    if (-not (Test-Path $LOCK_DIR)) { New-Item -ItemType Directory -Path $LOCK_DIR -Force | Out-Null }
    $lockFile = Join-Path $LOCK_DIR "chunk-${Chunk}.lock"
    $timestamp = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
    "${AgentId}, started ${timestamp}, branch: chunk/${Chunk}" | Out-File -FilePath $lockFile -Encoding utf8
}

function Unlock-Chunk {
    param([string]$Chunk)
    $lockFile = Join-Path $LOCK_DIR "chunk-${Chunk}.lock"
    if (Test-Path $lockFile) { Remove-Item $lockFile -Force }
}

# --- Status display ---

function Show-Status {
    Write-Host "=== LiteCMS Build Status ===" -ForegroundColor Cyan
    Write-Host ""

    $completed = 0
    $total = 0
    $nextChunks = @()

    foreach ($chunk in $CHUNK_ORDER) {
        $total++
        $status = "pending"
        $symbol = "[ ]"
        $color = "DarkGray"

        if (Test-ChunkComplete $chunk) {
            $status = "complete"
            $symbol = "[x]"
            $color = "Green"
            $completed++
        } elseif (Test-ChunkLocked $chunk) {
            $status = "in_progress"
            $symbol = "[>]"
            $color = "Yellow"
        } elseif (Test-PrereqsMet $chunk) {
            $status = "ready"
            $symbol = "[~]"
            $color = "White"
            $nextChunks += $chunk
        }

        $name = $CHUNK_NAMES[$chunk]
        Write-Host "  $symbol $chunk — $name " -ForegroundColor $color -NoNewline
        Write-Host "($status)" -ForegroundColor DarkGray
    }

    Write-Host ""
    Write-Host "Progress: ${completed}/${total} chunks complete" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Legend: " -NoNewline
    Write-Host "[x] complete  " -ForegroundColor Green -NoNewline
    Write-Host "[>] in progress  " -ForegroundColor Yellow -NoNewline
    Write-Host "[~] ready  " -ForegroundColor White -NoNewline
    Write-Host "[ ] blocked" -ForegroundColor DarkGray
    Write-Host ""

    if ($completed -eq $total) {
        Write-Host "ALL CHUNKS COMPLETE — ready for review agents" -ForegroundColor Green
        Write-Host "Run specialist reviews with:"
        Write-Host "  claude --print review/security-audit.md"
        Write-Host "  claude --print review/performance-review.md"
        Write-Host "  claude --print review/accessibility-review.md"
        Write-Host "  claude --print review/code-dedup.md"
        Write-Host "  claude --print review/loc-audit.md"
        return
    }

    if ($nextChunks.Count -gt 0) {
        $chunkList = $nextChunks -join ", "
        Write-Host "Ready to implement: $chunkList" -ForegroundColor White
    } else {
        Write-Host "No chunks ready — waiting for in-progress chunks to complete" -ForegroundColor Yellow
    }
}

# --- Agent invocation ---

function Build-Prompt {
    param([string]$Chunk)
    $promptFile = Join-Path $CHUNKS_DIR "${Chunk}-prompt.md"
    $detailFile = Join-Path $PROJECT_ROOT "CHUNK-${Chunk}-PLAN.md"
    $name = $CHUNK_NAMES[$Chunk]

    $prompt = @"
You are implementing LiteCMS chunk ${Chunk}: ${name}.

PROJECT ROOT: $PROJECT_ROOT

Read these files for context:
- STATUS.md (current project state)
- PROMPT.md (full project specification)
"@

    if (Test-Path $promptFile) {
        $prompt += "`n- chunks/${Chunk}-prompt.md (condensed implementation prompt — your primary guide)"
    }
    if (Test-Path $detailFile) {
        $prompt += "`n- CHUNK-${Chunk}-PLAN.md (detailed plan with code templates — reference if stuck)"
    }

    $prompt += @"

INSTRUCTIONS:
1. Read the prompt files listed above
2. Implement all files specified in the chunk plan
3. Run: composer install (if composer.json was created/changed)
4. Run: php tests/chunk-${Chunk}-verify.php
5. Fix any [FAIL] results until all tests show [PASS]
6. Run: php tests/run-all.php --full (cumulative regression check)
7. Update STATUS.md to mark chunk ${Chunk} as complete
8. Commit your changes with message: "feat: implement chunk ${Chunk} — ${name}"
"@

    return $prompt
}

function Invoke-Chunk {
    param([string]$Chunk)

    if (-not (Test-PrereqsMet $Chunk)) {
        Write-Host "ERROR: Prerequisites not met for chunk $Chunk" -ForegroundColor Red
        exit 1
    }

    if (Test-ChunkLocked $Chunk) {
        Write-Host "ERROR: Chunk $Chunk is already locked by another agent" -ForegroundColor Red
        $lockFile = Join-Path $LOCK_DIR "chunk-${Chunk}.lock"
        Get-Content $lockFile
        exit 1
    }

    if (Test-ChunkComplete $Chunk) {
        Write-Host "SKIP: Chunk $Chunk is already complete" -ForegroundColor Yellow
        return $true
    }

    $prompt = Build-Prompt $Chunk
    Lock-Chunk -Chunk $Chunk -AgentId "build-loop"

    $name = $CHUNK_NAMES[$Chunk]
    Write-Host "--- Starting chunk ${Chunk}: ${name} ---" -ForegroundColor Cyan
    Write-Host ""

    try {
        # Invoke claude with the prompt
        $prompt | claude -p
        if ($LASTEXITCODE -ne 0) { throw "Claude agent failed" }

        Write-Host ""
        Write-Host "--- Verifying chunk $Chunk ---" -ForegroundColor Cyan
        $testFile = Join-Path $TESTS_DIR "chunk-${Chunk}-verify.php"
        & php $testFile
        if ($LASTEXITCODE -eq 0) {
            Write-Host ""
            Write-Host "CHUNK $Chunk PASSED" -ForegroundColor Green
            Unlock-Chunk $Chunk
            return $true
        } else {
            Write-Host ""
            Write-Host "CHUNK $Chunk FAILED VERIFICATION — manual intervention needed" -ForegroundColor Red
            Unlock-Chunk $Chunk
            return $false
        }
    } catch {
        Write-Host "CHUNK $Chunk AGENT FAILED — manual intervention needed" -ForegroundColor Red
        Unlock-Chunk $Chunk
        return $false
    }
}

# --- Main ---

switch ($Mode) {
    "--status" {
        Show-Status
    }
    "--run" {
        Show-Status
        Write-Host ""
        Write-Host "=== Running next chunk ===" -ForegroundColor Cyan

        $found = $false
        foreach ($chunk in $CHUNK_ORDER) {
            if (-not (Test-ChunkComplete $chunk) -and -not (Test-ChunkLocked $chunk) -and (Test-PrereqsMet $chunk)) {
                $result = Invoke-Chunk $chunk
                Write-Host ""
                Write-Host "=== Updated status ===" -ForegroundColor Cyan
                Show-Status
                if (-not $result) { exit 1 }
                $found = $true
                break
            }
        }

        if (-not $found) {
            Write-Host "No chunks available to run" -ForegroundColor Yellow
        }
    }
    "--parallel" {
        Show-Status
        Write-Host ""
        Write-Host "=== Running all ready chunks in parallel ===" -ForegroundColor Cyan

        $jobs = @()
        $chunksRunning = @()

        foreach ($chunk in $CHUNK_ORDER) {
            if (-not (Test-ChunkComplete $chunk) -and -not (Test-ChunkLocked $chunk) -and (Test-PrereqsMet $chunk)) {
                Write-Host "Spawning agent for chunk $chunk..."
                $chunkCopy = $chunk
                $job = Start-Job -ScriptBlock {
                    param($ScriptPath, $Chunk)
                    & $ScriptPath --run-single $Chunk
                } -ArgumentList $MyInvocation.MyCommand.Path, $chunkCopy
                $jobs += $job
                $chunksRunning += $chunk
            }
        }

        if ($jobs.Count -eq 0) {
            Write-Host "No chunks available to run in parallel" -ForegroundColor Yellow
            exit 0
        }

        $chunkList = $chunksRunning -join ", "
        Write-Host "Waiting for agents: $chunkList"
        $jobs | Wait-Job | Out-Null
        $failed = 0
        foreach ($job in $jobs) {
            $result = Receive-Job $job
            if ($job.State -eq "Failed") { $failed++ }
            Remove-Job $job
        }

        Write-Host ""
        Write-Host "=== Updated status ===" -ForegroundColor Cyan
        Show-Status

        if ($failed -gt 0) {
            Write-Host "$failed chunk(s) failed" -ForegroundColor Red
            exit 1
        }
    }
    "--run-single" {
        # Internal: used by --parallel to run a single chunk in a job
        $chunk = $args[0]
        if ($null -eq $chunk) {
            Write-Host "ERROR: --run-single requires a chunk ID" -ForegroundColor Red
            exit 1
        }
        $result = Invoke-Chunk $chunk
        if (-not $result) { exit 1 }
    }
    { $_ -in "--help", "-h" } {
        Write-Host "Usage: .\build-loop.ps1 [--status|--run|--parallel|--help]"
        Write-Host ""
        Write-Host "  --status     Show build progress (default)"
        Write-Host "  --run        Implement the next unblocked chunk"
        Write-Host "  --parallel   Implement all unblocked chunks in parallel"
        Write-Host "  --help       Show this help"
    }
    default {
        Write-Host "Unknown option: $Mode" -ForegroundColor Red
        Write-Host "Run .\build-loop.ps1 --help for usage"
        exit 1
    }
}
