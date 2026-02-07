#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# LiteCMS — Autonomous Build Loop
#
# Detects project state via test suites, identifies the next unblocked chunk(s),
# and optionally spawns Claude agents to implement them.
#
# Usage:
#   ./build-loop.sh              # Show status and next steps (dry run)
#   ./build-loop.sh --run        # Auto-invoke claude for the next chunk
#   ./build-loop.sh --parallel   # Auto-invoke claude for all unblocked chunks
#   ./build-loop.sh --status     # Show status only
# =============================================================================

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
TESTS_DIR="$PROJECT_ROOT/tests"
CHUNKS_DIR="$PROJECT_ROOT/chunks"
LOCK_DIR="$PROJECT_ROOT/current_tasks"
STATUS_FILE="$PROJECT_ROOT/STATUS.md"

# --- Dependency graph: chunk → space-separated prerequisites ---
# Order matters for display; deps define the actual graph
CHUNK_ORDER="1.1 1.2 1.3 2.1 2.2 2.4 2.3 3.1 3.2 4.1 4.2 5.1 5.2 5.3"

get_deps() {
    case "$1" in
        1.1) echo "" ;;
        1.2) echo "1.1" ;;
        1.3) echo "1.2" ;;
        2.1) echo "1.3" ;;
        2.2) echo "2.1" ;;
        2.3) echo "2.2" ;;
        2.4) echo "2.1" ;;
        3.1) echo "2.1" ;;
        3.2) echo "3.1" ;;
        4.1) echo "2.2 2.3 2.4" ;;
        4.2) echo "4.1" ;;
        5.1) echo "4.2" ;;
        5.2) echo "5.1" ;;
        5.3) echo "5.2" ;;
        *)   echo "UNKNOWN" ;;
    esac
}

# Chunk descriptions for display
get_name() {
    case "$1" in
        1.1) echo "Scaffolding & Core Framework" ;;
        1.2) echo "Database Layer & Migrations" ;;
        1.3) echo "Authentication System" ;;
        2.1) echo "Admin Layout & Dashboard" ;;
        2.2) echo "Content CRUD" ;;
        2.3) echo "Media Management" ;;
        2.4) echo "User Management" ;;
        3.1) echo "Template Engine & Front Controller" ;;
        3.2) echo "Public Templates & Styling" ;;
        4.1) echo "Claude API Client & Backend" ;;
        4.2) echo "AI Chat Panel Frontend" ;;
        5.1) echo "Custom Content Types" ;;
        5.2) echo "Settings Panel" ;;
        5.3) echo "Final Polish & Docs" ;;
    esac
}

# --- State detection ---

# Check if a chunk's tests pass
chunk_complete() {
    local chunk="$1"
    local test_file="$TESTS_DIR/chunk-${chunk}-verify.php"
    if [[ ! -f "$test_file" ]]; then
        return 1
    fi
    php "$test_file" > /dev/null 2>&1
    return $?
}

# Check if a chunk is currently locked by an agent
chunk_locked() {
    local chunk="$1"
    [[ -f "$LOCK_DIR/chunk-${chunk}.lock" ]]
}

# Check if all prerequisites for a chunk are complete
prereqs_met() {
    local chunk="$1"
    local deps
    deps=$(get_deps "$chunk")
    if [[ -z "$deps" ]]; then
        return 0
    fi
    for dep in $deps; do
        if ! chunk_complete "$dep"; then
            return 1
        fi
    done
    return 0
}

# --- Lock file management ---

lock_chunk() {
    local chunk="$1"
    local agent_id="${2:-manual}"
    mkdir -p "$LOCK_DIR"
    echo "${agent_id}, started $(date -u +%Y-%m-%dT%H:%M:%SZ), branch: chunk/${chunk}" \
        > "$LOCK_DIR/chunk-${chunk}.lock"
}

unlock_chunk() {
    local chunk="$1"
    rm -f "$LOCK_DIR/chunk-${chunk}.lock"
}

# --- Status display ---

show_status() {
    echo "=== LiteCMS Build Status ==="
    echo ""

    local completed=0
    local total=0
    local next_chunks=""

    for chunk in $CHUNK_ORDER; do
        total=$((total + 1))
        local status="pending"
        local symbol="[ ]"

        if chunk_complete "$chunk"; then
            status="complete"
            symbol="[x]"
            completed=$((completed + 1))
        elif chunk_locked "$chunk"; then
            status="in_progress"
            symbol="[>]"
        elif prereqs_met "$chunk"; then
            status="ready"
            symbol="[~]"
            next_chunks="$next_chunks $chunk"
        fi

        printf "  %s %s — %s (%s)\n" "$symbol" "$chunk" "$(get_name "$chunk")" "$status"
    done

    echo ""
    echo "Progress: ${completed}/${total} chunks complete"
    echo ""
    echo "Legend: [x] complete  [>] in progress  [~] ready  [ ] blocked"
    echo ""

    if [[ $completed -eq $total ]]; then
        echo "ALL CHUNKS COMPLETE — ready for review agents"
        echo "Run specialist reviews with:"
        echo "  claude --print review/security-audit.md"
        echo "  claude --print review/performance-review.md"
        echo "  claude --print review/accessibility-review.md"
        echo "  claude --print review/code-dedup.md"
        echo "  claude --print review/loc-audit.md"
        return 0
    fi

    if [[ -n "$next_chunks" ]]; then
        echo "Ready to implement:$next_chunks"
    else
        echo "No chunks ready — waiting for in-progress chunks to complete"
    fi
}

