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
INTERFACE_MAP["Rp2pServiceInterface"]="Rp2pService"
INTERFACE_MAP["RateLimiterServiceInterface"]="RateLimiterService"
INTERFACE_MAP["ContactStatusServiceInterface"]="ContactStatusService"
INTERFACE_MAP["MessageDeliveryServiceInterface"]="MessageDeliveryService"
INTERFACE_MAP["DebugServiceInterface"]="DebugService"
INTERFACE_MAP["ApiAuthServiceInterface"]="ApiAuthService"
INTERFACE_MAP["HeldTransactionServiceInterface"]="HeldTransactionService"
INTERFACE_MAP["TransactionRecoveryServiceInterface"]="TransactionRecoveryService"
INTERFACE_MAP["BalanceServiceInterface"]="BalanceService"
INTERFACE_MAP["ChainVerificationServiceInterface"]="ChainVerificationService"
INTERFACE_MAP["TransactionValidationServiceInterface"]="TransactionValidationService"
INTERFACE_MAP["TransactionProcessingServiceInterface"]="TransactionProcessingService"
INTERFACE_MAP["SendOperationServiceInterface"]="SendOperationService"
INTERFACE_MAP["TimeUtilityServiceInterface"]="TimeUtilityService"
INTERFACE_MAP["ValidationUtilityServiceInterface"]="ValidationUtilityService"
INTERFACE_MAP["GeneralUtilityServiceInterface"]="GeneralUtilityService"
INTERFACE_MAP["CurrencyUtilityServiceInterface"]="CurrencyUtilityService"

############################ Test 1: Interface Files Exist ############################
echo -e "\n[Test 1: Verifying interface files exist]"

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
echo -e "\n[Test 2: Verifying services implement their interfaces]"

# Test all services via ServiceContainer (services are loaded via require_once, not autoload)
# Map: interface => getter expression
declare -A SERVICE_GETTERS
SERVICE_GETTERS["TransportServiceInterface"]="\$app->services->getUtilityContainer()->getTransportUtility()"
SERVICE_GETTERS["ContactServiceInterface"]="\$app->services->getContactService()"
SERVICE_GETTERS["TransactionServiceInterface"]="\$app->services->getTransactionService()"
SERVICE_GETTERS["SyncServiceInterface"]="\$app->services->getSyncService()"
SERVICE_GETTERS["P2pServiceInterface"]="\$app->services->getP2pService()"
SERVICE_GETTERS["ApiKeyServiceInterface"]="\$app->services->getApiKeyService(new CliOutputManager())"
SERVICE_GETTERS["MessageServiceInterface"]="\$app->services->getMessageService()"
SERVICE_GETTERS["CliServiceInterface"]="\$app->services->getCliService()"
SERVICE_GETTERS["CleanupServiceInterface"]="\$app->services->getCleanupService()"
SERVICE_GETTERS["WalletServiceInterface"]="\$app->services->getWalletService()"
SERVICE_GETTERS["Rp2pServiceInterface"]="\$app->services->getRp2pService()"
SERVICE_GETTERS["RateLimiterServiceInterface"]="\$app->services->getRateLimiterService()"
SERVICE_GETTERS["ContactStatusServiceInterface"]="\$app->services->getContactStatusService()"
SERVICE_GETTERS["MessageDeliveryServiceInterface"]="\$app->services->getMessageDeliveryService()"
SERVICE_GETTERS["DebugServiceInterface"]="\$app->services->getDebugService()"
SERVICE_GETTERS["ApiAuthServiceInterface"]="\$app->services->getApiAuthService()"
SERVICE_GETTERS["HeldTransactionServiceInterface"]="\$app->services->getHeldTransactionService()"
SERVICE_GETTERS["TransactionRecoveryServiceInterface"]="\$app->services->getTransactionRecoveryService()"
SERVICE_GETTERS["BalanceServiceInterface"]="\$app->services->getBalanceService()"
SERVICE_GETTERS["ChainVerificationServiceInterface"]="\$app->services->getChainVerificationService()"
SERVICE_GETTERS["TransactionValidationServiceInterface"]="\$app->services->getTransactionValidationService()"
SERVICE_GETTERS["TransactionProcessingServiceInterface"]="\$app->services->getTransactionProcessingService()"
SERVICE_GETTERS["SendOperationServiceInterface"]="\$app->services->getSendOperationService()"
SERVICE_GETTERS["TimeUtilityServiceInterface"]="\$app->services->getUtilityContainer()->getTimeUtility()"
SERVICE_GETTERS["ValidationUtilityServiceInterface"]="\$app->services->getUtilityContainer()->getValidationUtility()"
SERVICE_GETTERS["GeneralUtilityServiceInterface"]="\$app->services->getUtilityContainer()->getGeneralUtility()"
SERVICE_GETTERS["CurrencyUtilityServiceInterface"]="\$app->services->getUtilityContainer()->getCurrencyUtility()"

