#!/bin/bash

# Terraform Infrastructure Apply Script
# Version: 1.0.0
# Description: Applies Terraform infrastructure changes with comprehensive validation,
#              security checks, logging, and rollback capabilities
# Dependencies: 
# - terraform (1.5.x)
# - aws-cli (2.x)
# - jq (1.6+)

# Enable strict error handling
set -euo pipefail
[[ "${TRACE:-0}" == "1" ]] && set -x

# Source required scripts
source "$(dirname "${BASH_SOURCE[0]}")/setup-aws-credentials.sh"
source "$(dirname "${BASH_SOURCE[0]}")/init-terraform.sh"

# Global constants
readonly SCRIPT_DIR=$(dirname "${BASH_SOURCE[0]}")
readonly TERRAFORM_DIR="${SCRIPT_DIR}/../terraform"
readonly VALID_ENVIRONMENTS=('prod' 'staging' 'dev')
readonly AUTO_APPROVE=false
readonly LOG_DIR="${SCRIPT_DIR}/../logs"
readonly BACKUP_DIR="${SCRIPT_DIR}/../backups"
readonly LOCK_FILE="/tmp/terraform-apply.lock"
readonly PLAN_FILE="tfplan"
readonly CHECKSUM_FILE="tfplan.sha256"

# Logging function
log() {
    local level="$1"
    local message="$2"
    local timestamp
    timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    echo "[${timestamp}] [${level}] ${message}" | tee -a "${log_file}"
}

# Error handler
error_handler() {
    local exit_code=$?
    local line_number=$1
    log "ERROR" "Error occurred in script at line ${line_number}, exit code ${exit_code}"
    
    if [[ -f "${LOCK_FILE}" ]]; then
        log "INFO" "Attempting rollback due to error..."
        handle_rollback "${environment}" "${log_file}"
    fi
    
    cleanup_temporary_files
    exit "${exit_code}"
}

trap 'error_handler ${LINENO}' ERR

# Validate environment
validate_environment() {
    local environment="$1"
    log "INFO" "Validating environment: ${environment}"
    
    # Check if environment is valid
    if [[ ! " ${VALID_ENVIRONMENTS[*]} " =~ ${environment} ]]; then
        log "ERROR" "Invalid environment: ${environment}"
        return 1
    }
    
    # Validate AWS credentials
    validate_aws_credentials || return 1
    
    # Setup AWS profile
    setup_aws_profile "${environment}" || return 1
    
    # Validate AWS permissions
    validate_aws_permissions || return 1
    
    # Check if environment configuration exists
    if [[ ! -d "${TERRAFORM_DIR}/environments/${environment}" ]]; then
        log "ERROR" "Environment configuration not found: ${environment}"
        return 1
    }
    
    log "INFO" "Environment validation completed successfully"
    return 0
}

# Setup logging
setup_logging() {
    local environment="$1"
    local timestamp
    timestamp=$(date -u +"%Y%m%d_%H%M%S")
    
    # Create log directory with proper permissions
    mkdir -p "${LOG_DIR}/${environment}"
    chmod 750 "${LOG_DIR}/${environment}"
    
    # Initialize log file
    local log_file="${LOG_DIR}/${environment}/terraform-apply-${timestamp}.log"
    touch "${log_file}"
    chmod 640 "${log_file}"
    
    # Configure log rotation
    if [[ -f "${log_file}" ]] && [[ $(stat -f%z "${log_file}") -gt 104857600 ]]; then
        mv "${log_file}" "${log_file}.${timestamp}"
        gzip "${log_file}.${timestamp}"
    fi
    
    echo "${log_file}"
}

# Generate and validate Terraform plan
terraform_plan() {
    local environment="$1"
    local log_file="$2"
    
    log "INFO" "Generating Terraform plan for environment: ${environment}"
    
    # Change to environment directory
    cd "${TERRAFORM_DIR}/environments/${environment}"
    
    # Create state backup
    terraform state pull > "${BACKUP_DIR}/${environment}-$(date -u +"%Y%m%d_%H%M%S").tfstate"
    
    # Initialize Terraform
    init_terraform "${environment}" || return 1
    
    # Generate plan
    terraform plan \
        -detailed-exitcode \
        -input=false \
        -out="${PLAN_FILE}" \
        -var-file="terraform.tfvars" || {
            local exit_code=$?
            if [[ ${exit_code} -eq 2 ]]; then
                log "INFO" "Changes detected in plan"
            else
                log "ERROR" "Plan generation failed"
                return 1
            fi
        }
    
    # Generate plan checksum
    sha256sum "${PLAN_FILE}" > "${CHECKSUM_FILE}"
    
    # Validate plan against security policies
    terraform show -json "${PLAN_FILE}" | \
        jq -r '.resource_changes[] | select(.change.actions[] | contains("delete"))' > deleted_resources.json
    
    if [[ -s deleted_resources.json ]]; then
        log "WARN" "Plan includes resource deletions. Please review:"
        cat deleted_resources.json | tee -a "${log_file}"
        if [[ "${environment}" == "prod" ]]; then
            log "ERROR" "Production resource deletion requires manual approval"
            return 1
        fi
    fi
    
    log "INFO" "Plan generation completed successfully"
    return 0
}