# --- Agent invocation ---

build_prompt() {
    local chunk="$1"
    local prompt_file="$CHUNKS_DIR/${chunk}-prompt.md"
    local detail_file="$PROJECT_ROOT/CHUNK-${chunk}-PLAN.md"

    # Build the agent prompt
    local prompt="You are implementing LiteCMS chunk ${chunk}: $(get_name "$chunk").

PROJECT ROOT: $PROJECT_ROOT

Read these files for context:
- STATUS.md (current project state)
- PROMPT.md (full project specification)"

    if [[ -f "$prompt_file" ]]; then
        prompt="$prompt
- chunks/${chunk}-prompt.md (condensed implementation prompt — your primary guide)"
    fi

    if [[ -f "$detail_file" ]]; then
        prompt="$prompt
- CHUNK-${chunk}-PLAN.md (detailed plan with code templates — reference if stuck)"
    fi

    prompt="$prompt

INSTRUCTIONS:
1. Read the prompt files listed above
2. Implement all files specified in the chunk plan
3. Run: composer install (if composer.json was created/changed)
4. Run: php tests/chunk-${chunk}-verify.php
5. Fix any [FAIL] results until all tests show [PASS]
6. Run: php tests/run-all.php --full (cumulative regression check)
7. Update STATUS.md to mark chunk ${chunk} as complete
8. Commit your changes with message: \"feat: implement chunk ${chunk} — $(get_name "$chunk")\""

    echo "$prompt"
}

run_chunk() {
    local chunk="$1"
    local prompt

    if ! prereqs_met "$chunk"; then
        echo "ERROR: Prerequisites not met for chunk $chunk"
        exit 1
    fi

    if chunk_locked "$chunk"; then
        echo "ERROR: Chunk $chunk is already locked by another agent"
        cat "$LOCK_DIR/chunk-${chunk}.lock"
        exit 1
    fi

    if chunk_complete "$chunk"; then
        echo "SKIP: Chunk $chunk is already complete"
        return 0
    fi

    prompt=$(build_prompt "$chunk")
    lock_chunk "$chunk" "build-loop"

    echo "--- Starting chunk $chunk: $(get_name "$chunk") ---"
    echo ""

    # Invoke claude with the prompt
    # Uses --print to pass the prompt via stdin
    if claude -p "$prompt"; then
        # Verify tests pass after agent finishes
        echo ""
        echo "--- Verifying chunk $chunk ---"
        if php "$TESTS_DIR/chunk-${chunk}-verify.php"; then
            echo ""
            echo "CHUNK $chunk PASSED"
            unlock_chunk "$chunk"
            return 0
        else
            echo ""
            echo "CHUNK $chunk FAILED VERIFICATION — manual intervention needed"
            unlock_chunk "$chunk"
            return 1
        fi
    else
        echo "CHUNK $chunk AGENT FAILED — manual intervention needed"
        unlock_chunk "$chunk"
        return 1
    fi
}

# --- Main ---

MODE="${1:---status}"

case "$MODE" in
    --status)
        show_status
        ;;
    --run)
        show_status
        echo ""
        echo "=== Running next chunk ==="

        # Find first ready chunk
        for chunk in $CHUNK_ORDER; do
            if ! chunk_complete "$chunk" && ! chunk_locked "$chunk" && prereqs_met "$chunk"; then
                run_chunk "$chunk"
                echo ""
                echo "=== Updated status ==="
                show_status
                exit $?
            fi
        done

        echo "No chunks available to run"
        ;;
    --parallel)
        show_status
        echo ""
        echo "=== Running all ready chunks in parallel ==="

        pids=""
        chunks_running=""

        for chunk in $CHUNK_ORDER; do
            if ! chunk_complete "$chunk" && ! chunk_locked "$chunk" && prereqs_met "$chunk"; then
                echo "Spawning agent for chunk $chunk..."
                run_chunk "$chunk" &
                pids="$pids $!"
                chunks_running="$chunks_running $chunk"
            fi
        done

        if [[ -z "$pids" ]]; then
            echo "No chunks available to run in parallel"
            exit 0
        fi

        echo "Waiting for agents:$chunks_running"
        failed=0
        for pid in $pids; do
            if ! wait "$pid"; then
                failed=$((failed + 1))
            fi
        done

        echo ""
        echo "=== Updated status ==="
        show_status

        if [[ $failed -gt 0 ]]; then
            echo "$failed chunk(s) failed"
            exit 1
        fi
        ;;
    --help|-h)
        echo "Usage: ./build-loop.sh [--status|--run|--parallel|--help]"
        echo ""
        echo "  --status     Show build progress (default)"
        echo "  --run        Implement the next unblocked chunk"
        echo "  --parallel   Implement all unblocked chunks in parallel"
        echo "  --help       Show this help"
        ;;
    *)
        echo "Unknown option: $MODE"
        echo "Run ./build-loop.sh --help for usage"
        exit 1
        ;;
esac
