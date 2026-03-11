#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

set -e # Stop script on failure

# Check if network exists and create it if necessary
if docker network inspect "${network}" >/dev/null 2>&1; then
    echo "Network already exists."
else
    echo "Creating network..."
    docker network create --driver bridge "${network}"
fi

declare -A containerAddresses

declare -a containers=(
    "C0"
    "N1" "N2" "N3" "N4" "N5" "N6" "N7"
    "N3b" "N5b" "N6b" "N8"
    "E1" "E2" "E3" "E4" "E5" "E6" "E7" "E8"
    "E4b" "E6b" "E7b"
    "S1" "S2" "S3" "S4" "S5" "S6" "S7" "S8"
    "S4b" "S6b" "S7b"
    "W1" "W2" "W3" "W4" "W5" "W6" "W7" "W8"
    "W3b" "W5b" "W7b"
    "MH" "MH2"
    "LN1" "LN2" "LN3"
    "LS1" "LS2"
    "ISO"
)

# Randomized fees (0.1-0.9) per edge, same both directions
# This ensures best-fee routing is tested against varying fee structures
readonly defaultCredit=1000

# Generate a random fee: 0.1 to 0.9 (single decimal digit)
random_fee() { echo "0.$(( RANDOM % 9 + 1 ))"; }

# --- North arm fees ---
fee_C0_N1=$(random_fee)
fee_N1_N2=$(random_fee)
fee_N2_N3=$(random_fee)
fee_N3_N4=$(random_fee)
fee_N4_N5=$(random_fee)
fee_N5_N6=$(random_fee)
fee_N6_N7=$(random_fee)
fee_N3_N3b=$(random_fee)
fee_N5_N5b=$(random_fee)
fee_N7_N8=$(random_fee)
fee_N6_N6b=$(random_fee)
fee_N6b_N7=$(random_fee)

# --- East arm fees ---
fee_C0_E1=$(random_fee)
fee_E1_E2=$(random_fee)
fee_E2_E3=$(random_fee)
fee_E3_E4=$(random_fee)
fee_E4_E5=$(random_fee)
fee_E5_E6=$(random_fee)
fee_E6_E7=$(random_fee)
fee_E7_E8=$(random_fee)
fee_E4_E4b=$(random_fee)
fee_E6_E6b=$(random_fee)
fee_E6b_E7=$(random_fee)
fee_E7_E7b=$(random_fee)

# --- South arm fees ---
fee_C0_S1=$(random_fee)
fee_S1_S2=$(random_fee)
fee_S2_S3=$(random_fee)
fee_S3_S4=$(random_fee)
fee_S4_S5=$(random_fee)
fee_S5_S6=$(random_fee)
fee_S6_S7=$(random_fee)
fee_S7_S8=$(random_fee)
fee_S4_S4b=$(random_fee)
fee_S4b_S5=$(random_fee)
fee_S6_S6b=$(random_fee)
fee_S7_S7b=$(random_fee)

# --- West arm fees ---
fee_C0_W1=$(random_fee)
fee_W1_W2=$(random_fee)
fee_W2_W3=$(random_fee)
fee_W3_W4=$(random_fee)
fee_W4_W5=$(random_fee)
fee_W5_W6=$(random_fee)
fee_W6_W7=$(random_fee)
fee_W7_W8=$(random_fee)
fee_W3_W3b=$(random_fee)
fee_W5_W5b=$(random_fee)
fee_W5b_W6=$(random_fee)
fee_W7_W7b=$(random_fee)

# --- Skip connection fees (within-arm shortcuts) ---
# Overlapping skips create triangular cycles: e.g. E1-E2-E3-E1, E2-E3-E4-E2
fee_N1_N3=$(random_fee)
fee_N2_N4=$(random_fee)
fee_N5_N7=$(random_fee)
fee_E1_E3=$(random_fee)
fee_E2_E4=$(random_fee)
fee_E3_E5=$(random_fee)
fee_S1_S3=$(random_fee)
fee_S2_S4=$(random_fee)
fee_S3_S5=$(random_fee)
fee_W1_W3=$(random_fee)
fee_W2_W4=$(random_fee)
fee_W3_W5=$(random_fee)