# Apply Terraform changes
terraform_apply() {
    local environment="$1"
    local log_file="$2"
    
    log "INFO" "Applying Terraform changes for environment: ${environment}"
    
    # Acquire deployment lock
    if ! mkdir "${LOCK_FILE}" 2>/dev/null; then
        log "ERROR" "Another deployment is in progress"
        return 1
    fi
    
    # Verify plan checksum
    if ! sha256sum -c "${CHECKSUM_FILE}"; then
        log "ERROR" "Plan file has been modified"
        rm -f "${LOCK_FILE}"
        return 1
    }
    
    # Apply plan
    if [[ "${AUTO_APPROVE}" == "true" ]]; then
        terraform apply -input=false -auto-approve "${PLAN_FILE}"
    else
        terraform apply -input=false "${PLAN_FILE}"
    fi
    
    # Verify apply success
    terraform plan -detailed-exitcode -input=false >/dev/null || {
        local exit_code=$?
        if [[ ${exit_code} -eq 2 ]]; then
            log "ERROR" "Apply verification failed - drift detected"
            return 1
        fi
    }
    
    # Update state backup
    terraform state pull > "${BACKUP_DIR}/${environment}-$(date -u +"%Y%m%d_%H%M%S")-post-apply.tfstate"
    
    # Release lock
    rm -f "${LOCK_FILE}"
    
    log "INFO" "Terraform apply completed successfully"
    return 0
}

# Handle rollback
handle_rollback() {
    local environment="$1"
    local log_file="$2"
    
    log "WARN" "Initiating rollback procedure"
    
    # Find latest state backup
    local latest_backup
    latest_backup=$(ls -t "${BACKUP_DIR}/${environment}-"*.tfstate 2>/dev/null | head -n 1)
    
    if [[ -n "${latest_backup}" ]]; then
        log "INFO" "Rolling back to state: ${latest_backup}"
        
        # Verify backup integrity
        if ! terraform show "${latest_backup}" >/dev/null 2>&1; then
            log "ERROR" "Backup state file is corrupted"
            return 1
        }
        
        # Push backup state
        terraform state push "${latest_backup}"
        
        # Verify rollback
        terraform plan -detailed-exitcode -input=false >/dev/null || {
            local exit_code=$?
            if [[ ${exit_code} -eq 2 ]]; then
                log "ERROR" "Rollback verification failed"
                return 1
            fi
        }
        
        log "INFO" "Rollback completed successfully"
        return 0
    else
        log "ERROR" "No state backup found for rollback"
        return 1
    fi
}

# Cleanup temporary files
cleanup_temporary_files() {
    log "INFO" "Cleaning up temporary files"
    
    rm -f "${PLAN_FILE}" "${CHECKSUM_FILE}" deleted_resources.json
    [[ -d "${LOCK_FILE}" ]] && rm -rf "${LOCK_FILE}"
    
    # Cleanup old backups (older than 30 days)
    find "${BACKUP_DIR}" -name "*.tfstate" -type f -mtime +30 -delete
    
    log "INFO" "Cleanup completed"
}

# Main execution
main() {
    if [[ $# -ne 1 ]]; then
        echo "Usage: $0 <environment>"
        echo "Environment must be one of: ${VALID_ENVIRONMENTS[*]}"
        exit 1
    fi
    
    local environment="$1"
    local log_file
    
    # Setup logging
    log_file=$(setup_logging "${environment}")
    
    log "INFO" "Starting Terraform apply for environment: ${environment}"
    
    # Validate environment
    validate_environment "${environment}" || exit 1
    
    # Generate and validate plan
    terraform_plan "${environment}" "${log_file}" || exit 1
    
    # Apply changes
    terraform_apply "${environment}" "${log_file}" || exit 1
    
    # Cleanup
    cleanup_temporary_files
    
    log "INFO" "Terraform apply completed successfully"
}

# Execute main function
main "$@"