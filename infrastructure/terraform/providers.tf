# Terraform and Provider Versions Configuration
terraform {
  # Require Terraform 1.5.0 or higher for enhanced provider configuration and stability
  required_version = ">= 1.5.0"

  # Define required providers with specific version constraints
  required_providers {
    # AWS Provider for infrastructure deployment
    aws = {
      source  = "hashicorp/aws"
      version = "~> 4.0"  # Use AWS provider 4.x for stability and feature support
    }
    
    # Random provider for generating unique identifiers
    random = {
      source  = "hashicorp/random"
      version = "~> 3.0"  # Use Random provider 3.x for compatibility
    }
  }

  # Backend configuration should be provided separately in a backend.tf file
  # or via CLI to support different environments
}

# AWS Provider Configuration
provider "aws" {
  region = var.aws_region  # Region specified in variables.tf

  # Default tags applied to all resources
  default_tags {
    tags = {
      Project     = var.project_name
      Environment = var.environment
      ManagedBy   = "terraform"
      CreatedAt   = timestamp()
    }
  }

  # Enhanced security and operational configurations
  skip_metadata_api_check = true  # Disable metadata API checks for enhanced security
  skip_region_validation  = false # Ensure region validation
  s3_force_path_style    = false # Use virtual-hosted-style S3 URLs
  max_retries            = 3     # Number of retries for API calls

  # Configure default security settings
  default_security_group_rules = []  # Explicitly manage security group rules
  ignore_tags {
    key_prefixes = ["aws:"]  # Ignore AWS-managed tags
  }
}

# Random Provider Configuration
provider "random" {
  # Random provider doesn't require additional configuration
}

# Provider configuration for secondary region (DR)
provider "aws" {
  alias  = "dr"
  region = "us-west-2"  # Disaster recovery region

  # Inherit default tags and add DR-specific tags
  default_tags {
    tags = {
      Project     = var.project_name
      Environment = var.environment
      ManagedBy   = "terraform"
      CreatedAt   = timestamp()
      Purpose     = "disaster-recovery"
    }
  }

  # Maintain consistent security and operational configurations
  skip_metadata_api_check = true
  skip_region_validation  = false
  s3_force_path_style    = false
  max_retries            = 3

  default_security_group_rules = []
  ignore_tags {
    key_prefixes = ["aws:"]
  }
}