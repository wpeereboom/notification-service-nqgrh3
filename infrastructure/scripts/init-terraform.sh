#!/bin/bash

# Terraform Infrastructure Initialization Script
# Version: 1.0.0
# Description: Initializes Terraform infrastructure with secure state management and multi-environment support
# Dependencies: aws-cli (2.x), terraform (1.5.x)

# Enable strict error handling
set -euo pipefail
[[ "${TRACE:-0}" == "1" ]] && set -x

# Source AWS credentials setup script
source "$(dirname "$0")/setup-aws-credentials.sh"

# Global constants
readonly STATE_BUCKET="notification-service-terraform-state"
readonly LOCK_TABLE="notification-service-terraform-locks"
readonly AWS_REGION="us-east-1"
readonly BACKUP_REGION="us-west-2"
readonly LOG_FILE="/var/log/terraform-init.log"
readonly BACKUP_DIR="/var/backup/terraform-state"
readonly MIN_TERRAFORM_VERSION="1.5.0"

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
    cleanup_temporary_files
    exit "${exit_code}"
}

trap 'error_handler ${LINENO}' ERR

# Validate prerequisites
validate_prerequisites() {
    log "INFO" "Validating prerequisites..."

    # Check Terraform version
    if ! command -v terraform >/dev/null 2>&1; then
        log "ERROR" "Terraform is not installed"
        return 1
    }

    local terraform_version
    terraform_version=$(terraform version -json | jq -r '.terraform_version')
    if ! [[ "$(printf '%s\n' "${MIN_TERRAFORM_VERSION}" "${terraform_version}" | sort -V | head -n1)" = "${MIN_TERRAFORM_VERSION}" ]]; then
        log "ERROR" "Terraform version must be >= ${MIN_TERRAFORM_VERSION}"
        return 1
    }

    # Validate AWS CLI and credentials
    if ! aws sts get-caller-identity >/dev/null 2>&1; then
        log "ERROR" "AWS credentials not configured properly"
        return 1
    }

    # Create log directory with proper permissions
    mkdir -p "$(dirname "${LOG_FILE}")"
    chmod 750 "$(dirname "${LOG_FILE}")"
    touch "${LOG_FILE}"
    chmod 640 "${LOG_FILE}"

    log "INFO" "Prerequisites validation completed"
    return 0
}

# Create and configure S3 bucket for state storage
create_state_bucket() {
    log "INFO" "Creating state bucket..."

    # Check if bucket exists
    if ! aws s3api head-bucket --bucket "${STATE_BUCKET}" 2>/dev/null; then
        # Create bucket with encryption and versioning
        aws s3api create-bucket \
            --bucket "${STATE_BUCKET}" \
            --region "${AWS_REGION}" \
            --create-bucket-configuration LocationConstraint="${AWS_REGION}"

        # Enable versioning
        aws s3api put-bucket-versioning \
            --bucket "${STATE_BUCKET}" \
            --versioning-configuration Status=Enabled

        # Enable encryption
        aws s3api put-bucket-encryption \
            --bucket "${STATE_BUCKET}" \
            --server-side-encryption-configuration '{
                "Rules": [
                    {
                        "ApplyServerSideEncryptionByDefault": {
                            "SSEAlgorithm": "aws:kms",
                            "KMSMasterKeyID": "alias/aws/s3"
                        },
                        "BucketKeyEnabled": true
                    }
                ]
            }'

        # Configure lifecycle rules
        aws s3api put-bucket-lifecycle-configuration \
            --bucket "${STATE_BUCKET}" \
            --lifecycle-configuration '{
                "Rules": [
                    {
                        "ID": "state-retention",
                        "Status": "Enabled",
                        "NoncurrentVersionExpiration": {
                            "NoncurrentDays": 90
                        }
                    }
                ]
            }'

        # Enable cross-region replication
        if [[ -n "${BACKUP_REGION}" ]]; then
            setup_cross_region_replication
        fi
    fi

    # Configure bucket policy
    aws s3api put-bucket-policy \
        --bucket "${STATE_BUCKET}" \
        --policy '{
            "Version": "2012-10-17",
            "Statement": [
                {
                    "Sid": "EnforceTLS",
                    "Effect": "Deny",
                    "Principal": "*",
                    "Action": "s3:*",
                    "Resource": [
                        "arn:aws:s3:::'"${STATE_BUCKET}"'",
                        "arn:aws:s3:::'"${STATE_BUCKET}"'/*"
                    ],
                    "Condition": {
                        "Bool": {
                            "aws:SecureTransport": "false"
                        }
                    }
                }
            ]
        }'

    log "INFO" "State bucket configuration completed"
}

