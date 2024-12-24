#!/usr/bin/env bash

# run_integration_tests.sh
# Integration test execution script for Notification Service
# Version: 1.0
# PHPUnit Version: ^10.0

# Exit on error, undefined variables, and pipe failures
set -euo pipefail

# Global variables
SCRIPT_DIR=$(dirname "${BASH_SOURCE[0]}")
PROJECT_ROOT=$(realpath "$SCRIPT_DIR/../../")
PHPUNIT_CONFIG="$PROJECT_ROOT/test/Config/phpunit.xml"
TEST_ENV_FILE="$PROJECT_ROOT/test/Config/test.env"
TEST_RESULTS_DIR="$PROJECT_ROOT/test/test-results"
COVERAGE_DIR="$PROJECT_ROOT/test/coverage"
LOG_FILE="$TEST_RESULTS_DIR/integration-tests.log"
MAX_RETRIES=3
PARALLEL_JOBS=4

# Logging functions
log_info() { echo "[INFO] $(date '+%Y-%m-%d %H:%M:%S') - $*" | tee -a "$LOG_FILE"; }
log_warn() { echo "[WARN] $(date '+%Y-%m-%d %H:%M:%S') - $*" | tee -a "$LOG_FILE"; }
log_error() { echo "[ERROR] $(date '+%Y-%m-%d %H:%M:%S') - $*" | tee -a "$LOG_FILE"; }
log_debug() { echo "[DEBUG] $(date '+%Y-%m-%d %H:%M:%S') - $*" >> "$LOG_FILE"; }

# Error handling and cleanup
cleanup() {
    local exit_code=$?
    log_info "Initiating cleanup process..."
    
    # Stop running tests gracefully
    if [ -f "$TEST_RESULTS_DIR/.pid" ]; then
        local pid
        pid=$(cat "$TEST_RESULTS_DIR/.pid")
        kill -15 "$pid" 2>/dev/null || true
    fi
    
    # Archive partial results if they exist
    if [ -d "$TEST_RESULTS_DIR" ]; then
        log_info "Archiving test results..."
        tar -czf "$TEST_RESULTS_DIR/partial-results-$(date +%Y%m%d_%H%M%S).tar.gz" \
            -C "$TEST_RESULTS_DIR" . || true
    fi
    
    # Clean up temporary resources
    rm -f "$TEST_RESULTS_DIR/.pid"
    rm -rf "$TEST_RESULTS_DIR/tmp"
    
    log_info "Cleanup completed with exit code: $exit_code"
    exit "$exit_code"
}

trap cleanup EXIT
trap 'exit 2' SIGINT SIGTERM SIGQUIT

# Environment validation function
check_environment() {
    log_info "Validating test environment..."
    
    # Check PHP and PHPUnit versions
    if ! command -v php >/dev/null; then
        log_error "PHP not found"
        return 1
    fi
    
    if ! command -v ./vendor/bin/phpunit >/dev/null; then
        log_error "PHPUnit not found"
        return 1
    }
    
    # Verify environment file
    if [ ! -f "$TEST_ENV_FILE" ]; then
        log_error "Test environment file not found: $TEST_ENV_FILE"
        return 1
    }
    
    # Source environment variables
    # shellcheck disable=SC1090
    source "$TEST_ENV_FILE"
    
    # Validate required environment variables
    local required_vars=("DB_HOST" "DB_NAME" "DB_USER" "DB_PASSWORD" "AWS_ENDPOINT" "REDIS_HOST")
    for var in "${required_vars[@]}"; do
        if [ -z "${!var:-}" ]; then
            log_error "Required environment variable not set: $var"
            return 1
        fi
    done
    
    # Check disk space
    local required_space=5120000  # 5GB in KB
    local available_space
    available_space=$(df -k "$TEST_RESULTS_DIR" | awk 'NR==2 {print $4}')
    if [ "$available_space" -lt "$required_space" ]; then
        log_error "Insufficient disk space for test execution"
        return 1
    }
    
    log_info "Environment validation completed successfully"
    return 0
}

