#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Service Exception Test ############################
# Tests the ServiceException hierarchy and error handling
#
# Verifies:
# - Exception classes exist and are properly structured
# - CLI commands with invalid input throw ValidationServiceException
# - CLI exit codes are correct (1 for validation/fatal, 0 for recoverable)
# - Exception context (errorCode, httpStatus) is preserved
# - API endpoints return proper error responses from ServiceExceptions
#
# Prerequisites:
# - Containers must be running
# - Contacts should be established for some tests
################################################################################

echo -e "\nRunning Service Exception Tests..."

testname="serviceExceptionTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

echo -e "\t   Test container: ${testContainer}"

############################ SECTION 1: EXCEPTION CLASS STRUCTURE ############################

echo -e "\n[Section 1: Exception Class Structure]"

# Test 1.1: ServiceException base class exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ServiceException base class exists"

classExists=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    if (class_exists('\Eiou\Exceptions\ServiceException')) {
        \$reflection = new ReflectionClass('\Eiou\Exceptions\ServiceException');
        if (\$reflection->isAbstract()) {
            echo 'SUCCESS:abstract';
        } else {
            echo 'FAILED:not_abstract';
        }
    } else {
        echo 'FAILED:not_found';
    }
" 2>&1 | tail -1)

if [[ "$classExists" == "SUCCESS:abstract" ]]; then
    printf "\t   ServiceException base class exists and is abstract ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ServiceException base class ${RED}FAILED${NC} (%s)\n" "${classExists}"
    failure=$(( failure + 1 ))
fi

# Test 1.2: FatalServiceException exists and extends ServiceException
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing FatalServiceException class"

fatalExists=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    if (class_exists('\Eiou\Exceptions\FatalServiceException')) {
        \$reflection = new ReflectionClass('\Eiou\Exceptions\FatalServiceException');
        if (\$reflection->getParentClass()->getName() === 'Eiou\Exceptions\ServiceException') {
            echo 'SUCCESS';
        } else {
            echo 'FAILED:wrong_parent';
        }
    } else {
        echo 'FAILED:not_found';
    }
" 2>&1 | tail -1)

if [[ "$fatalExists" == "SUCCESS" ]]; then
    printf "\t   FatalServiceException exists and extends ServiceException ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   FatalServiceException ${RED}FAILED${NC} (%s)\n" "${fatalExists}"
    failure=$(( failure + 1 ))
fi

# Test 1.3: ValidationServiceException exists and extends ServiceException
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ValidationServiceException class"

validationExists=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    if (class_exists('\Eiou\Exceptions\ValidationServiceException')) {
        \$reflection = new ReflectionClass('\Eiou\Exceptions\ValidationServiceException');
        if (\$reflection->getParentClass()->getName() === 'Eiou\Exceptions\ServiceException') {
            echo 'SUCCESS';
        } else {
            echo 'FAILED:wrong_parent';
        }
    } else {
        echo 'FAILED:not_found';
    }
" 2>&1 | tail -1)

if [[ "$validationExists" == "SUCCESS" ]]; then
    printf "\t   ValidationServiceException exists and extends ServiceException ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ValidationServiceException ${RED}FAILED${NC} (%s)\n" "${validationExists}"
    failure=$(( failure + 1 ))
fi

# Test 1.4: RecoverableServiceException exists and extends ServiceException
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing RecoverableServiceException class"

recoverableExists=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    if (class_exists('\Eiou\Exceptions\RecoverableServiceException')) {
        \$reflection = new ReflectionClass('\Eiou\Exceptions\RecoverableServiceException');
        if (\$reflection->getParentClass()->getName() === 'Eiou\Exceptions\ServiceException') {
            echo 'SUCCESS';
        } else {
            echo 'FAILED:wrong_parent';
        }
    } else {
        echo 'FAILED:not_found';
    }
" 2>&1 | tail -1)

if [[ "$recoverableExists" == "SUCCESS" ]]; then
    printf "\t   RecoverableServiceException exists and extends ServiceException ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   RecoverableServiceException ${RED}FAILED${NC} (%s)\n" "${recoverableExists}"
    failure=$(( failure + 1 ))
fi

############################ SECTION 2: EXCEPTION PROPERTIES ############################

echo -e "\n[Section 2: Exception Properties and Methods]"

