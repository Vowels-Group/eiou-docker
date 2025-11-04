#!/bin/sh

# Path to builds
declare -A builds=(
    ["http4"]="./tests/buildfiles/http4.sh"
    ["http10"]="./tests/buildfiles/http10.sh"
    ["http13"]="./tests/buildfiles/http13.sh"
    ["http37"]="./tests/buildfiles/http37.sh"
)

# Explanation of build
declare -A descriptionsBuild=(
    ["http4"]="4 line nodes"
    ["http10"]="10 line nodes"
    ["http13"]="13 cluster nodes (small)"
    ["http37"]="37 cluster nodes (big)"
)


# Path to tests
declare -A tests=(
    ["generateTest"]="./tests/testfiles/generateTest.sh"
)

# Explanation of tests
declare -A descriptionsTests=(
    ["generateTest"]="Test if 'generate' command works"
   
)


##############################################################################

# Function to ask for builds
askforbuilds() {
    printf "Builds to choose from:\n"
    build=($(for x in ${!builds[@]}; do echo $x; done | sort))
    for buildname in ${!builds[@]}; do
        printf "\t %-8s : ${descriptionsBuild[${buildname}]} \n" ${buildname}
    done
    build=$(printf '%s' 'Enter build to run: ' >&2; read x && printf '%s' "$x")
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
printf '%s\n' '-----------------------'

##############################################################################

# Function to ask for tests
askfortests() {
    printf "Tests to choose from:\n"
    test=($(for x in ${!tests[@]}; do echo $x; done | sort))
    for testname in ${!tests[@]}; do
        printf "\t %-8s : ${descriptionsTests[${testname}]} \n" ${testname}
    done
    test=$(printf '%s' 'Enter test to run: ' >&2; read x && printf '%s' "$x")
}

# Inquire untill existing tests entered by user
test='a'
x=0
while  ! [[ ${tests[${test}]} ]]; do
    if [[ $x -gt 0 ]]; then
        printf "\tERROR: Test does not exist\\n"
    fi
    askfortests
    x=$(( $x + 1 ))
done

# Run the test
source ${tests[${test}]}