# Test directory preparation
prepare_test_directories() {
    log_info "Preparing test directories..."
    
    # Create main directories
    mkdir -p "$TEST_RESULTS_DIR"/{reports,logs,coverage,tmp}
    mkdir -p "$COVERAGE_DIR"/{html,clover,crap4j}
    
    # Set permissions
    chmod -R 755 "$TEST_RESULTS_DIR"
    chmod -R 755 "$COVERAGE_DIR"
    
    # Initialize log file with rotation
    if [ -f "$LOG_FILE" ]; then
        if [ "$(stat -f%z "$LOG_FILE" 2>/dev/null || stat -c%s "$LOG_FILE")" -gt 104857600 ]; then
            mv "$LOG_FILE" "$LOG_FILE.$(date +%Y%m%d_%H%M%S)"
            gzip "$LOG_FILE".* &
        fi
    fi
    touch "$LOG_FILE"
    
    # Clean old test results (keeping last 5 runs)
    find "$TEST_RESULTS_DIR/reports" -type f -mtime +5 -delete
    
    log_info "Test directories prepared successfully"
    return 0
}

# Integration test execution
run_integration_tests() {
    log_info "Starting integration test execution..."
    
    # Store process ID for cleanup
    echo $$ > "$TEST_RESULTS_DIR/.pid"
    
    # Execute PHPUnit with coverage
    ./vendor/bin/phpunit \
        --configuration "$PHPUNIT_CONFIG" \
        --testsuite integration \
        --coverage-html "$COVERAGE_DIR/html" \
        --coverage-clover "$COVERAGE_DIR/clover/coverage.xml" \
        --coverage-crap4j "$COVERAGE_DIR/crap4j/crap4j.xml" \
        --log-junit "$TEST_RESULTS_DIR/reports/junit.xml" \
        --colors=always \
        --debug \
        --verbose \
        --fail-on-warning \
        --fail-on-risky \
        --strict-coverage \
        --disallow-test-output \
        --enforce-time-limit \
        --default-time-limit=30 \
        --parallel "$PARALLEL_JOBS" \
        2>&1 | tee -a "$LOG_FILE"
    
    local exit_code=${PIPESTATUS[0]}
    
    # Remove PID file
    rm -f "$TEST_RESULTS_DIR/.pid"
    
    return "$exit_code"
}

# Test report generation
generate_test_report() {
    log_info "Generating test reports..."
    
    # Generate HTML report index
    cat > "$TEST_RESULTS_DIR/reports/index.html" << EOF
<!DOCTYPE html>
<html>
<head><title>Integration Test Results</title></head>
<body>
<h1>Integration Test Results</h1>
<p>Generated: $(date)</p>
<ul>
    <li><a href="../../coverage/html/index.html">Coverage Report</a></li>
    <li><a href="junit.xml">JUnit Report</a></li>
</ul>
</body>
</html>
EOF
    
    # Generate coverage badges if available
    if command -v coverage-badge >/dev/null; then
        coverage-badge -o "$TEST_RESULTS_DIR/reports/coverage.svg" \
            "$COVERAGE_DIR/clover/coverage.xml"
    fi
    
    log_info "Test reports generated successfully"
    return 0
}

# Environment cleanup
cleanup_environment() {
    log_info "Cleaning up test environment..."
    
    # Clean temporary files
    rm -rf "$TEST_RESULTS_DIR/tmp"/*
    
    # Archive logs
    find "$TEST_RESULTS_DIR/logs" -type f -name "*.log" -mtime +7 -exec gzip {} \;
    
    # Compress old reports
    find "$TEST_RESULTS_DIR/reports" -type f -name "*.xml" -mtime +7 -exec gzip {} \;
    
    log_info "Environment cleanup completed"
    return 0
}

# Main execution function
main() {
    local exit_code=0
    
    log_info "Starting integration test suite execution"
    
    # Check environment with retries
    local retry_count=0
    while [ $retry_count -lt $MAX_RETRIES ]; do
        if check_environment; then
            break
        fi
        retry_count=$((retry_count + 1))
        log_warn "Environment check failed, attempt $retry_count of $MAX_RETRIES"
        sleep 5
    done
    
    if [ $retry_count -eq $MAX_RETRIES ]; then
        log_error "Environment validation failed after $MAX_RETRIES attempts"
        return 1
    fi
    
    # Prepare directories
    if ! prepare_test_directories; then
        log_error "Failed to prepare test directories"
        return 2
    fi
    
    # Run tests
    if ! run_integration_tests; then
        exit_code=3
        log_error "Integration tests failed"
    fi
    
    # Generate reports regardless of test result
    if ! generate_test_report; then
        log_error "Failed to generate test reports"
        [ $exit_code -eq 0 ] && exit_code=4
    fi
    
    # Cleanup
    if ! cleanup_environment; then
        log_error "Environment cleanup failed"
        [ $exit_code -eq 0 ] && exit_code=5
    fi
    
    log_info "Integration test suite completed with exit code: $exit_code"
    return $exit_code
}

# Script execution
main "$@"