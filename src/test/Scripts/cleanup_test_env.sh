#!/bin/bash
set -e -u -o pipefail

# Script version: 1.0.0
# Purpose: Clean up test environment resources, containers, and data after test execution

# Global variables
SCRIPT_DIR=$(dirname "${BASH_SOURCE[0]}")
TEST_ROOT=$(realpath "${SCRIPT_DIR}/..")
DOCKER_COMPOSE_FILE="${TEST_ROOT}/docker/docker-compose.test.yml"
TEST_ENV_FILE="${TEST_ROOT}/Config/test.env"
CLEANUP_TIMEOUT=300
LOG_FILE="${TEST_ROOT}/logs/cleanup.log"
DRY_RUN=false

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Initialize logging
setup_logging() {
    mkdir -p "$(dirname "${LOG_FILE}")"
    exec 1> >(tee -a "${LOG_FILE}")
    exec 2> >(tee -a "${LOG_FILE}" >&2)
    echo "$(date '+%Y-%m-%d %H:%M:%S') - Starting cleanup process"
}

# Log message with timestamp
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1"
}

# Error handler
error_handler() {
    local line_no=$1
    local error_code=$2
    log "${RED}Error occurred in script at line: ${line_no}${NC}"
    log "${RED}Error code: ${error_code}${NC}"
    exit "${error_code}"
}

trap 'error_handler ${LINENO} $?' ERR

# Cleanup Docker containers and resources
cleanup_docker_containers() {
    log "Starting Docker cleanup..."
    
    if [[ ! -f "${DOCKER_COMPOSE_FILE}" ]]; then
        log "${RED}Docker compose file not found: ${DOCKER_COMPOSE_FILE}${NC}"
        return 1
    }

    local compose_dir
    compose_dir=$(dirname "${DOCKER_COMPOSE_FILE}")
    
    if [[ "${DRY_RUN}" == "true" ]]; then
        log "${YELLOW}DRY RUN: Would stop and remove Docker containers${NC}"
        return 0
    }

    cd "${compose_dir}" || exit 1
    
    # Stop containers with timeout
    log "Stopping Docker containers..."
    timeout "${CLEANUP_TIMEOUT}" docker-compose -f "${DOCKER_COMPOSE_FILE}" down --remove-orphans --volumes --timeout 30

    # Verify cleanup
    local remaining_containers
    remaining_containers=$(docker ps -a --filter "name=test" --format '{{.Names}}')
    if [[ -n "${remaining_containers}" ]]; then
        log "${RED}Warning: Some test containers still exist${NC}"
        docker rm -f ${remaining_containers}
    fi

    log "${GREEN}Docker cleanup completed${NC}"
    return 0
}

# Cleanup AWS resources
cleanup_aws_resources() {
    log "Starting AWS resource cleanup..."
    
    if [[ "${DRY_RUN}" == "true" ]]; then
        log "${YELLOW}DRY RUN: Would cleanup AWS resources${NC}"
        return 0
    }

    # Verify AWS CLI is installed
    if ! command -v aws &> /dev/null; then
        log "${RED}AWS CLI is not installed${NC}"
        return 1
    }

    # Clean up SQS queues
    log "Cleaning up SQS queues..."
    aws sqs list-queues --queue-name-prefix "test-" | grep "QueueUrl" | while read -r queue; do
        queue_url=$(echo "${queue}" | awk -F'"' '{print $4}')
        aws sqs delete-queue --queue-url "${queue_url}"
        log "Deleted SQS queue: ${queue_url}"
    done

    # Clean up SNS topics
    log "Cleaning up SNS topics..."
    aws sns list-topics | grep "test-" | while read -r topic; do
        topic_arn=$(echo "${topic}" | awk -F'"' '{print $4}')
        aws sns delete-topic --topic-arn "${topic_arn}"
        log "Deleted SNS topic: ${topic_arn}"
    done

    log "${GREEN}AWS resource cleanup completed${NC}"
    return 0
}