# --- Mesh hub fees ---
fee_N3_MH=$(random_fee)
fee_E3_MH=$(random_fee)
fee_S3_MH=$(random_fee)
fee_W3_MH=$(random_fee)
fee_E5_MH2=$(random_fee)
fee_S5_MH2=$(random_fee)

# --- Linear branch fees ---
fee_N4_LN1=$(random_fee)
fee_LN1_LN2=$(random_fee)
fee_LN2_LN3=$(random_fee)
fee_S6_LS1=$(random_fee)
fee_LS1_LS2=$(random_fee)

# --- Cross-arm link fees ---
fee_N6b_E6b=$(random_fee)
fee_S7b_W7b=$(random_fee)
fee_S4_W4=$(random_fee)

# Define contacts, direction ->
# example: [A0,A1] defines A0 as a contact of A1
#          must be accepted in reverse that is to say:
#          [A0,A1] needs to be followed by [A1,A0]
declare -A containersLinks=(
    # --- North arm spine ---
    [C0,N1]="$fee_C0_N1 $defaultCredit USD"
    [N1,C0]="$fee_C0_N1 $defaultCredit USD"
    [N1,N2]="$fee_N1_N2 $defaultCredit USD"
    [N2,N1]="$fee_N1_N2 $defaultCredit USD"
    [N2,N3]="$fee_N2_N3 $defaultCredit USD"
    [N3,N2]="$fee_N2_N3 $defaultCredit USD"
    [N3,N4]="$fee_N3_N4 $defaultCredit USD"
    [N4,N3]="$fee_N3_N4 $defaultCredit USD"
    [N4,N5]="$fee_N4_N5 $defaultCredit USD"
    [N5,N4]="$fee_N4_N5 $defaultCredit USD"
    [N5,N6]="$fee_N5_N6 $defaultCredit USD"
    [N6,N5]="$fee_N5_N6 $defaultCredit USD"
    [N6,N7]="$fee_N6_N7 $defaultCredit USD"
    [N7,N6]="$fee_N6_N7 $defaultCredit USD"
    # North dead ends
    [N3,N3b]="$fee_N3_N3b $defaultCredit USD"
    [N3b,N3]="$fee_N3_N3b $defaultCredit USD"
    [N5,N5b]="$fee_N5_N5b $defaultCredit USD"
    [N5b,N5]="$fee_N5_N5b $defaultCredit USD"
    [N7,N8]="$fee_N7_N8 $defaultCredit USD"
    [N8,N7]="$fee_N7_N8 $defaultCredit USD"
    # North collision: N6->N6b->N7 (alt to N6->N7)
    [N6,N6b]="$fee_N6_N6b $defaultCredit USD"
    [N6b,N6]="$fee_N6_N6b $defaultCredit USD"
    [N6b,N7]="$fee_N6b_N7 $defaultCredit USD"
    [N7,N6b]="$fee_N6b_N7 $defaultCredit USD"

    # --- East arm spine ---
    [C0,E1]="$fee_C0_E1 $defaultCredit USD"
    [E1,C0]="$fee_C0_E1 $defaultCredit USD"
    [E1,E2]="$fee_E1_E2 $defaultCredit USD"
    [E2,E1]="$fee_E1_E2 $defaultCredit USD"
    [E2,E3]="$fee_E2_E3 $defaultCredit USD"
    [E3,E2]="$fee_E2_E3 $defaultCredit USD"
    [E3,E4]="$fee_E3_E4 $defaultCredit USD"
    [E4,E3]="$fee_E3_E4 $defaultCredit USD"
    [E4,E5]="$fee_E4_E5 $defaultCredit USD"
    [E5,E4]="$fee_E4_E5 $defaultCredit USD"
    [E5,E6]="$fee_E5_E6 $defaultCredit USD"
    [E6,E5]="$fee_E5_E6 $defaultCredit USD"
    [E6,E7]="$fee_E6_E7 $defaultCredit USD"
    [E7,E6]="$fee_E6_E7 $defaultCredit USD"
    # East dead ends
    [E7,E8]="$fee_E7_E8 $defaultCredit USD"
    [E8,E7]="$fee_E7_E8 $defaultCredit USD"
    [E4,E4b]="$fee_E4_E4b $defaultCredit USD"
    [E4b,E4]="$fee_E4_E4b $defaultCredit USD"
    [E7,E7b]="$fee_E7_E7b $defaultCredit USD"
    [E7b,E7]="$fee_E7_E7b $defaultCredit USD"
    # East collision: E6->E6b->E7 (alt to E6->E7)
    [E6,E6b]="$fee_E6_E6b $defaultCredit USD"
    [E6b,E6]="$fee_E6_E6b $defaultCredit USD"
    [E6b,E7]="$fee_E6b_E7 $defaultCredit USD"
    [E7,E6b]="$fee_E6b_E7 $defaultCredit USD"

    # --- South arm spine ---
    [C0,S1]="$fee_C0_S1 $defaultCredit USD"
    [S1,C0]="$fee_C0_S1 $defaultCredit USD"
    [S1,S2]="$fee_S1_S2 $defaultCredit USD"
    [S2,S1]="$fee_S1_S2 $defaultCredit USD"
    [S2,S3]="$fee_S2_S3 $defaultCredit USD"
    [S3,S2]="$fee_S2_S3 $defaultCredit USD"
    [S3,S4]="$fee_S3_S4 $defaultCredit USD"
    [S4,S3]="$fee_S3_S4 $defaultCredit USD"
    [S4,S5]="$fee_S4_S5 $defaultCredit USD"
    [S5,S4]="$fee_S4_S5 $defaultCredit USD"
    [S5,S6]="$fee_S5_S6 $defaultCredit USD"
    [S6,S5]="$fee_S5_S6 $defaultCredit USD"
    [S6,S7]="$fee_S6_S7 $defaultCredit USD"
    [S7,S6]="$fee_S6_S7 $defaultCredit USD"
    # South dead ends
    [S7,S8]="$fee_S7_S8 $defaultCredit USD"
    [S8,S7]="$fee_S7_S8 $defaultCredit USD"
    [S6,S6b]="$fee_S6_S6b $defaultCredit USD"
    [S6b,S6]="$fee_S6_S6b $defaultCredit USD"
    # South collision: S4->S4b->S5 (alt to S4->S5)
    [S4,S4b]="$fee_S4_S4b $defaultCredit USD"
    [S4b,S4]="$fee_S4_S4b $defaultCredit USD"
    [S4b,S5]="$fee_S4b_S5 $defaultCredit USD"
    [S5,S4b]="$fee_S4b_S5 $defaultCredit USD"
    # South cross-arm branch
    [S7,S7b]="$fee_S7_S7b $defaultCredit USD"
    [S7b,S7]="$fee_S7_S7b $defaultCredit USD"

    # --- West arm spine ---
    [C0,W1]="$fee_C0_W1 $defaultCredit USD"
    [W1,C0]="$fee_C0_W1 $defaultCredit USD"
    [W1,W2]="$fee_W1_W2 $defaultCredit USD"
    [W2,W1]="$fee_W1_W2 $defaultCredit USD"
    [W2,W3]="$fee_W2_W3 $defaultCredit USD"
    [W3,W2]="$fee_W2_W3 $defaultCredit USD"
    [W3,W4]="$fee_W3_W4 $defaultCredit USD"
    [W4,W3]="$fee_W3_W4 $defaultCredit USD"
    [W4,W5]="$fee_W4_W5 $defaultCredit USD"
    [W5,W4]="$fee_W4_W5 $defaultCredit USD"
    [W5,W6]="$fee_W5_W6 $defaultCredit USD"
    [W6,W5]="$fee_W5_W6 $defaultCredit USD"
    [W6,W7]="$fee_W6_W7 $defaultCredit USD"
    [W7,W6]="$fee_W6_W7 $defaultCredit USD"
    # West dead ends
    [W7,W8]="$fee_W7_W8 $defaultCredit USD"
    [W8,W7]="$fee_W7_W8 $defaultCredit USD"
    [W3,W3b]="$fee_W3_W3b $defaultCredit USD"
    [W3b,W3]="$fee_W3_W3b $defaultCredit USD"
    # West collision: W5->W5b->W6 (alt to W5->W6)
    [W5,W5b]="$fee_W5_W5b $defaultCredit USD"
    [W5b,W5]="$fee_W5_W5b $defaultCredit USD"
    [W5b,W6]="$fee_W5b_W6 $defaultCredit USD"
    [W6,W5b]="$fee_W5b_W6 $defaultCredit USD"
    # West cross-arm branch
    [W7,W7b]="$fee_W7_W7b $defaultCredit USD"
    [W7b,W7]="$fee_W7_W7b $defaultCredit USD"

    # --- Cross-arm links ---
    # North-East at depth 6
    [N6b,E6b]="$fee_N6b_E6b $defaultCredit USD"
    [E6b,N6b]="$fee_N6b_E6b $defaultCredit USD"
    # South-West at depth 7
    [S7b,W7b]="$fee_S7b_W7b $defaultCredit USD"
    [W7b,S7b]="$fee_S7b_W7b $defaultCredit USD"
    # South-West at depth 4
    [S4,W4]="$fee_S4_W4 $defaultCredit USD"
    [W4,S4]="$fee_S4_W4 $defaultCredit USD"

    # --- Skip connections (within-arm shortcuts, overlapping = triangular cycles) ---
    # North: N1-N2-N3-N1, N2-N3-N4-N2, N5-N6-N7-N5
    [N1,N3]="$fee_N1_N3 $defaultCredit USD"
    [N3,N1]="$fee_N1_N3 $defaultCredit USD"
    [N2,N4]="$fee_N2_N4 $defaultCredit USD"
    [N4,N2]="$fee_N2_N4 $defaultCredit USD"
    [N5,N7]="$fee_N5_N7 $defaultCredit USD"
    [N7,N5]="$fee_N5_N7 $defaultCredit USD"
    # East: E1-E2-E3-E1, E2-E3-E4-E2, E3-E4-E5-E3
    [E1,E3]="$fee_E1_E3 $defaultCredit USD"
    [E3,E1]="$fee_E1_E3 $defaultCredit USD"
    [E2,E4]="$fee_E2_E4 $defaultCredit USD"
    [E4,E2]="$fee_E2_E4 $defaultCredit USD"
    [E3,E5]="$fee_E3_E5 $defaultCredit USD"
    [E5,E3]="$fee_E3_E5 $defaultCredit USD"
    # South: S1-S2-S3-S1, S2-S3-S4-S2, S3-S4-S5-S3
    [S1,S3]="$fee_S1_S3 $defaultCredit USD"
    [S3,S1]="$fee_S1_S3 $defaultCredit USD"
    [S2,S4]="$fee_S2_S4 $defaultCredit USD"
    [S4,S2]="$fee_S2_S4 $defaultCredit USD"
    [S3,S5]="$fee_S3_S5 $defaultCredit USD"
    [S5,S3]="$fee_S3_S5 $defaultCredit USD"
    # West: W1-W2-W3-W1, W2-W3-W4-W2, W3-W4-W5-W3
    [W1,W3]="$fee_W1_W3 $defaultCredit USD"
    [W3,W1]="$fee_W1_W3 $defaultCredit USD"
    [W2,W4]="$fee_W2_W4 $defaultCredit USD"
    [W4,W2]="$fee_W2_W4 $defaultCredit USD"
    [W3,W5]="$fee_W3_W5 $defaultCredit USD"
    [W5,W3]="$fee_W3_W5 $defaultCredit USD"

    # --- Mesh hub at depth 3 (MH connects all 4 arms) ---
    [N3,MH]="$fee_N3_MH $defaultCredit USD"
    [MH,N3]="$fee_N3_MH $defaultCredit USD"
    [E3,MH]="$fee_E3_MH $defaultCredit USD"
    [MH,E3]="$fee_E3_MH $defaultCredit USD"
    [S3,MH]="$fee_S3_MH $defaultCredit USD"
    [MH,S3]="$fee_S3_MH $defaultCredit USD"
    [W3,MH]="$fee_W3_MH $defaultCredit USD"
    [MH,W3]="$fee_W3_MH $defaultCredit USD"

    # --- Mesh hub at depth 5 (MH2 bridges East-South) ---
    [E5,MH2]="$fee_E5_MH2 $defaultCredit USD"
    [MH2,E5]="$fee_E5_MH2 $defaultCredit USD"
    [S5,MH2]="$fee_S5_MH2 $defaultCredit USD"
    [MH2,S5]="$fee_S5_MH2 $defaultCredit USD"

    # --- Linear branch off N4 ---
    [N4,LN1]="$fee_N4_LN1 $defaultCredit USD"
    [LN1,N4]="$fee_N4_LN1 $defaultCredit USD"
    [LN1,LN2]="$fee_LN1_LN2 $defaultCredit USD"
    [LN2,LN1]="$fee_LN1_LN2 $defaultCredit USD"
    [LN2,LN3]="$fee_LN2_LN3 $defaultCredit USD"
    [LN3,LN2]="$fee_LN2_LN3 $defaultCredit USD"

    # --- Linear branch off S6 ---
    [S6,LS1]="$fee_S6_LS1 $defaultCredit USD"
    [LS1,S6]="$fee_S6_LS1 $defaultCredit USD"
    [LS1,LS2]="$fee_LS1_LS2 $defaultCredit USD"
    [LS2,LS1]="$fee_LS1_LS2 $defaultCredit USD"
)