# Test 2.1: FatalServiceException has correct exit code (1)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing FatalServiceException exit code"

fatalExitCode=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    use Eiou\Exceptions\FatalServiceException;
    use Eiou\Core\ErrorCodes;

    \$exception = new FatalServiceException('Test error', ErrorCodes::INTERNAL_ERROR);
    echo \$exception->getExitCode();
" 2>&1 | tail -1)

if [[ "$fatalExitCode" == "1" ]]; then
    printf "\t   FatalServiceException exit code is 1 ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   FatalServiceException exit code ${RED}FAILED${NC} (got: %s, expected: 1)\n" "${fatalExitCode}"
    failure=$(( failure + 1 ))
fi

# Test 2.2: ValidationServiceException has correct exit code (1)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ValidationServiceException exit code"

validationExitCode=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    use Eiou\Exceptions\ValidationServiceException;
    use Eiou\Core\ErrorCodes;

    \$exception = new ValidationServiceException('Test error', ErrorCodes::VALIDATION_ERROR, 'test_field');
    echo \$exception->getExitCode();
" 2>&1 | tail -1)

if [[ "$validationExitCode" == "1" ]]; then
    printf "\t   ValidationServiceException exit code is 1 ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ValidationServiceException exit code ${RED}FAILED${NC} (got: %s, expected: 1)\n" "${validationExitCode}"
    failure=$(( failure + 1 ))
fi

# Test 2.3: RecoverableServiceException has correct default exit code (0)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing RecoverableServiceException default exit code"

recoverableExitCode=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    use Eiou\Exceptions\RecoverableServiceException;
    use Eiou\Core\ErrorCodes;

    \$exception = new RecoverableServiceException('Test error', ErrorCodes::GENERAL_ERROR);
    echo \$exception->getExitCode();
" 2>&1 | tail -1)

if [[ "$recoverableExitCode" == "0" ]]; then
    printf "\t   RecoverableServiceException default exit code is 0 ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   RecoverableServiceException exit code ${RED}FAILED${NC} (got: %s, expected: 0)\n" "${recoverableExitCode}"
    failure=$(( failure + 1 ))
fi

# Test 2.4: ValidationServiceException preserves field name
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ValidationServiceException field property"

fieldName=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    use Eiou\Exceptions\ValidationServiceException;
    use Eiou\Core\ErrorCodes;

    \$exception = new ValidationServiceException('Invalid name', ErrorCodes::INVALID_NAME, 'contact_name');
    echo \$exception->getField();
" 2>&1 | tail -1)

if [[ "$fieldName" == "contact_name" ]]; then
    printf "\t   ValidationServiceException preserves field name ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ValidationServiceException field ${RED}FAILED${NC} (got: %s, expected: contact_name)\n" "${fieldName}"
    failure=$(( failure + 1 ))
fi

# Test 2.5: ServiceException preserves error code
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ServiceException error code preservation"

errorCode=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    use Eiou\Exceptions\FatalServiceException;
    use Eiou\Core\ErrorCodes;

    \$exception = new FatalServiceException('Test error', ErrorCodes::WALLET_NOT_FOUND);
    echo \$exception->getErrorCode();
" 2>&1 | tail -1)

if [[ "$errorCode" == "WALLET_NOT_FOUND" ]]; then
    printf "\t   ServiceException preserves error code ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ServiceException error code ${RED}FAILED${NC} (got: %s)\n" "${errorCode}"
    failure=$(( failure + 1 ))
fi

# Test 2.6: ServiceException preserves HTTP status
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ServiceException HTTP status preservation"

httpStatus=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    use Eiou\Exceptions\FatalServiceException;
    use Eiou\Core\ErrorCodes;

    \$exception = new FatalServiceException('Test error', ErrorCodes::WALLET_NOT_FOUND, [], 404);
    echo \$exception->getHttpStatus();
" 2>&1 | tail -1)

if [[ "$httpStatus" == "404" ]]; then
    printf "\t   ServiceException preserves HTTP status ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ServiceException HTTP status ${RED}FAILED${NC} (got: %s, expected: 404)\n" "${httpStatus}"
    failure=$(( failure + 1 ))
fi

# Test 2.7: ServiceException toArray() method
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ServiceException toArray() method"