# Setup cross-region replication
setup_cross_region_replication() {
    log "INFO" "Setting up cross-region replication..."

    local backup_bucket="${STATE_BUCKET}-backup"
    
    # Create backup bucket in secondary region
    aws s3api create-bucket \
        --bucket "${backup_bucket}" \
        --region "${BACKUP_REGION}" \
        --create-bucket-configuration LocationConstraint="${BACKUP_REGION}"

    # Enable versioning on backup bucket
    aws s3api put-bucket-versioning \
        --bucket "${backup_bucket}" \
        --versioning-configuration Status=Enabled

    # Configure replication
    aws s3api put-bucket-replication \
        --bucket "${STATE_BUCKET}" \
        --replication-configuration '{
            "Role": "arn:aws:iam::ACCOUNT_ID:role/terraform-state-replication",
            "Rules": [
                {
                    "Status": "Enabled",
                    "Priority": 1,
                    "DeleteMarkerReplication": { "Status": "Enabled" },
                    "Destination": {
                        "Bucket": "arn:aws:s3:::'"${backup_bucket}"'",
                        "EncryptionConfiguration": {
                            "ReplicaKmsKeyID": "alias/aws/s3"
                        }
                    }
                }
            ]
        }'

    log "INFO" "Cross-region replication setup completed"
}

# Create DynamoDB table for state locking
create_lock_table() {
    log "INFO" "Creating lock table..."

    # Check if table exists
    if ! aws dynamodb describe-table --table-name "${LOCK_TABLE}" >/dev/null 2>&1; then
        aws dynamodb create-table \
            --table-name "${LOCK_TABLE}" \
            --attribute-definitions AttributeName=LockID,AttributeType=S \
            --key-schema AttributeName=LockID,KeyType=HASH \
            --billing-mode PAY_PER_REQUEST \
            --stream-specification StreamEnabled=true,StreamViewType=NEW_AND_OLD_IMAGES \
            --tags Key=Service,Value=Terraform

        # Wait for table to be active
        aws dynamodb wait table-exists --table-name "${LOCK_TABLE}"

        # Enable point-in-time recovery
        aws dynamodb update-continuous-backups \
            --table-name "${LOCK_TABLE}" \
            --point-in-time-recovery-specification PointInTimeRecoveryEnabled=true
    fi

    log "INFO" "Lock table configuration completed"
}

# Backup existing state
backup_existing_state() {
    log "INFO" "Backing up existing state..."

    local timestamp
    timestamp=$(date -u +"%Y%m%d_%H%M%S")
    local backup_path="${BACKUP_DIR}/${timestamp}"

    mkdir -p "${backup_path}"
    
    # Copy existing state files if they exist
    if [[ -f "terraform.tfstate" ]]; then
        cp terraform.tfstate "${backup_path}/"
    fi
    
    if [[ -f "terraform.tfstate.backup" ]]; then
        cp terraform.tfstate.backup "${backup_path}/"
    fi

    log "INFO" "State backup completed at ${backup_path}"
}

# Initialize Terraform
init_terraform() {
    local environment="$1"

    log "INFO" "Initializing Terraform for environment: ${environment}"

    # Validate environment
    if [[ ! "${environment}" =~ ^(prod|staging|dev)$ ]]; then
        log "ERROR" "Invalid environment: ${environment}"
        return 1
    }

    # Run prerequisite checks
    validate_prerequisites || return 1

    # Backup existing state
    backup_existing_state

    # Create and configure backend resources
    create_state_bucket
    create_lock_table

    # Initialize Terraform with backend config
    terraform init \
        -backend=true \
        -backend-config="bucket=${STATE_BUCKET}" \
        -backend-config="key=${environment}/terraform.tfstate" \
        -backend-config="region=${AWS_REGION}" \
        -backend-config="dynamodb_table=${LOCK_TABLE}" \
        -backend-config="encrypt=true"

    # Select workspace
    if ! terraform workspace select "${environment}" 2>/dev/null; then
        terraform workspace new "${environment}"
    fi

    # Validate configurations
    terraform validate

    log "INFO" "Terraform initialization completed for ${environment}"
}

# Cleanup temporary files
cleanup_temporary_files() {
    log "INFO" "Cleaning up temporary files..."
    
    # Remove temporary files
    find /tmp -name "terraform-*" -type f -mmin +60 -delete 2>/dev/null || true
    
    # Rotate logs if needed
    if [[ -f "${LOG_FILE}" ]] && [[ $(stat -f%z "${LOG_FILE}") -gt 104857600 ]]; then
        mv "${LOG_FILE}" "${LOG_FILE}.$(date -u +%Y%m%d)"
        gzip "${LOG_FILE}.$(date -u +%Y%m%d)"
    fi
}

# Main execution
main() {
    if [[ $# -ne 1 ]]; then
        echo "Usage: $0 <environment>"
        echo "Environment must be one of: prod, staging, dev"
        exit 1
    fi

    local environment="$1"
    
    log "INFO" "Starting Terraform initialization script"
    init_terraform "${environment}"
    cleanup_temporary_files
    log "INFO" "Terraform initialization completed successfully"
}

# Execute main function
main "$@"