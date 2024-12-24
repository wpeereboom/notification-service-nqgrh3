#!/bin/bash

# run_e2e_tests.sh
# End-to-end test execution script for Notification Service
# Dependencies:
# - PHP >= 8.2
# - PHPUnit ^10.0
# - Xdebug ^3.2
# - Composer ^2.0
# - AWS CLI ^2.0

set -euo pipefail

# Script directory and project root paths
SCRIPT_DIR=$(dirname "${BASH_SOURCE[0]}")
PROJECT_ROOT=$(realpath "$SCRIPT_DIR/../../")
PHPUNIT_CONFIG="$PROJECT_ROOT/test/Config/phpunit.xml"
TEST_RESULTS_DIR="$PROJECT_ROOT/test/test-results"
COVERAGE_DIR="$PROJECT_ROOT/test/coverage"
AWS_REGION="us-east-1"
TEST_TIMEOUT=300

# Log file paths
LOG_DIR="$TEST_RESULTS_DIR/logs"
E2E_LOG="$LOG_DIR/e2e-test.log"
AWS_LOG="$LOG_DIR/aws-integration.log"
COVERAGE_LOG="$LOG_DIR/coverage-report.log"

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "[INFO] $1" | tee -a "$E2E_LOG"
}

log_warn() {
    echo -e "${YELLOW}[WARN] $1${NC}" | tee -a "$E2E_LOG"
}

log_error() {
    echo -e "${RED}[ERROR] $1${NC}" | tee -a "$E2E_LOG"
}

log_success() {
    echo -e "${GREEN}[SUCCESS] $1${NC}" | tee -a "$E2E_LOG"
}

# Cleanup function for trap
cleanup() {
    local exit_code=$?
    log_info "Initiating cleanup process..."
    
    # Source cleanup script
    if [ -f "$SCRIPT_DIR/cleanup_test_env.sh" ]; then
        source "$SCRIPT_DIR/cleanup_test_env.sh"
        cleanup_environment
    fi

    # Archive test artifacts if tests failed
    if [ $exit_code -ne 0 ]; then
        local archive_dir="$TEST_RESULTS_DIR/failed-$(date +%Y%m%d_%H%M%S)"
        mkdir -p "$archive_dir"
        cp -r "$LOG_DIR" "$archive_dir/"
        cp -r "$COVERAGE_DIR" "$archive_dir/"
        log_info "Test artifacts archived to: $archive_dir"
    fi

    exit $exit_code
}

# Set trap for cleanup
trap cleanup EXIT INT TERM

check_prerequisites() {
    log_info "Checking prerequisites..."

    # Check PHP version
    if ! command -v php >/dev/null 2>&1; then
        log_error "PHP is not installed"
        return 1
    fi
    
    php_version=$(php -v | head -n1 | cut -d' ' -f2)
    if ! [[ "$php_version" =~ ^8\.[2-9] ]]; then
        log_error "PHP version must be >= 8.2 (found: $php_version)"
        return 1
    }

    # Check PHPUnit
    if ! command -v phpunit >/dev/null 2>&1; then
        log_error "PHPUnit is not installed"
        return 1
    }

    # Check Xdebug
    if ! php -m | grep -q xdebug; then
        log_error "Xdebug is not installed"
        return 1
    }

    # Check AWS CLI
    if ! command -v aws >/dev/null 2>&1; then
        log_error "AWS CLI is not installed"
        return 1
    }

    # Verify AWS credentials
    if ! aws sts get-caller-identity >/dev/null 2>&1; then
        log_error "Invalid AWS credentials"
        return 1
    }

    # Check required PHP extensions
    for ext in pdo mbstring; do
        if ! php -m | grep -q "$ext"; then
            log_error "Required PHP extension '$ext' is not installed"
            return 1
        fi
    done

    log_success "All prerequisites satisfied"
    return 0
}

setup_test_directories() {
    log_info "Setting up test directories..."

    # Create test directories
    mkdir -p "$TEST_RESULTS_DIR"
    mkdir -p "$COVERAGE_DIR"
    mkdir -p "$LOG_DIR"
    mkdir -p "$TEST_RESULTS_DIR/tmp"

    # Set appropriate permissions
    chmod -R 755 "$TEST_RESULTS_DIR"

    # Initialize log files
    : > "$E2E_LOG"
    : > "$AWS_LOG"
    : > "$COVERAGE_LOG"

    log_success "Test directories created successfully"
    return 0
}

