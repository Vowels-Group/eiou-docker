#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Service Interface Test ############################
# Verifies that all services properly implement their interfaces
#
# Tests:
# - All interface files exist in contracts/ directory
# - All services implement their respective interfaces
# - ServiceContainer returns interface types correctly
# - Mock injection works via interfaces (dependency inversion)
#
# Prerequisites:
# - Containers must be running with eiou files initialized
##################################################################################

############################ Testing #############################

testname="serviceInterfaceTest"
totaltests=0
passed=0
failure=0

# Use first container for testing (interfaces are the same across all containers)
test_container="${containers[0]}"

printf "\n${GREEN}Testing Service Interfaces on container: ${test_container}${NC}\n\n"

# Define all interfaces and their implementing services
declare -A INTERFACE_MAP
INTERFACE_MAP["TransportServiceInterface"]="TransportUtilityService"
INTERFACE_MAP["ContactServiceInterface"]="ContactService"
INTERFACE_MAP["TransactionServiceInterface"]="TransactionService"
INTERFACE_MAP["SyncServiceInterface"]="SyncService"
INTERFACE_MAP["P2pServiceInterface"]="P2pService"
INTERFACE_MAP["ApiKeyServiceInterface"]="ApiKeyService"
INTERFACE_MAP["MessageServiceInterface"]="MessageService"
INTERFACE_MAP["CliServiceInterface"]="CliService"
INTERFACE_MAP["CleanupServiceInterface"]="CleanupService"
INTERFACE_MAP["WalletServiceInterface"]="WalletService"
INTERFACE_MAP["Rp2pServiceInterface"]="RP2pService"
INTERFACE_MAP["RateLimiterServiceInterface"]="RateLimiterService"
INTERFACE_MAP["ContactStatusServiceInterface"]="ContactStatusService"
INTERFACE_MAP["MessageDeliveryServiceInterface"]="MessageDeliveryService"
INTERFACE_MAP["DebugServiceInterface"]="DebugService"
INTERFACE_MAP["ApiAuthServiceInterface"]="ApiAuthService"
INTERFACE_MAP["HeldTransactionServiceInterface"]="HeldTransactionService"
INTERFACE_MAP["TransactionRecoveryServiceInterface"]="TransactionRecoveryService"
INTERFACE_MAP["TimeUtilityServiceInterface"]="TimeUtilityService"
INTERFACE_MAP["ValidationUtilityServiceInterface"]="ValidationUtilityService"
INTERFACE_MAP["GeneralUtilityServiceInterface"]="GeneralUtilityService"
INTERFACE_MAP["CurrencyUtilityServiceInterface"]="CurrencyUtilityService"

############################ Test 1: Interface Files Exist ############################
printf "${YELLOW}Test 1: Verifying interface files exist${NC}\n"

