#!/bin/sh

# Path to builds
declare -A builds=(
    ["http4"]="./tests/build/http4.sh"
    ["http10"]="./tests/build/http10.sh"
    ["http13"]="./tests/build/http13.sh"
    ["http37"]="./tests/build/http37.sh"
)

# Explanation of build
declare -A descriptions=(
    ["http4"]="4 line nodes"
    ["http10"]="10 line nodes"
    ["http13"]="13 cluster nodes (small)"
    ["http37"]="37 cluster nodes (big)"
)

# Function to ask for builds
askforbuilds() {
    printf "Builds to choose from:\n"
    build=($(for x in ${!builds[@]}; do echo $x; done | sort))
    for buildname in ${!builds[@]}; do
        printf "\t %-8s : ${descriptions[${buildname}]} \n" ${buildname}
    done
    build=$(printf '%s' 'Enter build: ' >&2; read x && printf '%s' "$x")
}


# Inquire untill existing build entered with user
build='a'
x=0
while  ! [[ ${builds[${build}]} ]]; do
    if [[ $x -gt 0 ]]; then
        printf "\tERROR: Build does not exist\\n"
    fi
    askforbuilds
    x=$(( $x + 1 ))
done

# Build the build
source ${builds[${build}]}
