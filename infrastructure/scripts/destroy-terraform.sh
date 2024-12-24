#!/bin/bash

# Terraform Infrastructure Destruction Script
# Version: 1.0.0
# Description: Safely destroys AWS infrastructure with validation, backup, and confirmation steps
# Dependencies: terraform (>=1.5.0), aws-cli (>=2.0.0)

# Enable strict error handling
set -euo pipefail
[[ "${TRACE:-0}" == "1" ]] && set -x

# Source AWS credentials setup script
source "$(dirname "${BASH_SOURCE[0]}")/setup-aws-credentials.sh"

# Global constants
readonly SCRIPT_DIR="$(dirname "${BASH_SOURCE[0]}")"
readonly TERRAFORM_DIR="${SCRIPT_DIR}/../terraform"
readonly STATE_BACKUP_DIR="${SCRIPT_DIR}/../backups"
readonly LOG_DIR="${SCRIPT_DIR}/../logs"
readonly TIMESTAMP=$(date -u +"%Y%m%d_%H%M%S")
readonly LOG_FILE="${LOG_DIR}/terraform_destroy_${TIMESTAMP}.log"

# Logging function
log() {
    local level="$1"
    local message="$2"
    local timestamp
    timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    echo "[${timestamp}] [${level}] ${message}" | tee -a "${LOG_FILE}"
}

# Error handler
error_handler() {
    local exit_code=$?
    local line_number=$1
    log "ERROR" "Error occurred in script at line ${line_number}, exit code ${exit_code}"
    cleanup
    exit "${exit_code}"
}

# Set error handlers
trap 'error_handler ${LINENO}' ERR
trap cleanup EXIT SIGINT SIGTERM

# Validate environment and prerequisites
validate_environment() {
    local environment="$1"
    log "INFO" "Validating environment: ${environment}"

    # Validate environment name
    if [[ ! "${environment}" =~ ^(prod|staging|dev)$ ]]; then
        log "ERROR" "Invalid environment: ${environment}. Must be prod, staging, or dev"
        return 1
    }

    # Check required environment variables
    local required_vars=("AWS_PROFILE" "AWS_REGION" "TF_LOG_PATH" "TF_WORKSPACE")
    for var in "${required_vars[@]}"; do
        if [[ -z "${!var:-}" ]]; then
            log "ERROR" "Required environment variable ${var} is not set"
            return 1
        fi
    done

    # Validate AWS credentials
    if ! validate_aws_credentials "${AWS_PROFILE}-${environment}"; then
        log "ERROR" "AWS credentials validation failed"
        return 1
    }

    # Check Terraform version
    local tf_version
    tf_version=$(terraform version -json | jq -r '.terraform_version')
    if ! [[ "${tf_version}" =~ ^1\.[5-9]\. ]]; then
        log "ERROR" "Terraform version >= 1.5.0 required, found: ${tf_version}"
        return 1
    }

    # Verify Terraform directory exists
    if [[ ! -d "${TERRAFORM_DIR}" ]]; then
        log "ERROR" "Terraform directory not found: ${TERRAFORM_DIR}"
        return 1
    }

    log "INFO" "Environment validation completed successfully"
    return 0
}

# Create state backup
backup_state() {
    local environment="$1"
    log "INFO" "Creating state backup for environment: ${environment}"

    # Create backup directory
    mkdir -p "${STATE_BACKUP_DIR}/${environment}"
    
    local backup_file="${STATE_BACKUP_DIR}/${environment}/terraform_${TIMESTAMP}.tfstate"
    
    # Copy current state
    if terraform state pull > "${backup_file}"; then
        # Verify backup integrity
        if jq empty "${backup_file}" >/dev/null 2>&1; then
            log "INFO" "State backup created successfully: ${backup_file}"
            echo "${backup_file}"
            return 0
        fi
    fi
    
    log "ERROR" "Failed to create state backup"
    return 1
}