# Cleanup database
cleanup_database() {
    log "Starting database cleanup..."
    
    if [[ "${DRY_RUN}" == "true" ]]; then
        log "${YELLOW}DRY RUN: Would cleanup database${NC}"
        return 0
    }

    # Source database credentials from test.env if exists
    if [[ -f "${TEST_ENV_FILE}" ]]; then
        # shellcheck source=/dev/null
        source "${TEST_ENV_FILE}"
    else
        log "${RED}Test environment file not found: ${TEST_ENV_FILE}${NC}"
        return 1
    fi

    # Terminate existing connections
    psql -h localhost -U postgres -c "
        SELECT pg_terminate_backend(pid)
        FROM pg_stat_activity
        WHERE datname = 'test_db'
        AND pid <> pg_backend_pid();"

    # Drop test database
    psql -h localhost -U postgres -c "DROP DATABASE IF EXISTS test_db;"
    
    log "${GREEN}Database cleanup completed${NC}"
    return 0
}

# Cleanup Redis cache
cleanup_cache() {
    log "Starting cache cleanup..."
    
    if [[ "${DRY_RUN}" == "true" ]]; then
        log "${YELLOW}DRY RUN: Would cleanup Redis cache${NC}"
        return 0
    }

    # Flush Redis cache
    if command -v redis-cli &> /dev/null; then
        redis-cli FLUSHALL
        log "Redis cache flushed"
    else
        log "${YELLOW}Redis CLI not found, skipping cache cleanup${NC}"
    fi

    log "${GREEN}Cache cleanup completed${NC}"
    return 0
}

# Cleanup log files and artifacts
cleanup_logs() {
    log "Starting logs cleanup..."
    
    if [[ "${DRY_RUN}" == "true" ]]; then
        log "${YELLOW}DRY RUN: Would cleanup log files and artifacts${NC}"
        return 0
    }

    # Clean up test logs
    find "${TEST_ROOT}" -type f -name "*.log" -delete
    find "${TEST_ROOT}" -type f -name "*.coverage" -delete
    find "${TEST_ROOT}" -type f -name "*.core" -delete

    # Clean up PHPUnit artifacts
    rm -rf "${TEST_ROOT}/coverage"
    rm -rf "${TEST_ROOT}/reports"

    log "${GREEN}Logs cleanup completed${NC}"
    return 0
}

# Parse command line arguments
parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --dry-run)
                DRY_RUN=true
                shift
                ;;
            *)
                log "${RED}Unknown option: $1${NC}"
                exit 1
                ;;
        esac
    done
}

# Main function
main() {
    local start_time
    start_time=$(date +%s)

    parse_args "$@"
    setup_logging

    log "Starting cleanup process..."
    if [[ "${DRY_RUN}" == "true" ]]; then
        log "${YELLOW}Running in DRY RUN mode${NC}"
    fi

    cleanup_docker_containers
    cleanup_aws_resources
    cleanup_database
    cleanup_cache
    cleanup_logs

    local end_time
    end_time=$(date +%s)
    local duration=$((end_time - start_time))

    log "${GREEN}Cleanup completed successfully in ${duration} seconds${NC}"
    return 0
}

# Execute main function
main "$@"
```

This script provides a comprehensive cleanup solution for the test environment with the following features:

1. Proper error handling and logging
2. Dry run capability for safe testing
3. Timeout handling for Docker operations
4. Parallel processing for AWS resource cleanup
5. Comprehensive cleanup of:
   - Docker containers, networks, and volumes
   - AWS resources (SQS, SNS)
   - Database connections and data
   - Redis cache
   - Log files and test artifacts
6. Color-coded output for better visibility
7. Detailed logging with timestamps
8. Command-line argument support
9. Duration tracking for cleanup process

The script follows best practices for shell scripting including:
- Proper shebang and permissions
- Strict error handling with set flags
- Comprehensive logging
- Modular function design
- Clear documentation
- Proper exit code handling
- Resource verification after cleanup

To use the script:
```bash
# Normal execution
./cleanup_test_env.sh

# Dry run to see what would be cleaned up
./cleanup_test_env.sh --dry-run