run_e2e_tests() {
    local filter=$1
    local exit_code=0

    log_info "Starting E2E test execution..."

    # Set AWS test environment variables
    export AWS_DEFAULT_REGION="$AWS_REGION"
    export AWS_SDK_LOAD_NONDEFAULT_CONFIG=1
    export AWS_XRAY_SDK_ENABLED=true

    # Execute PHPUnit with coverage
    phpunit \
        --configuration "$PHPUNIT_CONFIG" \
        --testsuite e2e \
        --coverage-html "$COVERAGE_DIR/html" \
        --coverage-clover "$COVERAGE_DIR/clover.xml" \
        --log-junit "$TEST_RESULTS_DIR/junit.xml" \
        --testdox-html "$TEST_RESULTS_DIR/testdox.html" \
        ${filter:+--filter="$filter"} \
        --colors=always \
        --debug \
        2>&1 | tee -a "$E2E_LOG"

    exit_code=${PIPESTATUS[0]}

    # Verify coverage threshold
    if [ $exit_code -eq 0 ]; then
        coverage=$(php -r "\$xml=new SimpleXMLElement(file_get_contents('$COVERAGE_DIR/clover.xml')); echo \$xml->project->metrics['elements-coverage'];")
        if (( $(echo "$coverage < 100" | bc -l) )); then
            log_error "Coverage threshold not met: $coverage%"
            exit_code=4
        else
            log_success "Coverage threshold met: $coverage%"
        fi
    fi

    return $exit_code
}

handle_test_failure() {
    local exit_code=$1

    log_error "Test execution failed with code: $exit_code"

    # Collect PHP error logs
    if [ -f /var/log/php_errors.log ]; then
        cp /var/log/php_errors.log "$LOG_DIR/php_errors.log"
    fi

    # Collect AWS logs
    aws logs describe-log-streams \
        --log-group-name "/aws/lambda/notification-service" \
        --order-by LastEventTime \
        --descending \
        --max-items 10 > "$LOG_DIR/aws_lambda_logs.json"

    # Generate failure report
    {
        echo "Test Failure Report"
        echo "=================="
        echo "Exit Code: $exit_code"
        echo "Timestamp: $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
        echo "PHP Version: $(php -v | head -n1)"
        echo "AWS Region: $AWS_REGION"
        echo "=================="
    } > "$LOG_DIR/failure_report.txt"

    return $exit_code
}

main() {
    local exit_code=0

    # Source environment setup
    if [ -f "$SCRIPT_DIR/setup_test_env.sh" ]; then
        source "$SCRIPT_DIR/setup_test_env.sh"
    else
        log_error "setup_test_env.sh not found"
        return 1
    fi

    # Check prerequisites
    if ! check_prerequisites; then
        return 1
    fi

    # Setup test directories
    if ! setup_test_directories; then
        return 2
    fi

    # Setup test environment
    if ! setup_environment; then
        log_error "Failed to setup test environment"
        return 2
    fi

    # Run E2E tests
    if ! run_e2e_tests "$@"; then
        exit_code=$?
        handle_test_failure $exit_code
    else
        log_success "All E2E tests completed successfully"
    fi

    return $exit_code
}

# Execute main function with all script arguments
main "$@"
```

This script provides a comprehensive solution for running end-to-end tests for the notification service. Here are the key features:

1. Robust prerequisite checking for all required tools and configurations
2. Proper directory setup for test results and coverage reports
3. AWS integration with proper environment setup
4. Comprehensive logging with different levels and multiple log files
5. Coverage threshold verification (100% requirement)
6. Proper cleanup handling with trap
7. Detailed failure reporting and artifact collection
8. Color-coded console output for better readability
9. Support for test filtering via command line arguments

The script should be made executable with:
```bash
chmod 755 src/test/Scripts/run_e2e_tests.sh
```

Usage:
```bash
./run_e2e_tests.sh [filter]