# Confirm destruction
confirm_destruction() {
    local environment="$1"
    log "INFO" "Requesting destruction confirmation for environment: ${environment}"

    # Show warning message
    echo -e "\n⚠️  WARNING: You are about to destroy all resources in the ${environment} environment"
    echo -e "This action is IRREVERSIBLE and will delete all infrastructure resources.\n"

    # Show resources to be destroyed
    terraform plan -destroy -input=false -no-color

    # Additional confirmation for production
    if [[ "${environment}" == "prod" ]]; then
        echo -e "\n🚨 PRODUCTION ENVIRONMENT DESTRUCTION WARNING 🚨"
        echo "You are about to destroy the PRODUCTION environment."
        echo -e "This action will result in service downtime and data loss.\n"
        
        read -r -p "Type 'DESTROY-PRODUCTION' to confirm: " confirmation
        if [[ "${confirmation}" != "DESTROY-PRODUCTION" ]]; then
            log "INFO" "Production destruction cancelled by user"
            return 1
        fi
    fi

    # Final confirmation
    read -r -p "Are you absolutely sure you want to destroy ${environment}? [y/N] " response
    if [[ ! "${response}" =~ ^[Yy]$ ]]; then
        log "INFO" "Destruction cancelled by user"
        return 1
    fi

    return 0
}

# Execute infrastructure destruction
destroy_infrastructure() {
    local environment="$1"
    log "INFO" "Starting infrastructure destruction for environment: ${environment}"

    # Initialize Terraform
    if ! terraform init -backend=true -reconfigure; then
        log "ERROR" "Terraform initialization failed"
        return 1
    fi

    # Select workspace
    if ! terraform workspace select "${environment}"; then
        log "ERROR" "Failed to select Terraform workspace: ${environment}"
        return 1
    }

    # Execute destroy
    if terraform destroy -auto-approve -input=false; then
        log "INFO" "Infrastructure destruction completed successfully"
        return 0
    else
        log "ERROR" "Infrastructure destruction failed"
        return 1
    fi
}

# Cleanup function
cleanup() {
    log "INFO" "Performing cleanup"

    # Archive logs
    if [[ -f "${LOG_FILE}" ]]; then
        mkdir -p "${LOG_DIR}/archive"
        gzip -c "${LOG_FILE}" > "${LOG_DIR}/archive/terraform_destroy_${TIMESTAMP}.log.gz"
    fi

    # Clean Terraform temporary files
    find "${TERRAFORM_DIR}" -name ".terraform.lock.hcl" -delete 2>/dev/null || true
    find "${TERRAFORM_DIR}" -name "crash.log" -delete 2>/dev/null || true
    find "${TERRAFORM_DIR}" -name ".terraform" -type d -exec rm -rf {} + 2>/dev/null || true

    log "INFO" "Cleanup completed"
}

# Main execution
main() {
    local environment="${1:-}"

    # Create log directory
    mkdir -p "${LOG_DIR}"

    # Validate input
    if [[ -z "${environment}" ]]; then
        log "ERROR" "Environment parameter is required"
        echo "Usage: $0 <environment>"
        echo "Environments: prod, staging, dev"
        exit 1
    fi

    # Change to Terraform directory
    cd "${TERRAFORM_DIR}"

    # Execute destruction process
    if validate_environment "${environment}" && \
       backup_state "${environment}" && \
       confirm_destruction "${environment}" && \
       destroy_infrastructure "${environment}"; then
        log "SUCCESS" "Infrastructure destruction completed successfully for ${environment}"
        exit 0
    else
        log "ERROR" "Infrastructure destruction failed for ${environment}"
        exit 1
    fi
}

# Execute main function with all arguments
main "$@"
```

This implementation follows enterprise-grade practices with:

1. Comprehensive error handling and logging
2. Multiple safety checks and validations
3. State backup before destruction
4. Enhanced production safeguards
5. Proper AWS credential validation
6. Cleanup of temporary files
7. Detailed logging and archival
8. Terraform workspace management
9. Resource verification before destruction

The script requires proper permissions and environment setup as specified in the technical specification. It integrates with the AWS credentials setup script and follows the infrastructure deployment patterns defined in the specification.

Usage:
```bash
chmod 755 destroy-terraform.sh
./destroy-terraform.sh <environment>