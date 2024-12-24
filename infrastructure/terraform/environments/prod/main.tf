# Terraform Configuration
terraform {
  required_version = ">= 1.5.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 4.0"
    }
  }

  backend "s3" {
    bucket         = "notification-service-terraform-state"
    key            = "environments/prod/terraform.tfstate"
    region         = "us-east-1"
    encrypt        = true
    dynamodb_table = "terraform-state-lock"
  }
}

# Local Variables
locals {
  environment     = "prod"
  primary_region  = "us-east-1"
  backup_region   = "us-west-2"
  common_tags = {
    Environment         = "production"
    Project            = "notification-service"
    ManagedBy          = "terraform"
    Backup             = "true"
    MultiAZ            = "true"
    CostCenter         = "notifications"
    SecurityCompliance = "high"
    DataClassification = "sensitive"
  }
}

# Primary Region Provider
provider "aws" {
  alias  = "primary"
  region = local.primary_region
  
  assume_role {
    role_arn = "arn:aws:iam::${var.aws_account_id}:role/TerraformProductionRole"
  }
  
  default_tags {
    tags = local.common_tags
  }
}

# Backup Region Provider
provider "aws" {
  alias  = "backup"
  region = local.backup_region
  
  assume_role {
    role_arn = "arn:aws:iam::${var.aws_account_id}:role/TerraformProductionRole"
  }
  
  default_tags {
    tags = local.common_tags
  }
}

# Primary Region Infrastructure
module "primary" {
  source = "../../"
  providers = {
    aws = aws.primary
  }

  environment               = local.environment
  aws_region               = local.primary_region
  vpc_cidr                 = "10.0.0.0/16"
  
  # Database Configuration
  database_instance_class   = "db.r6g.xlarge"
  multi_az_enabled         = true
  backup_retention_period  = 30
  enable_cross_region_backup = true
  enable_performance_insights = true
  enable_enhanced_monitoring = true
  
  # Cache Configuration
  cache_instance_class     = "cache.r6g.large"
  
  # Lambda Configuration
  lambda_memory_size      = 512
  lambda_timeout         = 30
  
  # API Gateway Configuration
  api_throttling_rate_limit = 100000
  api_throttling_burst_limit = 50000
  
  # SQS Configuration
  sqs_message_retention_seconds = 1209600 # 14 days
  
  tags = local.common_tags
}

# Backup Region Infrastructure
module "backup" {
  source = "../../"
  providers = {
    aws = aws.backup
  }

  environment               = local.environment
  aws_region               = local.backup_region
  vpc_cidr                 = "10.1.0.0/16"
  
  # Database Configuration
  database_instance_class   = "db.r6g.xlarge"
  multi_az_enabled         = true
  backup_retention_period  = 30
  enable_cross_region_backup = true
  enable_performance_insights = true
  enable_enhanced_monitoring = true
  
  # Cache Configuration
  cache_instance_class     = "cache.r6g.large"
  
  # Lambda Configuration
  lambda_memory_size      = 512
  lambda_timeout         = 30
  
  # API Gateway Configuration
  api_throttling_rate_limit = 100000
  api_throttling_burst_limit = 50000
  
  tags = merge(local.common_tags, {
    IsBackupRegion = "true"
  })
}

# Cross-Region Replication Configuration
resource "aws_dynamodb_global_table" "state_lock" {
  provider = aws.primary
  
  name = "terraform-state-lock"
  
  replica {
    region_name = local.primary_region
  }
  
  replica {
    region_name = local.backup_region
  }
}

# Outputs
output "primary_vpc_id" {
  description = "Primary region VPC ID"
  value       = module.primary.vpc_id
}

output "backup_vpc_id" {
  description = "Backup region VPC ID"
  value       = module.backup.vpc_id
}

output "primary_api_endpoint" {
  description = "Primary region API Gateway endpoint"
  value       = module.primary.api_gateway_endpoint
}

output "backup_api_endpoint" {
  description = "Backup region API Gateway endpoint"
  value       = module.backup.api_gateway_endpoint
}

output "primary_rds_endpoint" {
  description = "Primary region RDS endpoint"
  value       = module.primary.rds_endpoint
  sensitive   = true
}

output "backup_rds_endpoint" {
  description = "Backup region RDS endpoint"
  value       = module.backup.rds_endpoint
  sensitive   = true
}

output "lambda_function_arns" {
  description = "ARNs of Lambda functions in both regions"
  value = {
    primary = module.primary.lambda_function_arns
    backup  = module.backup.lambda_function_arns
  }
}