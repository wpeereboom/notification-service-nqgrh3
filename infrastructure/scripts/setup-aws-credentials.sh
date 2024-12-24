#!/bin/bash

# AWS Credential Configuration Script
# Version: 1.0.0
# Description: Configures AWS credentials and authentication for multi-environment infrastructure deployment
# Dependencies: aws-cli (2.x), jq (1.6+)

# Enable strict error handling and debugging
set -euo pipefail
[[ "${TRACE:-0}" == "1" ]] && set -x

# Global constants
readonly AWS_PROFILE_PREFIX="notification-service"
readonly AWS_PRIMARY_REGION="us-east-1"
readonly AWS_BACKUP_REGION="us-west-2"
readonly AWS_CONFIG_FILE="${HOME}/.aws/config"
readonly AWS_CREDENTIALS_FILE="${HOME}/.aws/credentials"
readonly LOG_FILE="/var/log/aws-credential-setup.log"
readonly BACKUP_DIR="${HOME}/.aws/backups"
readonly AUDIT_LOG="/var/log/aws-credential-audit.log"

# Logging function with timestamp and log level
log() {
    local level="$1"
    local message="$2"
    local timestamp
    timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    echo "[${timestamp}] [${level}] ${message}" | tee -a "${LOG_FILE}"
}

# Error handler function
error_handler() {
    local exit_code=$?
    local line_number=$1
    log "ERROR" "Error occurred in script at line ${line_number}, exit code ${exit_code}"
    cleanup_temporary_files
    exit "${exit_code}"
}

# Set error handler
trap 'error_handler ${LINENO}' ERR

# Function decorator for logging function calls
log_function_call() {
    log "INFO" "Executing function: $1"
}

# Backup configuration files before modifications
backup_config() {
    local timestamp
    timestamp=$(date -u +"%Y%m%d_%H%M%S")
    local backup_path="${BACKUP_DIR}/${timestamp}"
    
    mkdir -p "${backup_path}"
    [[ -f "${AWS_CONFIG_FILE}" ]] && cp "${AWS_CONFIG_FILE}" "${backup_path}/config.bak"
    [[ -f "${AWS_CREDENTIALS_FILE}" ]] && cp "${AWS_CREDENTIALS_FILE}" "${backup_path}/credentials.bak"
    
    log "INFO" "Configuration backup created at ${backup_path}"
}

# Validate prerequisites and environment
validate_prerequisites() {
    log_function_call "${FUNCNAME[0]}"
    
    # Check AWS CLI version
    local aws_version
    aws_version=$(aws --version 2>&1 | cut -d/ -f2 | cut -d. -f1)
    if [[ ${aws_version} -lt 2 ]]; then
        log "ERROR" "AWS CLI version 2.x or higher is required"
        return 1
    fi
    
    # Verify jq installation
    if ! command -v jq >/dev/null 2>&1; then
        log "ERROR" "jq is required but not installed"
        return 1
    }
    
    # Check AWS configuration directory
    local aws_dir="${HOME}/.aws"
    if [[ ! -d "${aws_dir}" ]]; then
        mkdir -p "${aws_dir}"
        chmod 700 "${aws_dir}"
    fi
    
    # Validate write permissions
    if [[ ! -w "${aws_dir}" ]]; then
        log "ERROR" "No write permission for AWS configuration directory"
        return 1
    }
    
    # Create and check backup directory
    mkdir -p "${BACKUP_DIR}"
    chmod 700 "${BACKUP_DIR}"
    
    # Verify log file permissions
    touch "${LOG_FILE}" "${AUDIT_LOG}"
    chmod 600 "${LOG_FILE}" "${AUDIT_LOG}"
    
    # Check disk space
    local required_space=104857600  # 100MB
    local available_space
    available_space=$(df -P "$(dirname "${LOG_FILE}")" | awk 'NR==2 {print $4}')
    if [[ ${available_space} -lt ${required_space} ]]; then
        log "ERROR" "Insufficient disk space for logs and backups"
        return 1
    }
    
    log "INFO" "Prerequisites validation completed successfully"
    return 0
}