declare -A expectedContacts=(
    [C0]=4    # Connected to N1, E1, S1, W1
    # --- North arm ---
    [N1]=3    # Connected to C0, N2, N3 (skip)
    [N2]=3    # Connected to N1, N3, N4 (skip)
    [N3]=5    # Connected to N2, N4, N3b, MH, N1 (skip)
    [N4]=4    # Connected to N3, N5, N2 (skip), LN1
    [N5]=4    # Connected to N4, N6, N5b, N7 (skip)
    [N6]=3    # Connected to N5, N7, N6b
    [N7]=4    # Connected to N6, N8, N6b, N5 (skip)
    [N3b]=1   # Dead end: N3
    [N5b]=1   # Dead end: N5
    [N6b]=3   # Connected to N6, N7, E6b (cross-arm)
    [N8]=1    # Dead end: N7
    # --- East arm ---
    [E1]=3    # Connected to C0, E2, E3 (skip)
    [E2]=3    # Connected to E1, E3, E4 (skip)
    [E3]=5    # Connected to E2, E4, MH, E5 (skip), E1 (skip)
    [E4]=4    # Connected to E3, E5, E4b, E2 (skip)
    [E5]=4    # Connected to E4, E6, E3 (skip), MH2
    [E6]=3    # Connected to E5, E7, E6b
    [E7]=4    # Connected to E6, E8, E6b, E7b
    [E8]=1    # Dead end: E7
    [E4b]=1   # Dead end: E4
    [E6b]=3   # Connected to E6, E7, N6b (cross-arm)
    [E7b]=1   # Dead end: E7
    # --- South arm ---
    [S1]=3    # Connected to C0, S2, S3 (skip)
    [S2]=3    # Connected to S1, S3, S4 (skip)
    [S3]=5    # Connected to S2, S4, MH, S5 (skip), S1 (skip)
    [S4]=5    # Connected to S3, S5, S4b, W4 (cross-arm), S2 (skip)
    [S5]=5    # Connected to S4, S6, S4b, S3 (skip), MH2
    [S6]=4    # Connected to S5, S7, S6b, LS1
    [S7]=3    # Connected to S6, S8, S7b
    [S8]=1    # Dead end: S7
    [S4b]=2   # Collision node: S4, S5
    [S6b]=1   # Dead end: S6
    [S7b]=2   # Cross-arm node: S7, W7b
    # --- West arm ---
    [W1]=3    # Connected to C0, W2, W3 (skip)
    [W2]=3    # Connected to W1, W3, W4 (skip)
    [W3]=6    # Connected to W2, W4, W3b, MH, W5 (skip), W1 (skip)
    [W4]=4    # Connected to W3, W5, S4 (cross-arm), W2 (skip)
    [W5]=4    # Connected to W4, W6, W5b, W3 (skip)
    [W6]=3    # Connected to W5, W7, W5b
    [W7]=3    # Connected to W6, W8, W7b
    [W8]=1    # Dead end: W7
    [W3b]=1   # Dead end: W3
    [W5b]=2   # Collision node: W5, W6
    [W7b]=2   # Cross-arm node: W7, S7b
    # --- Mesh hubs ---
    [MH]=4    # Connected to N3, E3, S3, W3
    [MH2]=2   # Connected to E5, S5
    # --- Linear branch off N4 ---
    [LN1]=2   # Connected to N4, LN2
    [LN2]=2   # Connected to LN1, LN3
    [LN3]=1   # Dead end: LN2
    # --- Linear branch off S6 ---
    [LS1]=2   # Connected to S6, LS2
    [LS2]=1   # Dead end: LS1
    # --- Isolated ---
    [ISO]=0   # Isolated node (cascade cancel target)
)