toArrayResult=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    use Eiou\Exceptions\FatalServiceException;
    use Eiou\Core\ErrorCodes;

    \$exception = new FatalServiceException('Test error message', ErrorCodes::INTERNAL_ERROR, ['key' => 'value'], 500);
    \$array = \$exception->toArray();

    if (isset(\$array['success']) && \$array['success'] === false &&
        isset(\$array['error']['code']) && \$array['error']['code'] === 'INTERNAL_ERROR' &&
        isset(\$array['error']['message']) && \$array['error']['message'] === 'Test error message' &&
        isset(\$array['error']['context']['key']) && \$array['error']['context']['key'] === 'value') {
        echo 'SUCCESS';
    } else {
        echo 'FAILED:' . json_encode(\$array);
    }
" 2>&1 | tail -1)

if [[ "$toArrayResult" == "SUCCESS" ]]; then
    printf "\t   ServiceException toArray() returns correct structure ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ServiceException toArray() ${RED}FAILED${NC} (%s)\n" "${toArrayResult}"
    failure=$(( failure + 1 ))
fi

############################ SECTION 3: CLI EXIT CODES ############################

echo -e "\n[Section 3: CLI Exit Codes]"

# Test 3.1: Invalid name in search command produces exit code 1
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing CLI exit code for invalid search name"

# Use a name with invalid characters that should trigger ValidationServiceException
# Using @ and ! which are not in the allowed character set [a-zA-Z0-9_\s-]
docker exec ${testContainer} eiou search 'invalid@name!test' --json >/dev/null 2>&1
exitCode=$?

if [[ "$exitCode" == "1" ]]; then
    printf "\t   Invalid search name produces exit code 1 ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Invalid search name exit code ${RED}FAILED${NC} (got: %s, expected: 1)\n" "${exitCode}"
    failure=$(( failure + 1 ))
fi

# Test 3.2: Valid command produces exit code 0
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing CLI exit code for valid command"

docker exec ${testContainer} eiou help >/dev/null 2>&1
exitCode=$?

if [[ "$exitCode" == "0" ]]; then
    printf "\t   Valid help command produces exit code 0 ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Valid command exit code ${RED}FAILED${NC} (got: %s, expected: 0)\n" "${exitCode}"
    failure=$(( failure + 1 ))
fi

# Test 3.3: viewcontact with invalid address format produces exit code 1
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing CLI exit code for invalid viewcontact address"

# Use an address that IS recognized as an address format (http://) but contains invalid characters
# This will pass isAddress() check but fail validateAddress()
docker exec ${testContainer} eiou viewcontact 'http://invalid<script>address' --json >/dev/null 2>&1
exitCode=$?

if [[ "$exitCode" == "1" ]]; then
    printf "\t   Invalid viewcontact address produces exit code 1 ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Invalid viewcontact address exit code ${RED}FAILED${NC} (got: %s, expected: 1)\n" "${exitCode}"
    failure=$(( failure + 1 ))
fi

############################ SECTION 4: ERROR MESSAGE FORMAT ############################

echo -e "\n[Section 4: Error Message Format]"

# Test 4.1: ValidationServiceException error includes error code in JSON output
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing error message contains error code"

# Use invalid characters that trigger validation error
errorOutput=$(docker exec ${testContainer} eiou search 'test@invalid!' --json 2>&1)

if [[ "$errorOutput" =~ "INVALID" ]] || [[ "$errorOutput" =~ "error" ]] || [[ "$errorOutput" =~ "invalid" ]]; then
    printf "\t   Error message contains error code ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Error message format ${RED}FAILED${NC}\n"
    printf "\t   Output: ${errorOutput}\n"
    failure=$(( failure + 1 ))
fi

# Test 4.2: Error output is valid JSON when --json flag is used
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing error output is valid JSON"