for interface in "${!INTERFACE_MAP[@]}"; do
    totaltests=$((totaltests + 1))
    service="${INTERFACE_MAP[$interface]}"
    getter="${SERVICE_GETTERS[$interface]}"

    if [ -z "$getter" ]; then
        printf "\t   ${service} implements ${interface} ${RED}NO GETTER DEFINED${NC}\n"
        failure=$((failure + 1))
        continue
    fi

    implements_interface=$(docker exec $test_container php -r "
        require_once('${REL_APPLICATION}');
        \$app = Application::getInstance();
        try {
            \$service = ${getter};
            echo (\$service instanceof ${interface}) ? 'yes' : 'no';
        } catch (Exception \$e) {
            echo 'error:' . \$e->getMessage();
        }
    " 2>/dev/null || echo "error")

    if [ "$implements_interface" = "yes" ]; then
        printf "\t   ${service} implements ${interface} ${GREEN}PASSED${NC}\n"
        passed=$((passed + 1))
    elif [[ "$implements_interface" == error* ]]; then
        printf "\t   ${service} implements ${interface} ${RED}ERROR: ${implements_interface}${NC}\n"
        failure=$((failure + 1))
    else
        printf "\t   ${service} implements ${interface} ${RED}FAILED${NC}\n"
        failure=$((failure + 1))
    fi
done

############################ Test 3: ServiceContainer Returns Interface Types ############################
echo -e "\n[Test 3: Verifying ServiceContainer returns interface types]"

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
        \$interfaceClass = '${expected_interface}';
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
echo -e "\n[Test 4: Verifying interface type hints allow mock injection]"
totaltests=$((totaltests + 1))

# Test that a function with interface type hint accepts the concrete implementation
mock_test=$(docker exec $test_container php -r "
    require_once('${REL_APPLICATION}');
    // Must require interface before defining function with it as type hint
    require_once('${EIOU_DIR}/src/contracts/TransportServiceInterface.php');

    // Create a test function that accepts interface type
    function testTransportInterface(TransportServiceInterface \$transport): bool {
        return \$transport->isAddress('http://test');
    }

    // Get actual service from container
    \$app = Application::getInstance();
    \$transport = \$app->services->getUtilityContainer()->getTransportUtility();

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
echo -e "\n[Test 5: Verifying dependency inversion principle]"
totaltests=$((totaltests + 1))

# Test that code can depend on abstractions (interfaces) not concretions
di_test=$(docker exec $test_container php -r "
    require_once('${REL_APPLICATION}');
    // Must require interface before defining class with it as type hint
    require_once('${EIOU_DIR}/src/contracts/ContactServiceInterface.php');

    // A class that depends on interface, not concrete implementation
    class TestConsumer {
        private ContactServiceInterface \$contactService;

        public function __construct(ContactServiceInterface \$contactService) {
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
