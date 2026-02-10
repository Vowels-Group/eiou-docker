#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC
#
# Best-fee routing benchmark: runs the bestfee test N times and summarizes results.
# Each run rebuilds the collisions topology with fresh random fees.
#
# Usage: ./benchmark-bestfee.sh [runs] [mode]
#   runs  - Number of benchmark runs (default: 10)
#   mode  - Transport mode: http or tor (default: http)
#
# Example: ./benchmark-bestfee.sh 10 http

RUNS="${1:-10}"
MODE="${2:-http}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo ""
echo "============================================"
echo "  Best-Fee Routing Benchmark (${RUNS} runs)"
echo "============================================"
echo "Mode: ${MODE}"
echo "Time: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

declare -a results
declare -a fast_times
declare -a bestfee_times
declare -a fast_fees
declare -a bestfee_fees
declare -a optimal_fees
optimal=0
better=0
same_optimal=0
same_suboptimal=0
worse=0
failures=0

for ((run=1; run<=RUNS; run++)); do
    echo ""
    echo "============================================"
    printf "  ${GREEN}--- Run ${run}/${RUNS} ---${NC}\n"
    echo "============================================"

    # Run the full test (build + contacts + bestfee)
    output=$(cd /mnt/c/Users/fcvan/eiou/ai-dev/github/eiou-docker/tests && SKIP_CLEANUP=1 ./run-all-tests.sh collisions "$MODE" bestfee 2>&1)

    # Extract timing
    fast_time=$(echo "$output" | grep -oP 'Fast mode \(first route\):\s+\K\d+')
    bestfee_time=$(echo "$output" | grep -oP 'Best-fee mode \(optimal\):\s+\K\d+')

    # Extract fee multipliers from Path Comparison section
    fast_fee=$(echo "$output" | grep 'Fast path:' | tail -1 | grep -oP '[0-9]+\.[0-9]+x' | head -1 | tr -d 'x')
    bestfee_fee=$(echo "$output" | grep 'Best-fee path:' | tail -1 | grep -oP '[0-9]+\.[0-9]+x' | head -1 | tr -d 'x')
    optimal_fee=$(echo "$output" | grep 'Optimal fee:' | tail -1 | grep -oP '[0-9]+\.[0-9]+x' | head -1 | tr -d 'x')

    # Extract RESULT line
    result_line=$(echo "$output" | grep 'RESULT:' | tail -1)

    # Determine result category
    if echo "$result_line" | grep -q 'RESULT: OPTIMAL'; then
        category="OPTIMAL"
        optimal=$((optimal + 1))
    elif echo "$result_line" | grep -q 'RESULT: BETTER'; then
        category="BETTER (not optimal)"
        better=$((better + 1))
    elif echo "$result_line" | grep -q 'RESULT: SAME (optimal)'; then
        category="SAME (optimal)"
        same_optimal=$((same_optimal + 1))
    elif echo "$result_line" | grep -q 'RESULT: SAME (sub-optimal)'; then
        category="SAME (sub-optimal)"
        same_suboptimal=$((same_suboptimal + 1))
    elif echo "$result_line" | grep -q 'RESULT: WORSE'; then
        category="WORSE (fast won)"
        worse=$((worse + 1))
    else
        category="UNKNOWN"
        failures=$((failures + 1))
    fi

    fast_times+=("$fast_time")
    bestfee_times+=("$bestfee_time")
    fast_fees+=("$fast_fee")
    bestfee_fees+=("$bestfee_fee")
    optimal_fees+=("$optimal_fee")
    results+=("$category")

    printf "  Fast: %3ss (%sx) | Best-fee: %3ss (%sx) | Optimal: %sx | %s\n" \
        "${fast_time:-?}" "${fast_fee:-?}" "${bestfee_time:-?}" "${bestfee_fee:-?}" "${optimal_fee:-?}" "$category"

    # Print path details
    echo "$output" | grep -A2 -- '--- Path Comparison ---' | tail -3
done

# Summary
echo ""
echo "============================================"
echo "  BENCHMARK SUMMARY (${RUNS} runs)"
echo "============================================"
echo ""
echo "--- Route Quality ---"
printf "  ${GREEN}OPTIMAL:${NC}              %d/%d\n" "$optimal" "$RUNS"
printf "  ${GREEN}BETTER (not optimal):${NC}   %d/%d\n" "$better" "$RUNS"
printf "  ${GREEN}SAME (optimal):${NC}       %d/%d\n" "$same_optimal" "$RUNS"
printf "  ${YELLOW}SAME (sub-optimal):${NC}   %d/%d\n" "$same_suboptimal" "$RUNS"
printf "  ${YELLOW}WORSE (fast won):${NC}     %d/%d\n" "$worse" "$RUNS"
if [ "$failures" -gt 0 ]; then
    printf "  ${RED}UNKNOWN/FAILED:${NC}       %d/%d\n" "$failures" "$RUNS"
fi

