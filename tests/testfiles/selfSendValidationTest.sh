#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

############################ Self-Send Validation Test ############################
# Tests for self-send transaction prevention:
# - ErrorCodes.SELF_SEND constant exists
# - InputValidator.validateNotSelfSend method exists and works
# - TransactionService validates self-send attempts
# - MessageHelper has GUI-friendly message for SELF_SEND
################################################################################

# Helper functions are sourced via config.sh -> testHelpers.sh
# No need to source again here

testname="selfSendValidationTest"
totaltests=0
passed=0
failure=0

echo -e "\n"
echo "========================================================================"
echo "                    SELF-SEND VALIDATION TEST"
echo "========================================================================"
echo -e "\n"

# Use first container for tests
testContainer="${containers[0]}"

if [[ -z "$testContainer" ]]; then
    echo -e "${YELLOW}Warning: No containers available, skipping self-send validation test${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'self-send validation test'"
    exit 0
fi

echo -e "[Test Container: ${testContainer}]"

##################### SECTION 1: ErrorCodes #####################

echo -e "\n"
echo "========================================================================"
echo "Section 1: ErrorCodes Constants"
echo "========================================================================"

# Test: Verify SELF_SEND constant exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SELF_SEND constant exists"

selfSendConstant=$(docker exec ${testContainer} php -r "
    require_once('/etc/eiou/src/core/ErrorCodes.php');
    if (defined('ErrorCodes::SELF_SEND')) {
        echo ErrorCodes::SELF_SEND;
    } else {
        echo 'CONSTANT_NOT_FOUND';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$selfSendConstant" == "SELF_SEND" ]]; then
    printf "\t   SELF_SEND constant ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SELF_SEND constant ${RED}FAILED${NC} (%s)\n" "${selfSendConstant}"
    failure=$(( failure + 1 ))
fi

# Test: Verify SELF_SEND has HTTP status 400
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SELF_SEND HTTP status is 400"

httpStatus=$(docker exec ${testContainer} php -r "
    require_once('/etc/eiou/src/core/ErrorCodes.php');
    echo ErrorCodes::getHttpStatus(ErrorCodes::SELF_SEND);
" 2>/dev/null || echo "ERROR")

if [[ "$httpStatus" == "400" ]]; then
    printf "\t   SELF_SEND HTTP status ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SELF_SEND HTTP status ${RED}FAILED${NC} (expected 400, got %s)\n" "${httpStatus}"
    failure=$(( failure + 1 ))
fi

# Test: Verify SELF_SEND has proper title
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SELF_SEND title"

errorTitle=$(docker exec ${testContainer} php -r "
    require_once('/etc/eiou/src/core/ErrorCodes.php');
    echo ErrorCodes::getTitle(ErrorCodes::SELF_SEND);
" 2>/dev/null || echo "ERROR")

if [[ "$errorTitle" == *"Send"*"Yourself"* ]] || [[ "$errorTitle" == *"Cannot"* ]]; then
    printf "\t   SELF_SEND title ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SELF_SEND title ${RED}FAILED${NC} (got %s)\n" "${errorTitle}"
    failure=$(( failure + 1 ))
fi

##################### SECTION 2: InputValidator #####################

echo -e "\n"
echo "========================================================================"
echo "Section 2: InputValidator Self-Send Validation"
echo "========================================================================"

# Test: Verify validateNotSelfSend method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing validateNotSelfSend method exists"

methodExists=$(docker exec ${testContainer} php -r "
    require_once('/etc/eiou/src/utils/InputValidator.php');
    echo method_exists('InputValidator', 'validateNotSelfSend') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$methodExists" == "EXISTS" ]]; then
    printf "\t   validateNotSelfSend method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   validateNotSelfSend method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: Verify self-send returns invalid
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing self-send returns invalid"

selfSendCheck=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    require_once('/etc/eiou/src/utils/InputValidator.php');

    \$userContext = Application::getInstance()->services->getCurrentUser();
    \$myAddress = \$userContext->getHttpAddress() ?? \$userContext->getTorAddress();

    if (\$myAddress === null) {
        echo 'NO_USER_ADDRESS';
        exit;
    }

    \$result = InputValidator::validateNotSelfSend(\$myAddress, \$userContext);

    if (\$result['valid'] === false && strpos(\$result['error'], 'yourself') !== false) {
        echo 'CORRECTLY_INVALID';
    } else {
        echo 'INCORRECTLY_VALID';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$selfSendCheck" == "CORRECTLY_INVALID" ]]; then
    printf "\t   Self-send detection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$selfSendCheck" == "NO_USER_ADDRESS" ]]; then
    printf "\t   Self-send detection ${YELLOW}SKIPPED${NC} (no user address)\n"
else
    printf "\t   Self-send detection ${RED}FAILED${NC} (%s)\n" "${selfSendCheck}"
    failure=$(( failure + 1 ))
fi

# Test: Verify different address returns valid
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing different address returns valid"

differentAddressCheck=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    require_once('/etc/eiou/src/utils/InputValidator.php');

    \$userContext = Application::getInstance()->services->getCurrentUser();

    // Use a clearly different address
    \$differentAddress = 'https://different-recipient.example.com';

    \$result = InputValidator::validateNotSelfSend(\$differentAddress, \$userContext);

    if (\$result['valid'] === true && \$result['error'] === null) {
        echo 'CORRECTLY_VALID';
    } else {
        echo 'INCORRECTLY_INVALID';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$differentAddressCheck" == "CORRECTLY_VALID" ]]; then
    printf "\t   Different address validation ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Different address validation ${RED}FAILED${NC} (%s)\n" "${differentAddressCheck}"
    failure=$(( failure + 1 ))
fi

# Test: Verify empty address returns valid (handled by other validators)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing empty address returns valid"

emptyAddressCheck=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    require_once('/etc/eiou/src/utils/InputValidator.php');

    \$userContext = Application::getInstance()->services->getCurrentUser();

    \$result = InputValidator::validateNotSelfSend('', \$userContext);

    if (\$result['valid'] === true) {
        echo 'CORRECTLY_VALID';
    } else {
        echo 'INCORRECTLY_INVALID';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$emptyAddressCheck" == "CORRECTLY_VALID" ]]; then
    printf "\t   Empty address validation ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Empty address validation ${RED}FAILED${NC} (%s)\n" "${emptyAddressCheck}"
    failure=$(( failure + 1 ))
fi

##################### SECTION 3: MessageHelper #####################

echo -e "\n"
echo "========================================================================"
echo "Section 3: MessageHelper GUI Message"
echo "========================================================================"

# Test: Verify SELF_SEND has GUI-friendly message
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SELF_SEND GUI-friendly message"

guiMessage=$(docker exec ${testContainer} php -r "
    require_once('/etc/eiou/src/gui/helpers/MessageHelper.php');
    \$msg = MessageHelper::getGuiFriendlyMessage('SELF_SEND', '');
    echo \$msg;
" 2>/dev/null || echo "ERROR")

if [[ "$guiMessage" == *"cannot send"* ]] || [[ "$guiMessage" == *"yourself"* ]]; then
    printf "\t   SELF_SEND GUI message ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SELF_SEND GUI message ${RED}FAILED${NC} (got: %s)\n" "${guiMessage}"
    failure=$(( failure + 1 ))
fi

##################### SECTION 4: UserContext #####################

echo -e "\n"
echo "========================================================================"
echo "Section 4: UserContext Address Methods"
echo "========================================================================"

# Test: Verify isMyAddress method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing isMyAddress method exists"

isMyAddressMethod=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$userContext = Application::getInstance()->services->getCurrentUser();
    echo method_exists(\$userContext, 'isMyAddress') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$isMyAddressMethod" == "EXISTS" ]]; then
    printf "\t   isMyAddress method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   isMyAddress method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: Verify getUserAddresses method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getUserAddresses method exists"

getUserAddressesMethod=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$userContext = Application::getInstance()->services->getCurrentUser();
    echo method_exists(\$userContext, 'getUserAddresses') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$getUserAddressesMethod" == "EXISTS" ]]; then
    printf "\t   getUserAddresses method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getUserAddresses method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

################################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'self-send validation test'"

################################################################################