jsonValid=$(docker exec ${testContainer} php -r "
    \$output = shell_exec('eiou search \"test@invalid\" --json 2>&1');
    \$decoded = json_decode(\$output, true);
    if (\$decoded !== null && isset(\$decoded['success'])) {
        echo 'SUCCESS';
    } else {
        echo 'FAILED:' . substr(\$output, 0, 100);
    }
" 2>&1 | tail -1)

if [[ "$jsonValid" == "SUCCESS" ]]; then
    printf "\t   Error output is valid JSON ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Error JSON format ${RED}FAILED${NC} (%s)\n" "${jsonValid}"
    failure=$(( failure + 1 ))
fi

############################ SECTION 5: EXCEPTION HANDLING IN ENTRY POINTS ############################

echo -e "\n[Section 5: Exception Handling in Entry Points]"

# Test 5.1: Eiou.php has ServiceException imports
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing Eiou.php imports ServiceException classes"

# CLI entry point is at /usr/local/bin/eiou.php
importsExist=$(docker exec ${testContainer} sh -c "grep -c 'Eiou.Exceptions.ServiceException' /usr/local/bin/eiou.php 2>/dev/null || echo 0")

if [[ "$importsExist" -ge "1" ]]; then
    printf "\t   Eiou.php imports ServiceException ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Eiou.php ServiceException import ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 5.2: Eiou.php has try-catch for ServiceExceptions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing Eiou.php has ServiceException catch blocks"

catchExists=$(docker exec ${testContainer} sh -c "grep -c 'catch.*ServiceException' /usr/local/bin/eiou.php 2>/dev/null || echo 0")

if [[ "$catchExists" -ge "1" ]]; then
    printf "\t   Eiou.php has ServiceException catch blocks ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Eiou.php catch blocks ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 5.3: ApiController.php has ServiceException import
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ApiController.php imports ServiceException"

apiImportExists=$(docker exec ${testContainer} sh -c "grep -c 'Eiou.Exceptions.ServiceException' /etc/eiou/src/api/ApiController.php 2>/dev/null || echo 0")

if [[ "$apiImportExists" -ge "1" ]]; then
    printf "\t   ApiController.php imports ServiceException ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ApiController.php ServiceException import ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 5.4: ApiController.php has ServiceException catch block
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ApiController.php has ServiceException catch block"

apiCatchExists=$(docker exec ${testContainer} sh -c "grep -c 'catch.*ServiceException' /etc/eiou/src/api/ApiController.php 2>/dev/null || echo 0")

if [[ "$apiCatchExists" -ge "1" ]]; then
    printf "\t   ApiController.php has ServiceException catch block ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ApiController.php catch block ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 6: SERVICE METHODS THROW EXCEPTIONS ############################

echo -e "\n[Section 6: Service Methods Use Exceptions]"

# Test 6.1: ContactService uses ValidationServiceException
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ContactService uses ValidationServiceException"

contactServiceUses=$(docker exec ${testContainer} sh -c "grep -c 'ValidationServiceException' /etc/eiou/src/services/ContactService.php 2>/dev/null || echo 0")

if [[ "$contactServiceUses" -ge "1" ]]; then
    printf "\t   ContactService uses ValidationServiceException ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   ContactService ValidationServiceException usage ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6.2: WalletService uses FatalServiceException
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing WalletService uses FatalServiceException"

walletServiceUses=$(docker exec ${testContainer} sh -c "grep -c 'FatalServiceException' /etc/eiou/src/services/WalletService.php 2>/dev/null || echo 0")

if [[ "$walletServiceUses" -ge "1" ]]; then
    printf "\t   WalletService uses FatalServiceException ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   WalletService FatalServiceException usage ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6.3: MessageService uses FatalServiceException
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing MessageService uses FatalServiceException"

messageServiceUses=$(docker exec ${testContainer} sh -c "grep -c 'FatalServiceException' /etc/eiou/src/services/MessageService.php 2>/dev/null || echo 0")

if [[ "$messageServiceUses" -ge "1" ]]; then
    printf "\t   MessageService uses FatalServiceException ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   MessageService FatalServiceException usage ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6.4: No exit() calls remain in service files
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing no exit() calls in service files"

exitCalls=$(docker exec ${testContainer} sh -c "grep -l 'exit(' /etc/eiou/src/services/ContactService.php /etc/eiou/src/services/WalletService.php /etc/eiou/src/services/MessageService.php 2>/dev/null | wc -l")

if [[ "$exitCalls" == "0" ]]; then
    printf "\t   No exit() calls in service files ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   exit() calls still exist in services ${RED}FAILED${NC} (found in %s files)\n" "${exitCalls}"
    failure=$(( failure + 1 ))
fi

############################ RESULTS ############################

succesrate "${totaltests}" "${passed}" "${failure}" "'service exception tests'"
