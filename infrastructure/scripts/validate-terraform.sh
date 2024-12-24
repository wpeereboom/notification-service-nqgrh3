#!/usr/bin/env bash

# Terraform Configuration Validation Script
# Version: 1.0.0
# Validates Terraform configurations for syntax, formatting, security, and environment-specific requirements

set -euo pipefail

# Global variables
TERRAFORM_DIR="../terraform"
ENVIRONMENTS="prod staging dev"
EXIT_CODE=0
VALIDATION_TIMEOUT=300
PARALLEL_JOBS=3

# Required tool versions
REQUIRED_TERRAFORM_VERSION="1.5.0"
REQUIRED_TFLINT_VERSION="0.40.0"

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check required tools
check_required_tools() {
    log_info "Checking required tools..."
    
    # Check Terraform version
    if ! terraform version | grep -q "v${REQUIRED_TERRAFORM_VERSION}"; then
        log_error "Required Terraform version ${REQUIRED_TERRAFORM_VERSION} not found"
        log_info "Please install with: brew install terraform@${REQUIRED_TERRAFORM_VERSION}"
        exit 1
    fi

    # Check TFLint version
    if ! tflint --version | grep -q "${REQUIRED_TFLINT_VERSION}"; then
        log_error "Required TFLint version ${REQUIRED_TFLINT_VERSION} not found"
        log_info "Please install with: brew install tflint"
        exit 1
    fi
}

# Validate Terraform syntax
validate_terraform_syntax() {
    local env_dir="$1"
    local validation_output
    
    log_info "Validating Terraform syntax in ${env_dir}..."
    
    # Initialize Terraform without backend
    if ! terraform -chdir="${env_dir}" init -backend=false > /dev/null; then
        log_error "Failed to initialize Terraform in ${env_dir}"
        return 1
    fi
    
    # Validate configuration
    validation_output=$(terraform -chdir="${env_dir}" validate -json)
    if [ $? -ne 0 ]; then
        log_error "Terraform validation failed in ${env_dir}"
        echo "${validation_output}" | jq -r '.diagnostics[] | "  - \(.detail)"'
        return 1
    fi
    
    # Check for deprecated resources
    if echo "${validation_output}" | jq -e '.warnings[] | select(.detail | contains("deprecated"))' > /dev/null; then
        log_warn "Deprecated resource usage detected in ${env_dir}"
        echo "${validation_output}" | jq -r '.warnings[] | "  - \(.detail)"'
    fi
    
    return 0
}

# Check Terraform formatting
check_terraform_format() {
    local env_dir="$1"
    local format_output
    
    log_info "Checking Terraform formatting in ${env_dir}..."
    
    format_output=$(terraform fmt -check -recursive -diff "${env_dir}" 2>&1)
    if [ $? -ne 0 ]; then
        log_error "Terraform formatting check failed in ${env_dir}"
        echo "${format_output}"
        return 1
    fi
    
    return 0
}

# Run TFLint security checks
run_tflint() {
    local env_dir="$1"
    local tflint_output
    
    log_info "Running TFLint security checks in ${env_dir}..."
    
    # Initialize TFLint with AWS plugin
    if ! tflint --init --config="${env_dir}/.tflint.hcl" > /dev/null; then
        log_error "Failed to initialize TFLint in ${env_dir}"
        return 1
    fi
    
    # Run TFLint with AWS rules
    tflint_output=$(tflint --config="${env_dir}/.tflint.hcl" --format=json "${env_dir}")
    if [ $? -ne 0 ]; then
        log_error "TFLint security checks failed in ${env_dir}"
        echo "${tflint_output}" | jq -r '.issues[] | "  - \(.message) (\(.rule))"'
        return 1
    fi
    
    # Check for security warnings
    if echo "${tflint_output}" | jq -e '.issues[] | select(.rule | startswith("aws_"))' > /dev/null; then
        log_warn "Security warnings detected in ${env_dir}"
        echo "${tflint_output}" | jq -r '.issues[] | "  - \(.message) (\(.rule))"'
    fi
    
    return 0
}

# Validate environment-specific configuration
validate_environment() {
    local environment="$1"
    local env_dir="${TERRAFORM_DIR}/environments/${environment}"
    local exit_code=0
    
    log_info "Validating ${environment} environment..."
    
    # Check if environment directory exists
    if [ ! -d "${env_dir}" ]; then
        log_error "Environment directory ${env_dir} not found"
        return 1
    fi
    
    # Run validations with timeout
    timeout ${VALIDATION_TIMEOUT} bash -c "
        # Validate syntax
        if ! validate_terraform_syntax '${env_dir}'; then
            exit 1
        fi
        
        # Check formatting
        if ! check_terraform_format '${env_dir}'; then
            exit 1
        fi
        
        # Run security checks
        if ! run_tflint '${env_dir}'; then
            exit 1
        fi
    "
    
    exit_code=$?
    
    if [ ${exit_code} -eq 124 ]; then
        log_error "Validation timed out for ${environment} environment"
        return 1
    fi
    
    return ${exit_code}
}

# Main execution
main() {
    local start_time=$(date +%s)
    local failed_environments=()
    
    log_info "Starting Terraform validation..."
    
    # Check required tools
    check_required_tools
    
    # Create temporary directory for parallel execution
    local tmp_dir=$(mktemp -d)
    trap 'rm -rf "${tmp_dir}"' EXIT
    
    # Validate each environment
    for environment in ${ENVIRONMENTS}; do
        if [ ${PARALLEL_JOBS} -gt 1 ]; then
            # Run validations in parallel
            validate_environment "${environment}" > "${tmp_dir}/${environment}.log" 2>&1 &
            
            # Limit parallel jobs
            if [ $(jobs -r | wc -l) -ge ${PARALLEL_JOBS} ]; then
                wait -n
            fi
        else
            # Run validations sequentially
            if ! validate_environment "${environment}"; then
                failed_environments+=("${environment}")
                EXIT_CODE=1
            fi
        fi
    done
    
    # Wait for parallel jobs to complete
    wait
    
    # Check results from parallel execution
    if [ ${PARALLEL_JOBS} -gt 1 ]; then
        for environment in ${ENVIRONMENTS}; do
            if [ -f "${tmp_dir}/${environment}.log" ]; then
                cat "${tmp_dir}/${environment}.log"
                if grep -q "\[ERROR\]" "${tmp_dir}/${environment}.log"; then
                    failed_environments+=("${environment}")
                    EXIT_CODE=1
                fi
            fi
        done
    fi
    
    # Summary
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    log_info "Validation completed in ${duration} seconds"
    
    if [ ${#failed_environments[@]} -eq 0 ]; then
        log_info "All environments validated successfully"
    else
        log_error "Validation failed for environments: ${failed_environments[*]}"
    fi
    
    return ${EXIT_CODE}
}

# Execute main function
main "$@"
```

This script provides a comprehensive Terraform validation solution with the following features:

1. Strict error handling with set -euo pipefail
2. Parallel validation support for multiple environments
3. Timeout handling for long-running validations
4. Comprehensive validation including:
   - Syntax checking
   - Format verification
   - Security scanning with TFLint
   - Environment-specific validation
5. Color-coded output for better readability
6. Detailed error reporting with context
7. Tool version verification
8. Support for prod, staging, and dev environments
9. Clean temporary file handling
10. Summary reporting with execution duration

The script can be executed from the infrastructure/scripts directory:
```bash
chmod +x validate-terraform.sh
./validate-terraform.sh