# Calculate averages
fastSum=0; bestSum=0; fastCount=0; bestCount=0
fastFeeSum="0"; bestFeeFeeSum="0"; optimalFeeSum="0"
feeCount=0; savingsSum="0"; savingsCount=0
for ((i=0; i<${#results[@]}; i++)); do
    if [ -n "${fast_times[$i]}" ] && [ "${fast_times[$i]}" != "0" ]; then
        fastSum=$((fastSum + fast_times[$i]))
        fastCount=$((fastCount + 1))
    fi
    if [ -n "${bestfee_times[$i]}" ] && [ "${bestfee_times[$i]}" != "0" ]; then
        bestSum=$((bestSum + bestfee_times[$i]))
        bestCount=$((bestCount + 1))
    fi
    if [ -n "${fast_fees[$i]}" ] && [ -n "${bestfee_fees[$i]}" ] && [ -n "${optimal_fees[$i]}" ]; then
        fastFeeSum=$(awk "BEGIN {printf \"%.6f\", $fastFeeSum + ${fast_fees[$i]}}")
        bestFeeFeeSum=$(awk "BEGIN {printf \"%.6f\", $bestFeeFeeSum + ${bestfee_fees[$i]}}")
        optimalFeeSum=$(awk "BEGIN {printf \"%.6f\", $optimalFeeSum + ${optimal_fees[$i]}}")
        feeCount=$((feeCount + 1))
        # Fee savings: fast fee - bestfee fee (positive = bestfee saved money)
        savings=$(awk "BEGIN {printf \"%.6f\", ${fast_fees[$i]} - ${bestfee_fees[$i]}}")
        savingsSum=$(awk "BEGIN {printf \"%.6f\", $savingsSum + $savings}")
        savingsCount=$((savingsCount + 1))
    fi
done

echo ""
echo "--- Timing ---"
if [ "$fastCount" -gt 0 ]; then
    printf "  Fast mode avg:        %ds (n=%d)\n" "$((fastSum / fastCount))" "$fastCount"
fi
if [ "$bestCount" -gt 0 ]; then
    printf "  Best-fee avg:         %ds (n=%d)\n" "$((bestSum / bestCount))" "$bestCount"
    if [ "$fastCount" -gt 0 ]; then
        printf "  Avg overhead:         +%ds\n" "$(( (bestSum / bestCount) - (fastSum / fastCount) ))"
    fi
fi

if [ "$feeCount" -gt 0 ]; then
    avgFastFee=$(awk "BEGIN {printf \"%.4f\", $fastFeeSum / $feeCount}")
    avgBestFeeFee=$(awk "BEGIN {printf \"%.4f\", $bestFeeFeeSum / $feeCount}")
    avgOptimalFee=$(awk "BEGIN {printf \"%.4f\", $optimalFeeSum / $feeCount}")
    echo ""
    echo "--- Fee Multipliers (avg) ---"
    printf "  Fast mode avg fee:    %sx\n" "$avgFastFee"
    printf "  Best-fee avg fee:     %sx\n" "$avgBestFeeFee"
    printf "  Optimal avg fee:      %sx\n" "$avgOptimalFee"
    if [ "$savingsCount" -gt 0 ]; then
        avgSavings=$(awk "BEGIN {printf \"%.4f\", $savingsSum / $savingsCount}")
        # Positive = best-fee saved vs fast; negative = fast was cheaper
        if [ "$(awk "BEGIN {print ($savingsSum > 0) ? 1 : 0}")" -eq 1 ]; then
            printf "  Avg fee savings:      ${GREEN}-%sx vs fast${NC}\n" "$avgSavings"
        elif [ "$(awk "BEGIN {print ($savingsSum < 0) ? 1 : 0}")" -eq 1 ]; then
            printf "  Avg fee savings:      ${YELLOW}+%sx vs fast (fast was cheaper)${NC}\n" "$(awk "BEGIN {printf \"%.4f\", -1 * $savingsSum / $savingsCount}")"
        else
            printf "  Avg fee savings:      0x (identical)\n"
        fi
    fi
fi

echo ""
echo "--- Per-Run Details ---"
printf "  ${CYAN}%-4s  %-8s  %-11s  %-10s  %-10s  %-10s  %s${NC}\n" \
    "Run" "Fast(s)" "BestFee(s)" "FastFee" "BestFee" "Optimal" "Result"
printf "  %-4s  %-8s  %-11s  %-10s  %-10s  %-10s  %s\n" \
    "---" "-------" "----------" "-------" "-------" "-------" "------"
for ((i=0; i<${#results[@]}; i++)); do
    ff="${fast_fees[$i]:-?}"
    bf="${bestfee_fees[$i]:-?}"
    of="${optimal_fees[$i]:-?}"
    [ "$ff" != "?" ] && ff="${ff}x"
    [ "$bf" != "?" ] && bf="${bf}x"
    [ "$of" != "?" ] && of="${of}x"
    printf "  %-4s  %-8s  %-11s  %-10s  %-10s  %-10s  %s\n" \
        "$((i+1))" "${fast_times[$i]:-?}s" "${bestfee_times[$i]:-?}s" "$ff" "$bf" "$of" "${results[$i]}"
done

echo ""
echo "Benchmark completed: $(date '+%Y-%m-%d %H:%M:%S')"