# 53-node cluster (collisionscluster) topology with randomized fees,
# dead ends, collision paths, cross-arm links, mesh hubs, skip connections,
# and linear branches:
##
##                                      N8
##                                      |
##                                N5b--N7------+ N5<->N7
##                                      |\     |
##                                    N6-N6b   |
##                                      |  :   |
##                                     N5  :   |
##                                      |  : N6b<->E6b
##                       LN3--LN2--LN1-N4  :  N2<->N4
##                                      |  :   |
##                                     N3--:--N3b
##                                   /  |  :   | N1<->N3
##                         N1<->N3  /  N2--:---+
##                                 /    |  :
##                                N1    :  :
##                                 \    :  :
##       W1<->W3                    \   :  :                 E1<->E3
##          \    W2<->W4             \  :  :          E2<->E4    /
##           \      \                 \ :  :             /      /
## W8--W7--W6--W5--W4--W3--W2--W1----C0:---:--E1--E2--E3--E4--E5--E6--E7--E8
##      |    |   /      /  |         : : MH:                  |    |   \      \
##     W7b  W5b /      /  W3b       :W3:N3 :               E4b   MH2   \      \
##      :     W3<->W5 /             :  :S3 :E3              :  E3<->E5  E6b E7b
##      :            /              :  :   :                :
##      :           S1              :  :   :                :
##      :         /   \             :  :   :                :
##      :  S1<->S3     \            :  :   :                :
##      :       S2    S2<->S4       :  :   :                :
##      :        \      \          S4<->W4 :                :
##      :         S3     \                 :                :
##      :          \      \                :                :
##      :     S4b--S4------+ S3<->S5       :                :
##      :            \      |              :                :
##      :             S5---+        S5<->MH2                :
##      :              |                                    :
##      :     S6b--S6--LS1--LS2                             :
##      :              |                                    :
##      :         S8--S7                                    :
##      :              |                                    :
##      :............S7b                         N6b........:
##        S7b<->W7b                                N6b<->E6b
##
## C0 at center. N=up, S=down, E=right, W=left.
## Spine edges: solid lines (|, --, /, \).
## Skip connections: labeled X<->Y (within-arm shortcuts).
## Dotted lines (:, .): cross-arm and mesh hub links (drawn below/beside arms).
##
## Mesh hubs:
##   MH  <-> N3, E3, S3, W3  (4-way hub linking all arms at depth 3)
##   MH2 <-> E5, S5           (2-way hub linking East-South at depth 5)
## Collision bypasses: N6-N6b-N7, E6-E6b-E7, S4-S4b-S5, W5-W5b-W6
## Cross-arm links:
##   S4  <-> W4   (South-West at depth 4)
##   N6b <-> E6b  (North-East at depth 6)
##   S7b <-> W7b  (South-West at depth 7)
## Linear branches: N4->LN1->LN2->LN3, S6->LS1->LS2
## ISO is isolated (no connections) -- cascade cancel target.
## Fees are randomized (0.1-0.9) per run; best-fee route varies each time.
##
## Triangular cycles (16):
##   N1-N2-N3, N2-N3-N4, N5-N6-N7, N6-N6b-N7
##   E1-E2-E3, E2-E3-E4, E3-E4-E5, E6-E6b-E7
##   S1-S2-S3, S2-S3-S4, S3-S4-S5, S4-S4b-S5
##   W1-W2-W3, W2-W3-W4, W3-W4-W5, W5-W5b-W6
##
## Minimum hop distances from C0:
##   1 hop:  N1, E1, S1, W1
##   2 hops: N2, N3, E2, E3, S2, S3, W2, W3
##   3 hops: N3b, N4, E4, E5, MH, S4, S5, W3b, W4, W5
##   4 hops: N5, E4b, E6, LN1, MH2, S4b, S6, W5b, W6
##   5 hops: N5b, N6, N7, E6b, E7, LN2, LS1, S6b, S7, W7
##   6 hops: N6b, N8, E7b, E8, LN3, LS2, S7b, S8, W7b, W8
## 53 nodes, 74 edges, 16 triangular cycles, max depth 7 hops from center.
declare -A routingTests=(
    [C0,N8]="N1,N2,N3,N4,N5,N6,N7"
    [C0,E8]="E1,E2,E3,E4,E5,E6,E7"
    [N8,E8]="N7,N6,N6b,E6b,E7"
    [S8,W8]="S7,S7b,W7b,W7"
    [N3b,W3b]="N3,MH,W3"
    [LN3,LS2]="LN2,LN1,N4,N3,MH,S3,S4,S5,S6,LS1"
)

