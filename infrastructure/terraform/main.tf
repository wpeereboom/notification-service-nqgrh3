# Provider Configuration
terraform {
  required_version = ">= 1.0.0"
  
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 4.0"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.0"
    }
  }

  backend "s3" {
    # Backend configuration should be provided via backend config file or CLI
    key = "notification-service/terraform.tfstate"
  }
}

# AWS Provider Configuration
provider "aws" {
  region = var.aws_region

  default_tags {
    tags = local.common_tags
  }
}

# Local Variables
locals {
  project_name = "notification-service"
  common_tags = {
    Project             = local.project_name
    ManagedBy          = "terraform"
    Environment        = var.environment
    SecurityLevel      = "high"
    ComplianceRequired = "true"
    BackupRequired     = "true"
  }
}

# Random ID for unique resource naming
resource "random_id" "unique" {
  byte_length = 8
}

# VPC Module
module "vpc" {
  source = "./modules/vpc"

  environment        = var.environment
  vpc_cidr          = var.vpc_cidr
  enable_flow_logs  = true
  enable_nat_gateway = true
  single_nat_gateway = false # For high availability

  providers = {
    aws = aws
  }

  tags = local.common_tags
}

# API Gateway Module
module "api_gateway" {
  source = "./modules/api_gateway"

  environment         = var.environment
  project_name        = local.project_name
  vpc_id             = module.vpc.vpc_id
  lambda_function_arns = module.lambda.lambda_function_arns
  
  enable_waf          = true
  enable_access_logs  = true
  throttling_rate_limit = var.api_throttling_rate_limit
  throttling_burst_limit = var.api_throttling_burst_limit

  providers = {
    aws = aws
  }

  tags = local.common_tags
}

# Lambda Module
module "lambda" {
  source = "./modules/lambda"

  environment        = var.environment
  project_name       = local.project_name
  vpc_id            = module.vpc.vpc_id
  private_subnet_ids = module.vpc.private_subnet_ids
  
  memory_size         = var.lambda_memory_size
  timeout            = var.lambda_timeout
  runtime            = "provided.al2"
  enable_xray        = true
  enable_cloudwatch_logs = true

  providers = {
    aws = aws
  }

  tags = local.common_tags
}

# RDS Module
module "rds" {
  source = "./modules/rds"

  environment         = var.environment
  project_name        = local.project_name
  vpc_id             = module.vpc.vpc_id
  private_subnet_ids  = module.vpc.private_subnet_ids
  instance_class     = var.database_instance_class
  
  multi_az           = var.enable_multi_az
  backup_retention_period = var.backup_retention_period
  engine_version     = "14"
  storage_encrypted  = true

  providers = {
    aws = aws
  }

  tags = local.common_tags
}

# ElastiCache Module
module "elasticache" {
  source = "./modules/elasticache"

  environment         = var.environment
  project_name        = local.project_name
  vpc_id             = module.vpc.vpc_id
  private_subnet_ids  = module.vpc.private_subnet_ids
  instance_class     = var.cache_instance_class
  
  multi_az           = var.enable_multi_az
  engine_version     = "7.0"
  at_rest_encryption = true
  transit_encryption = true

  providers = {
    aws = aws
  }

  tags = local.common_tags
}

# SQS Module
module "sqs" {
  source = "./modules/sqs"

  environment        = var.environment
  project_name       = local.project_name
  
  message_retention_seconds = var.sqs_message_retention_seconds
  enable_dlq        = true
  enable_encryption = true

  providers = {
    aws = aws
  }

  tags = local.common_tags
}

# CloudWatch Module for Monitoring
module "monitoring" {
  source = "./modules/monitoring"

  environment        = var.environment
  project_name       = local.project_name
  
  enable_dashboard  = true
  enable_alerts     = true
  
  rds_instance_id   = module.rds.instance_id
  cache_cluster_id  = module.elasticache.cluster_id
  lambda_functions  = module.lambda.lambda_function_names

  providers = {
    aws = aws
  }

  tags = local.common_tags
}

# Outputs
output "vpc_id" {
  description = "ID of the created VPC"
  value       = module.vpc.vpc_id
}

output "api_gateway_endpoint" {
  description = "API Gateway endpoint URL"
  value       = module.api_gateway.api_gateway_endpoint
}

output "lambda_function_arns" {
  description = "ARNs of created Lambda functions"
  value       = module.lambda.lambda_function_arns
}

output "rds_endpoint" {
  description = "RDS instance endpoint"
  value       = module.rds.endpoint
  sensitive   = true
}

output "elasticache_endpoint" {
  description = "ElastiCache cluster endpoint"
  value       = module.elasticache.endpoint
  sensitive   = true
}

output "sqs_queue_urls" {
  description = "URLs of created SQS queues"
  value       = module.sqs.queue_urls
}