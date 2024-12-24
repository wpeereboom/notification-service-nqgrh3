# ---------------------------------------------------------------------------------------------------------------------
# STAGING ENVIRONMENT TERRAFORM CONFIGURATION
# Main configuration file for staging environment deployment of the Notification Service
# ---------------------------------------------------------------------------------------------------------------------

terraform {
  required_version = ">= 1.5.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 4.0"
    }
  }

  # S3 backend configuration for state management
  backend "s3" {
    bucket         = "notification-service-terraform-state"
    key            = "staging/terraform.tfstate"
    region         = "us-east-1"
    encrypt        = true
    dynamodb_table = "terraform-state-lock"
  }
}

# ---------------------------------------------------------------------------------------------------------------------
# LOCAL VARIABLES
# ---------------------------------------------------------------------------------------------------------------------

locals {
  environment = "staging"
  common_tags = {
    Environment = local.environment
    Project     = "notification-service"
    ManagedBy   = "terraform"
    CostCenter  = "staging-notifications"
  }
}

# ---------------------------------------------------------------------------------------------------------------------
# NOTIFICATION SERVICE INFRASTRUCTURE MODULE
# ---------------------------------------------------------------------------------------------------------------------

module "notification_infrastructure" {
  source = "../../"

  # Environment Configuration
  environment = local.environment
  aws_region  = var.aws_region
  vpc_cidr    = var.vpc_cidr

  # Database Configuration
  database_instance_class    = var.database_instance_class
  backup_retention_period    = var.db_backup_retention_period
  db_deletion_protection     = var.db_deletion_protection

  # Cache Configuration
  cache_instance_class       = var.cache_instance_class
  cache_node_count          = var.cache_node_count
  cache_parameter_group_family = var.cache_parameter_group_family

  # Lambda Configuration
  lambda_memory_size        = var.lambda_memory_size
  lambda_timeout           = var.lambda_timeout
  lambda_concurrent_executions = var.lambda_concurrent_executions
  lambda_log_retention_days = var.lambda_log_retention_days

  # API Gateway Configuration
  api_throttling_rate_limit  = var.api_throttling_rate_limit
  api_throttling_burst_limit = var.api_throttling_burst_limit

  # SQS Configuration
  sqs_message_retention_seconds = var.sqs_message_retention_seconds
  sqs_visibility_timeout_seconds = var.sqs_visibility_timeout_seconds

  # High Availability Configuration
  multi_az = var.enable_multi_az

  # Resource Tags
  tags = merge(
    local.common_tags,
    var.tags,
    {
      Environment = local.environment
      Project     = "notification-service"
      ManagedBy   = "terraform"
      CostCenter  = "staging-notifications"
    }
  )

  providers = {
    aws = aws
  }
}

# ---------------------------------------------------------------------------------------------------------------------
# OUTPUTS
# ---------------------------------------------------------------------------------------------------------------------

output "vpc_id" {
  description = "ID of the staging VPC"
  value       = module.notification_infrastructure.vpc_id
}

output "api_gateway_endpoint" {
  description = "API Gateway endpoint URL for staging environment"
  value       = module.notification_infrastructure.api_gateway_endpoint
}

output "lambda_function_arns" {
  description = "ARNs of Lambda functions in staging environment"
  value       = module.notification_infrastructure.lambda_function_arns
}

output "rds_endpoint" {
  description = "RDS instance endpoint for staging environment"
  value       = module.notification_infrastructure.rds_endpoint
  sensitive   = true
}

output "elasticache_endpoint" {
  description = "ElastiCache cluster endpoint for staging environment"
  value       = module.notification_infrastructure.elasticache_endpoint
  sensitive   = true
}

output "sqs_queue_urls" {
  description = "URLs of SQS queues in staging environment"
  value       = module.notification_infrastructure.sqs_queue_urls
}