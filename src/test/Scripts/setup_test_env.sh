#!/usr/bin/env bash

# setup_test_env.sh
# Version: 1.0.0
# Description: Initializes and configures test environment for notification service testing
# Author: Notification Service Team

# Exit on error, undefined variables, and pipe failures
set -euo pipefail

# Global variables
SCRIPT_DIR=$(dirname "${BASH_SOURCE[0]}")
PROJECT_ROOT=$(realpath "$SCRIPT_DIR/../../")
TEST_ENV_FILE="$PROJECT_ROOT/test/Config/test.env"
DOCKER_COMPOSE_FILE="$PROJECT_ROOT/test/docker/docker-compose.test.yml"
LOG_FILE="$PROJECT_ROOT/test/logs/setup.log"
REQUIRED_PORTS="5432,6379,4566,4571"
MIN_DISK_SPACE=5120  # MB
TIMEOUT=300  # seconds

# Logging functions
setup_logging() {
    mkdir -p "$(dirname "$LOG_FILE")"
    exec 1> >(tee -a "$LOG_FILE")
    exec 2> >(tee -a "$LOG_FILE" >&2)
}

log() {
    local level=$1
    shift
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] [$level] $*"
}

log_debug() { log "DEBUG" "$@"; }
log_info() { log "INFO" "$@"; }
log_warn() { log "WARN" "$@"; }
log_error() { log "ERROR" "$@"; }
log_fatal() { log "FATAL" "$@"; exit 1; }

# Cleanup function
cleanup() {
    log_info "Initiating cleanup..."
    
    # Stop containers gracefully
    if [[ -f "$DOCKER_COMPOSE_FILE" ]]; then
        docker-compose -f "$DOCKER_COMPOSE_FILE" down --volumes --remove-orphans || true
    fi
    
    # Remove temporary files
    rm -rf "$PROJECT_ROOT/test/tmp" || true
    
    # Archive logs if they exist
    if [[ -f "$LOG_FILE" ]]; then
        mkdir -p "$PROJECT_ROOT/test/logs/archive"
        mv "$LOG_FILE" "$PROJECT_ROOT/test/logs/archive/setup_$(date +%Y%m%d_%H%M%S).log"
    fi
    
    log_info "Cleanup completed"
}

# Set up signal traps
trap cleanup EXIT SIGINT SIGTERM SIGQUIT

# Check prerequisites
check_prerequisites() {
    log_info "Checking prerequisites..."
    
    # Check Docker installation
    if ! command -v docker >/dev/null 2>&1; then
        log_fatal "Docker is not installed"
    fi
    
    # Check Docker Compose installation
    if ! command -v docker-compose >/dev/null 2>&1; then
        log_fatal "Docker Compose is not installed"
    fi
    
    # Check Docker version
    local docker_version
    docker_version=$(docker --version | cut -d' ' -f3 | cut -d'.' -f1)
    if ((docker_version < 20)); then
        log_fatal "Docker version must be 20.0 or higher"
    fi
    
    # Check port availability
    IFS=',' read -ra PORTS <<< "$REQUIRED_PORTS"
    for port in "${PORTS[@]}"; do
        if lsof -i :"$port" >/dev/null 2>&1; then
            log_fatal "Port $port is already in use"
        fi
    done
    
    # Check disk space
    local available_space
    available_space=$(df -m "$PROJECT_ROOT" | awk 'NR==2 {print $4}')
    if ((available_space < MIN_DISK_SPACE)); then
        log_fatal "Insufficient disk space. Required: ${MIN_DISK_SPACE}MB, Available: ${available_space}MB"
    fi
    
    # Check Docker socket permissions
    if ! docker info >/dev/null 2>&1; then
        log_fatal "Unable to access Docker socket. Check permissions"
    fi
    
    # Verify config files
    if [[ ! -f "$DOCKER_COMPOSE_FILE" ]]; then
        log_fatal "Docker Compose file not found: $DOCKER_COMPOSE_FILE"
    fi
    
    log_info "Prerequisites check passed"
    return 0
}

