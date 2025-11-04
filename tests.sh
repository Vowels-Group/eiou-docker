#!/bin/sh

############################### Base config #################################

source './tests/baseconfig/config.sh'

############################## Load in Files ################################

# Path to builds
declare -A builds=()
buildspath='./tests/buildfiles'
for file in "$buildspath"/*; do
    filename=$(basename -- "$file")
    filename="${filename%.*}"
    builds[$filename]=$file
done

# Path to tests
declare -A tests=()
testsspath='./tests/testfiles'
for file in "$testsspath"/*; do
    filename=$(basename -- "$file")
    filename="${filename%.*}"
    tests[$filename]=$file
done



############################# Welcome message ###############################

printf "\n===== Welcome to the testing shell enviroment =====\n\n"

############################# Build the Build ###############################


# Function to ask for builds
askforbuilds() {
    printf "Choose a build, options are:\n"
    keys=($(for x in ${!builds[@]}; do echo $x; done | sort))
    for buildname in ${keys[@]}; do
        printf "\t- %s\n" ${buildname}
    done
    build=$(printf '\n%s' 'Enter build to run: ' >&2; read x && printf '%s' "$x")
}

# Inquire untill existing build entered with user
build='a'
x=0
while  ! [[ ${builds[${build}]} ]]; do
    if [[ $x -gt 0 ]]; then
        printf "\t${RED}ERROR${NC}: Build does not exist, Please try again.\n\n"
    fi
    askforbuilds
    x=$(( $x + 1 ))
done

# Build the build
source ${builds[${build}]}

printf "${GREEN}${CHECK} Basic build completed succesfully!${NC}\n"

# Check if generateTests exists, if so run first (pre-requisite for other tests)
if [[ ${tests[@]} =~ "generateTest" ]]; then
    printf "Running generateTest (pre-requisite to other tests)\n" 
    source ${tests["generateTest"]}
else
    printf "Please run the test containing the 'generate' command first (pre-requisite to other tests)\n" 
fi

printf '%s\n' '=============================================================='


############################## Test the test ################################

# Function to ask for tests
askfortests() {
    printf "Choose a test, options are:\n"
    keys=($(for x in ${!tests[@]}; do echo $x; done | sort))
    for testname in ${keys[@]}; do
        printf "\t- %s\n" ${testname}
    done
    test=$(printf '%s' 'Enter test to run: ' >&2; read x && printf '%s' "$x")
}

# Inquire untill exited by user, for tests entered by user

nostop=true
while  [[ "$nostop" = true ]]; do
    test='a'
    x=0
    while  ! [[ ${tests[${test}]} ]]; do
        if [[ $x -gt 0 ]]; then
            printf "\t${RED}ERROR${NC}: Test does not exist, Please try again.\n\n"
        fi
        askfortests
        x=$(( $x + 1 ))
    done
    # Run the test
    source ${tests[${test}]}
    printf '%s\n' '=============================================================='
done





#############################################################################