for interface in "${!INTERFACE_MAP[@]}"; do
    totaltests=$((totaltests + 1))
    interface_file="${EIOU_DIR}//src//contracts//${interface}.php"

    file_exists=$(docker exec $test_container php -r "
        echo file_exists('${interface_file}') ? 'yes' : 'no';
    " 2>/dev/null || echo "error")

    if [ "$file_exists" = "yes" ]; then
        printf "\t   Interface file ${interface}.php ${GREEN}EXISTS${NC}\n"
        passed=$((passed + 1))
    else
        printf "\t   Interface file ${interface}.php ${RED}MISSING${NC}\n"
        failure=$((failure + 1))
    fi
done

############################ Test 2: Services Implement Interfaces ############################
printf "\n${YELLOW}Test 2: Verifying services implement their interfaces${NC}\n"

for interface in "${!INTERFACE_MAP[@]}"; do
    totaltests=$((totaltests + 1))
    service="${INTERFACE_MAP[$interface]}"

    implements_interface=$(docker exec $test_container php -r "
        require_once('${REL_APPLICATION}');

        // Map service names to their full class paths
        \$serviceClassMap = [
            'TransportUtilityService' => 'Eiou\\\Services\\\Utilities\\\TransportUtilityService',
            'ContactService' => 'Eiou\\\Services\\\ContactService',
            'TransactionService' => 'Eiou\\\Services\\\TransactionService',
            'SyncService' => 'SyncService',
            'P2pService' => 'Eiou\\\Services\\\P2pService',
            'ApiKeyService' => 'Eiou\\\Services\\\ApiKeyService',
            'MessageService' => 'Eiou\\\Services\\\MessageService',
            'CliService' => 'Eiou\\\Services\\\CliService',
            'CleanupService' => 'Eiou\\\Services\\\CleanupService',
            'WalletService' => 'Eiou\\\Services\\\WalletService',
            'RP2pService' => 'Eiou\\\Services\\\RP2pService',
            'RateLimiterService' => 'Eiou\\\Services\\\RateLimiterService',
            'ContactStatusService' => 'Eiou\\\Services\\\ContactStatusService',
            'MessageDeliveryService' => 'Eiou\\\Services\\\MessageDeliveryService',
            'DebugService' => 'Eiou\\\Services\\\DebugService',
            'ApiAuthService' => 'Eiou\\\Services\\\ApiAuthService',
            'HeldTransactionService' => 'Eiou\\\Services\\\HeldTransactionService',
            'TransactionRecoveryService' => 'Eiou\\\Services\\\TransactionRecoveryService',
            'TimeUtilityService' => 'Eiou\\\Services\\\Utilities\\\TimeUtilityService',
            'ValidationUtilityService' => 'Eiou\\\Services\\\Utilities\\\ValidationUtilityService',
            'GeneralUtilityService' => 'Eiou\\\Services\\\Utilities\\\GeneralUtilityService',
            'CurrencyUtilityService' => 'Eiou\\\Services\\\Utilities\\\CurrencyUtilityService',
        ];

        \$serviceClass = \$serviceClassMap['${service}'] ?? '${service}';
        \$interfaceClass = 'Eiou\\\Contracts\\\${interface}';

        if (class_exists(\$serviceClass) && interface_exists(\$interfaceClass)) {
            \$reflection = new ReflectionClass(\$serviceClass);
            echo \$reflection->implementsInterface(\$interfaceClass) ? 'yes' : 'no';
        } else {
            echo 'missing';
        }
    " 2>/dev/null || echo "error")

    if [ "$implements_interface" = "yes" ]; then
        printf "\t   ${service} implements ${interface} ${GREEN}PASSED${NC}\n"
        passed=$((passed + 1))
    elif [ "$implements_interface" = "missing" ]; then
        printf "\t   ${service} or ${interface} ${RED}CLASS NOT FOUND${NC}\n"
        failure=$((failure + 1))
    else
        printf "\t   ${service} implements ${interface} ${RED}FAILED${NC}\n"
        failure=$((failure + 1))
    fi
done

############################ Test 3: ServiceContainer Returns Interface Types ############################
printf "\n${YELLOW}Test 3: Verifying ServiceContainer returns interface types${NC}\n"

# Test key service getters return interface types
service_getters=(
    "getContactService:ContactServiceInterface"
    "getTransactionService:TransactionServiceInterface"
    "getP2pService:P2pServiceInterface"
    "getSyncService:SyncServiceInterface"
    "getMessageService:MessageServiceInterface"
    "getCliService:CliServiceInterface"
    "getCleanupService:CleanupServiceInterface"
    "getRateLimiterService:RateLimiterServiceInterface"
)

for getter_pair in "${service_getters[@]}"; do
    totaltests=$((totaltests + 1))
    getter="${getter_pair%%:*}"
    expected_interface="${getter_pair##*:}"

    returns_interface=$(docker exec $test_container php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        \$service = \$app->services->${getter}();
        \$interfaceClass = 'Eiou\\\Contracts\\\${expected_interface}';
        echo (\$service instanceof \$interfaceClass) ? 'yes' : 'no';
    " 2>/dev/null || echo "error")

    if [ "$returns_interface" = "yes" ]; then
        printf "\t   ServiceContainer->${getter}() returns ${expected_interface} ${GREEN}PASSED${NC}\n"
        passed=$((passed + 1))
    else
        printf "\t   ServiceContainer->${getter}() returns ${expected_interface} ${RED}FAILED${NC}\n"
        failure=$((failure + 1))
    fi
done

############################ Test 4: Mock Injection Capability ############################
printf "\n${YELLOW}Test 4: Verifying interface type hints allow mock injection${NC}\n"
totaltests=$((totaltests + 1))

# Test that a function with interface type hint accepts the concrete implementation
mock_test=$(docker exec $test_container php -r "
    require_once('${REL_APPLICATION}');

    // Create a test function that accepts interface type
    function testTransportInterface(Eiou\Contracts\TransportServiceInterface \$transport): bool {
        return \$transport->isAddress('http://test');
    }

    // Get actual service from container
    \$app = Application::getInstance();
    \$transport = \$app->services->utilities->getTransportUtility();

    // This will fail if the service doesn't implement the interface
    try {
        testTransportInterface(\$transport);
        echo 'yes';
    } catch (TypeError \$e) {
        echo 'no';
    }
" 2>/dev/null || echo "error")

if [ "$mock_test" = "yes" ]; then
    printf "\t   Interface type hints accept implementations ${GREEN}PASSED${NC}\n"
    passed=$((passed + 1))
else
    printf "\t   Interface type hints accept implementations ${RED}FAILED${NC}\n"
    failure=$((failure + 1))
fi

############################ Test 5: Dependency Inversion Works ############################
printf "\n${YELLOW}Test 5: Verifying dependency inversion principle${NC}\n"
totaltests=$((totaltests + 1))

# Test that code can depend on abstractions (interfaces) not concretions
di_test=$(docker exec $test_container php -r "
    require_once('${REL_APPLICATION}');

    // A class that depends on interface, not concrete implementation
    class TestConsumer {
        private Eiou\Contracts\ContactServiceInterface \$contactService;

        public function __construct(Eiou\Contracts\ContactServiceInterface \$contactService) {
            \$this->contactService = \$contactService;
        }

        public function hasService(): bool {
            return \$this->contactService !== null;
        }
    }

    \$app = Application::getInstance();
    \$contactService = \$app->services->getContactService();

    try {
        \$consumer = new TestConsumer(\$contactService);
        echo \$consumer->hasService() ? 'yes' : 'no';
    } catch (TypeError \$e) {
        echo 'no';
    }
" 2>/dev/null || echo "error")

if [ "$di_test" = "yes" ]; then
    printf "\t   Dependency inversion principle ${GREEN}PASSED${NC}\n"
    passed=$((passed + 1))
else
    printf "\t   Dependency inversion principle ${RED}FAILED${NC}\n"
    failure=$((failure + 1))
fi

############################### Summary ###############################
printf "\n"
succesrate "${totaltests}" "${passed}" "${failure}" "'${testname}'"

##################################################################