# Setup AWS profile for specific environment
setup_environment_profile() {
    local environment="$1"
    local account_id="$2"
    local role_name="$3"
    local mfa_serial="$4"
    
    log_function_call "${FUNCNAME[0]}"
    backup_config
    
    # Validate environment name
    if [[ ! "${environment}" =~ ^(production|staging|development)$ ]]; then
        log "ERROR" "Invalid environment name: ${environment}"
        return 1
    }
    
    local profile_name="${AWS_PROFILE_PREFIX}-${environment}"
    
    # Configure AWS profile
    aws configure set profile."${profile_name}".region "${AWS_PRIMARY_REGION}"
    aws configure set profile."${profile_name}".output "json"
    aws configure set profile."${profile_name}".role_arn "arn:aws:iam::${account_id}:role/${role_name}"
    aws configure set profile."${profile_name}".source_profile "default"
    aws configure set profile."${profile_name}".mfa_serial "${mfa_serial}"
    
    # Configure encryption settings
    aws configure set profile."${profile_name}".s3.signature_version "s3v4"
    aws configure set profile."${profile_name}".s3.use_accelerate_endpoint "true"
    
    # Set up backup region configuration
    if [[ "${environment}" == "production" ]]; then
        setup_backup_region "${profile_name}"
    fi
    
    # Validate profile configuration
    validate_credentials "${profile_name}"
    
    # Create audit log entry
    local timestamp
    timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    echo "${timestamp},${profile_name},${account_id},${role_name},${USER}" >> "${AUDIT_LOG}"
    
    log "INFO" "Environment profile ${profile_name} configured successfully"
    return 0
}

# Validate AWS credentials and permissions
validate_credentials() {
    local profile_name="$1"
    log_function_call "${FUNCNAME[0]}"
    
    # Test AWS STS access
    if ! aws sts get-caller-identity --profile "${profile_name}" >/dev/null 2>&1; then
        log "ERROR" "Failed to validate AWS credentials for profile ${profile_name}"
        return 1
    }
    
    # Verify assumed role permissions
    local assumed_role
    assumed_role=$(aws sts get-caller-identity --profile "${profile_name}" --query 'Arn' --output text)
    log "INFO" "Successfully assumed role: ${assumed_role}"
    
    # Check region access
    if ! aws ec2 describe-regions --profile "${profile_name}" >/dev/null 2>&1; then
        log "ERROR" "Failed to validate region access for profile ${profile_name}"
        return 1
    }
    
    # Verify encryption configuration
    if ! aws kms list-keys --profile "${profile_name}" >/dev/null 2>&1; then
        log "ERROR" "Failed to validate KMS access for profile ${profile_name}"
        return 1
    }
    
    log "INFO" "Credentials validation completed successfully for profile ${profile_name}"
    return 0
}

# Setup backup region configuration
setup_backup_region() {
    local profile_name="$1"
    log_function_call "${FUNCNAME[0]}"
    
    local backup_profile="${profile_name}-backup"
    
    # Configure backup region profile
    aws configure set profile."${backup_profile}".region "${AWS_BACKUP_REGION}"
    aws configure set profile."${backup_profile}".source_profile "${profile_name}"
    
    # Validate backup region access
    if ! aws ec2 describe-regions --profile "${backup_profile}" >/dev/null 2>&1; then
        log "ERROR" "Failed to validate backup region access"
        return 1
    }
    
    log "INFO" "Backup region configuration completed successfully"
    return 0
}

# Cleanup temporary files and sensitive data
cleanup_temporary_files() {
    log_function_call "${FUNCNAME[0]}"
    
    # Clean up temporary session tokens
    find /tmp -name "aws_session_*" -type f -mmin +60 -delete 2>/dev/null || true
    
    # Remove old backups (older than 30 days)
    find "${BACKUP_DIR}" -type d -mtime +30 -exec rm -rf {} + 2>/dev/null || true
    
    # Rotate logs if they exceed 100MB
    for log_file in "${LOG_FILE}" "${AUDIT_LOG}"; do
        if [[ -f "${log_file}" ]] && [[ $(stat -f%z "${log_file}") -gt 104857600 ]]; then
            mv "${log_file}" "${log_file}.$(date -u +%Y%m%d)"
            gzip "${log_file}.$(date -u +%Y%m%d)"
        fi
    done
    
    log "INFO" "Temporary files cleanup completed"
    return 0
}

# Main execution
main() {
    log "INFO" "Starting AWS credential configuration"
    
    validate_prerequisites || exit 1
    
    # Configure environments
    setup_environment_profile "production" "123456789012" "NotificationServiceRole" "arn:aws:iam::123456789012:mfa/admin"
    setup_environment_profile "staging" "123456789013" "NotificationServiceRole" "arn:aws:iam::123456789013:mfa/admin"
    setup_environment_profile "development" "123456789014" "NotificationServiceRole" "arn:aws:iam::123456789014:mfa/admin"
    
    cleanup_temporary_files
    
    log "INFO" "AWS credential configuration completed successfully"
}

# Execute main function
main "$@"