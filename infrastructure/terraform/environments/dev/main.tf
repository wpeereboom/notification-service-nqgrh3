# Development Environment Terraform Configuration
terraform {
  required_version = ">= 1.0.0"
  
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 4.0"
    }
  }

  # S3 backend configuration for state management
  backend "s3" {
    key = "notification-service/dev/terraform.tfstate"
    # Other backend settings should be provided via backend config file or CLI
  }
}

# AWS Provider configuration for development environment
provider "aws" {
  region = var.aws_region

  default_tags {
    tags = local.common_tags
  }
}

# Local variables for development environment
locals {
  environment = "dev"
  common_tags = {
    Environment = local.environment
    Project     = "notification-service"
    ManagedBy   = "terraform"
    CostCenter  = "development"
  }
}

# Root module configuration for development environment
module "root_module" {
  source = "../../"

  # Environment configuration
  environment = local.environment
  aws_region  = var.aws_region
  vpc_cidr    = "10.0.0.0/16"

  # Development-specific resource sizing
  database_instance_class = "db.t3.medium"    # Cost-optimized instance for dev
  cache_instance_class   = "cache.t3.medium"  # Cost-optimized instance for dev
  lambda_memory_size    = 512                # 512MB for development functions
  lambda_timeout       = 30                  # 30 second timeout

  # Development environment settings
  enable_multi_az            = false  # Single AZ for cost optimization
  backup_retention_period    = 7      # 7 days retention for dev
  enable_deletion_protection = false  # Allow deletion in dev environment

  # API Gateway throttling for development
  api_throttling_rate_limit  = 1000   # Reduced rate limit for dev
  api_throttling_burst_limit = 500    # Reduced burst limit for dev

  # SQS Configuration for development
  sqs_message_retention_seconds = 345600  # 4 days retention for dev

  # Common tags for all resources
  tags = local.common_tags
}

# Outputs for development environment
output "vpc_id" {
  description = "Development VPC ID"
  value       = module.root_module.vpc_id
}

output "api_gateway_endpoint" {
  description = "Development API Gateway endpoint URL"
  value       = module.root_module.api_gateway_endpoint
}

output "lambda_function_arns" {
  description = "Development Lambda function ARNs"
  value       = module.root_module.lambda_function_arns
}

output "rds_endpoint" {
  description = "Development RDS endpoint"
  value       = module.root_module.rds_endpoint
  sensitive   = true
}

output "elasticache_endpoint" {
  description = "Development ElastiCache endpoint"
  value       = module.root_module.elasticache_endpoint
  sensitive   = true
}

output "sqs_queue_urls" {
  description = "Development SQS queue URLs"
  value       = module.root_module.sqs_queue_urls
}