# Set up environment
setup_environment() {
    log_info "Setting up test environment..."
    
    # Create required directories
    mkdir -p "$PROJECT_ROOT/test/"{data,logs,tmp}/{postgres,redis,localstack}
    
    # Create test.env if not exists
    if [[ ! -f "$TEST_ENV_FILE" ]]; then
        cat > "$TEST_ENV_FILE" << EOF
# Test Environment Configuration
POSTGRES_HOST=localhost
POSTGRES_PORT=5432
POSTGRES_DB=notification_test
POSTGRES_USER=test_user
POSTGRES_PASSWORD=test_password

REDIS_HOST=localhost
REDIS_PORT=6379

LOCALSTACK_HOSTNAME=localhost
AWS_DEFAULT_REGION=us-east-1
AWS_ACCESS_KEY_ID=test
AWS_SECRET_ACCESS_KEY=test
EOF
    fi
    
    # Set proper permissions
    chmod 600 "$TEST_ENV_FILE"
    
    log_info "Environment setup completed"
    return 0
}

# Start services
start_services() {
    log_info "Starting services..."
    
    # Pull required images
    docker-compose -f "$DOCKER_COMPOSE_FILE" pull
    
    # Start services
    docker-compose -f "$DOCKER_COMPOSE_FILE" up -d
    
    # Wait for PostgreSQL
    local retries=0
    while ! docker-compose -f "$DOCKER_COMPOSE_FILE" exec -T postgres pg_isready -U test_user >/dev/null 2>&1; do
        ((retries++))
        if ((retries > TIMEOUT)); then
            log_fatal "PostgreSQL failed to start within timeout"
        fi
        sleep 1
    done
    
    # Wait for Redis
    retries=0
    while ! docker-compose -f "$DOCKER_COMPOSE_FILE" exec -T redis redis-cli ping >/dev/null 2>&1; do
        ((retries++))
        if ((retries > TIMEOUT)); then
            log_fatal "Redis failed to start within timeout"
        fi
        sleep 1
    done
    
    # Wait for LocalStack
    retries=0
    while ! curl -s "http://localhost:4566/health" | grep -q "running"; do
        ((retries++))
        if ((retries > TIMEOUT)); then
            log_fatal "LocalStack failed to start within timeout"
        fi
        sleep 1
    done
    
    log_info "All services started successfully"
    return 0
}

# Initialize AWS resources
initialize_aws_resources() {
    log_info "Initializing AWS resources..."
    
    # Configure AWS CLI for LocalStack
    export AWS_ENDPOINT_URL="http://localhost:4566"
    
    # Create SQS queues
    aws --endpoint-url="$AWS_ENDPOINT_URL" sqs create-queue \
        --queue-name notification-test-queue \
        --attributes '{"VisibilityTimeout": "30"}' || log_fatal "Failed to create SQS queue"
        
    aws --endpoint-url="$AWS_ENDPOINT_URL" sqs create-queue \
        --queue-name notification-test-dlq \
        --attributes '{"MessageRetentionPeriod": "1209600"}' || log_fatal "Failed to create DLQ"
    
    # Create SNS topics
    aws --endpoint-url="$AWS_ENDPOINT_URL" sns create-topic \
        --name notification-test-topic || log_fatal "Failed to create SNS topic"
    
    # Create S3 bucket
    aws --endpoint-url="$AWS_ENDPOINT_URL" s3 mb \
        s3://notification-test-bucket || log_fatal "Failed to create S3 bucket"
    
    log_info "AWS resources initialized successfully"
    return 0
}

# Main execution
main() {
    setup_logging
    log_info "Starting test environment setup..."
    
    check_prerequisites || exit 1
    setup_environment || exit 2
    start_services || exit 3
    initialize_aws_resources || exit 4
    
    log_info "Test environment setup completed successfully"
    
    # Output environment details
    cat << EOF
========================================
Test Environment Ready
----------------------------------------
PostgreSQL: localhost:5432
Redis: localhost:6379
LocalStack: localhost:4566
----------------------------------------
Log file: $LOG_FILE
Environment file: $TEST_ENV_FILE
========================================
EOF
    
    return 0
}

# Execute main function
main "$@"