echo "Removing existing containers and associated volumes (if any)..."
for container in "${containers[@]}"; do
    remove_container_if_exists $container
done

echo "Building base image..."
cd ../
docker build -f eiou.dockerfile -t eiou/eiou .

echo -e "\nCreating containers..."
# Pass env flags from parent shell (defaults if not set)
CONTACT_STATUS_FLAG="${EIOU_CONTACT_STATUS_ENABLED:-true}"
TOR_FORCE_FAST_FLAG="${EIOU_TOR_FORCE_FAST:-true}"
DEFAULT_TRANSPORT_FLAG="${EIOU_DEFAULT_TRANSPORT_MODE:-http}"
HOP_BUDGET_RANDOMIZED_FLAG="${EIOU_HOP_BUDGET_RANDOMIZED:-false}"
for container in "${containers[@]}"; do
    docker run -d --network=eiou-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-config:/etc/eiou/config" -v "${container}-backups:/var/lib/eiou/backups" -v "${container}-letsencrypt:/etc/letsencrypt" -e QUICKSTART=$container -e EIOU_CONTACT_STATUS_ENABLED=$CONTACT_STATUS_FLAG -e EIOU_TOR_FORCE_FAST=$TOR_FORCE_FAST_FLAG -e EIOU_DEFAULT_TRANSPORT_MODE=$DEFAULT_TRANSPORT_FLAG -e EIOU_HOP_BUDGET_RANDOMIZED=$HOP_BUDGET_RANDOMIZED_FLAG eiou/eiou
done
