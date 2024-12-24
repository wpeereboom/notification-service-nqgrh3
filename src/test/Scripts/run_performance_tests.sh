#!/bin/bash

# Performance test script for notification service
# Validates key requirements:
# - 100,000+ messages per minute throughput
# - 99.9% delivery success rate
# - < 30 seconds processing latency (95th percentile)

set -e

# Configuration
TEST_ENV=${TEST_ENV:-"test"}
TARGET_RPS=${TARGET_RPS:-"1667"} # 100k per minute
TEST_DURATION=${TEST_DURATION:-"600"} # 10 minutes
REPORT_DIR=${REPORT_DIR:-"./reports/performance"}
PARALLEL_WORKERS=${PARALLEL_WORKERS:-"4"}
METRICS_INTERVAL=${METRICS_INTERVAL:-"5"}
SUCCESS_THRESHOLD=${SUCCESS_THRESHOLD:-"99.9"}
LATENCY_THRESHOLD=${LATENCY_THRESHOLD:-"30000"} # 30 seconds in ms

# Required tools
REQUIRED_TOOLS=("k6" "jq" "php")

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Setup environment and validate dependencies
setup_environment() {
    echo -e "${YELLOW}Setting up test environment...${NC}"

    # Check required tools
    for tool in "${REQUIRED_TOOLS[@]}"; do
        if ! command -v "$tool" &> /dev/null; then
            echo -e "${RED}Error: Required tool '$tool' is not installed${NC}"
            exit 1
        fi
    done

    # Create report directory
    mkdir -p "${REPORT_DIR}"/{metrics,logs,reports}

    # Load environment variables
    if [ -f "test.env" ]; then
        source test.env
    fi

    # Initialize vendor simulators
    php vendor/bin/phpunit --bootstrap vendor/autoload.php \
        --filter "TestHelper::setupTestEnvironment" \
        tests/Utils/TestHelper.php

    echo -e "${GREEN}Environment setup complete${NC}"
}

# Run load tests with parallel execution
run_load_tests() {
    echo -e "${YELLOW}Starting load tests...${NC}"

    # Start metrics collection daemon
    start_metrics_collector

    # Run notification throughput test
    echo "Running notification throughput test..."
    k6 run \
        --vus "${PARALLEL_WORKERS}" \
        --duration "${TEST_DURATION}s" \
        --rps "${TARGET_RPS}" \
        --tag testid="notification_throughput" \
        k6.config.js

    # Run template rendering test
    echo "Running template rendering test..."
    php vendor/bin/phpunit \
        --filter "testTemplateRenderingPerformance" \
        --log-junit "${REPORT_DIR}/reports/template_performance.xml" \
        tests/Performance/LoadTest/TemplateLoadTest.php

    # Run vendor failover test
    echo "Running vendor failover test..."
    php vendor/bin/phpunit \
        --filter "testVendorFailover" \
        --log-junit "${REPORT_DIR}/reports/vendor_failover.xml" \
        tests/Performance/LoadTest/VendorLoadTest.php

    echo -e "${GREEN}Load tests completed${NC}"
}

# Run stress tests with increasing load
run_stress_tests() {
    echo -e "${YELLOW}Starting stress tests...${NC}"

    # Calculate stress test parameters
    local max_vus=$((PARALLEL_WORKERS * 2))
    local ramp_duration=$((TEST_DURATION / 4))

    # Run k6 stress test with ramping VUs
    k6 run \
        --vus "${PARALLEL_WORKERS}" \
        --stage "0s:${PARALLEL_WORKERS},${ramp_duration}s:${max_vus}" \
        --stage "${ramp_duration}s:${max_vus},${TEST_DURATION}s:${PARALLEL_WORKERS}" \
        --tag testid="stress_test" \
        k6.config.js

    echo -e "${GREEN}Stress tests completed${NC}"
}

# Start metrics collection in background
start_metrics_collector() {
    # Start collecting system metrics
    while true; do
        collect_system_metrics >> "${REPORT_DIR}/metrics/system_metrics.log" &
        sleep "${METRICS_INTERVAL}"
    done &
    METRICS_PID=$!
}

# Collect system metrics
collect_system_metrics() {
    local timestamp=$(date +%s)
    local cpu_usage=$(top -bn1 | grep "Cpu(s)" | awk '{print $2}')
    local mem_usage=$(free -m | awk '/Mem:/ {print $3}')
    
    echo "{\"timestamp\":${timestamp},\"cpu_usage\":${cpu_usage},\"memory_usage\":${mem_usage}}"
}

# Generate comprehensive test reports
generate_reports() {
    echo -e "${YELLOW}Generating test reports...${NC}"

    # Aggregate test results
    jq -s '.' "${REPORT_DIR}"/metrics/*.log > "${REPORT_DIR}/reports/metrics_summary.json"

    # Calculate success rates
    local success_rate=$(jq -r '[.[] | select(.status=="success")] | length as $successes | length as $total | ($successes/$total*100)' "${REPORT_DIR}/reports/metrics_summary.json")
    
    # Calculate latency percentiles
    local p95_latency=$(jq -r '[.[] | .latency] | sort | .[(length * 0.95 | floor)]' "${REPORT_DIR}/reports/metrics_summary.json")

    # Generate HTML report
    cat > "${REPORT_DIR}/reports/performance_report.html" << EOF
<!DOCTYPE html>
<html>
<head><title>Performance Test Report</title></head>
<body>
    <h1>Performance Test Results</h1>
    <h2>Key Metrics</h2>
    <ul>
        <li>Success Rate: ${success_rate}%</li>
        <li>P95 Latency: ${p95_latency}ms</li>
    </ul>
</body>
</html>
EOF

    echo -e "${GREEN}Reports generated successfully${NC}"
}

# Cleanup test environment
cleanup() {
    echo -e "${YELLOW}Cleaning up test environment...${NC}"

    # Stop metrics collector
    if [ ! -z "${METRICS_PID}" ]; then
        kill "${METRICS_PID}" || true
    fi

    # Reset vendor simulators
    php vendor/bin/phpunit --bootstrap vendor/autoload.php \
        --filter "TestHelper::cleanupTestEnvironment" \
        tests/Utils/TestHelper.php

    # Archive test results
    local archive_name="performance_test_results_$(date +%Y%m%d_%H%M%S).tar.gz"
    tar -czf "${REPORT_DIR}/${archive_name}" -C "${REPORT_DIR}" .

    echo -e "${GREEN}Cleanup completed${NC}"
}

# Main execution
main() {
    # Trap cleanup on exit
    trap cleanup EXIT

    # Execute test phases
    setup_environment
    run_load_tests
    run_stress_tests
    generate_reports

    # Validate test results
    local success_rate=$(jq -r '.success_rate' "${REPORT_DIR}/reports/metrics_summary.json")
    local p95_latency=$(jq -r '.p95_latency' "${REPORT_DIR}/reports/metrics_summary.json")

    if (( $(echo "$success_rate < $SUCCESS_THRESHOLD" | bc -l) )); then
        echo -e "${RED}Error: Success rate ${success_rate}% below threshold ${SUCCESS_THRESHOLD}%${NC}"
        exit 1
    fi

    if (( $(echo "$p95_latency > $LATENCY_THRESHOLD" | bc -l) )); then
        echo -e "${RED}Error: P95 latency ${p95_latency}ms exceeds threshold ${LATENCY_THRESHOLD}ms${NC}"
        exit 1
    fi

    echo -e "${GREEN}All performance tests completed successfully${NC}"
}

# Execute main function
main "$@"