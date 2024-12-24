# Core Environment Variables
variable "environment" {
  type        = string
  description = "Deployment environment identifier (prod, staging, dev)"
  validation {
    condition     = contains(["prod", "staging", "dev"], var.environment)
    error_message = "Environment must be one of: prod, staging, dev."
  }
}

variable "aws_region" {
  type        = string
  description = "AWS region for resource deployment"
  default     = "us-east-1"
  validation {
    condition     = can(regex("^[a-z]{2}-[a-z]+-[0-9]{1}$", var.aws_region))
    error_message = "AWS region must be in format: xx-xxxx-#."
  }
}

variable "project_name" {
  type        = string
  description = "Project identifier for resource naming and tagging"
  default     = "notification-service"
  validation {
    condition     = can(regex("^[a-z][a-z0-9-]{2,30}[a-z0-9]$", var.project_name))
    error_message = "Project name must be lowercase alphanumeric with hyphens, 4-32 characters."
  }
}

# Network Configuration
variable "vpc_cidr" {
  type        = string
  description = "CIDR block for VPC network configuration"
  validation {
    condition     = can(cidrhost(var.vpc_cidr, 0))
    error_message = "VPC CIDR must be a valid IPv4 CIDR block."
  }
}

# Database Configuration
variable "database_instance_class" {
  type        = string
  description = "RDS instance type for PostgreSQL database"
  default     = "db.r6g.xlarge"
  validation {
    condition     = can(regex("^db\\.[a-z0-9]+\\.[a-z0-9]+$", var.database_instance_class))
    error_message = "Database instance class must be a valid RDS instance type."
  }
}

# Cache Configuration
variable "cache_instance_class" {
  type        = string
  description = "ElastiCache instance type for Redis"
  default     = "cache.r6g.large"
  validation {
    condition     = can(regex("^cache\\.[a-z0-9]+\\.[a-z0-9]+$", var.cache_instance_class))
    error_message = "Cache instance class must be a valid ElastiCache instance type."
  }
}

# Lambda Configuration
variable "lambda_memory_size" {
  type        = number
  description = "Memory allocation for Lambda functions in MB"
  default     = 512
  validation {
    condition     = var.lambda_memory_size >= 128 && var.lambda_memory_size <= 10240
    error_message = "Lambda memory must be between 128 MB and 10240 MB."
  }
}

variable "lambda_timeout" {
  type        = number
  description = "Timeout for Lambda functions in seconds"
  default     = 30
  validation {
    condition     = var.lambda_timeout >= 1 && var.lambda_timeout <= 900
    error_message = "Lambda timeout must be between 1 and 900 seconds."
  }
}

# High Availability Configuration
variable "enable_multi_az" {
  type        = bool
  description = "Enable Multi-AZ deployment for high availability"
  default     = true
}

# Resource Tagging
variable "tags" {
  type        = map(string)
  description = "Common resource tags for cost allocation and organization"
  default = {
    Project     = "notification-service"
    ManagedBy   = "terraform"
    Environment = "prod"
  }
  validation {
    condition     = length(var.tags) > 0
    error_message = "At least one tag must be specified."
  }
}

# API Gateway Configuration
variable "api_throttling_rate_limit" {
  type        = number
  description = "API Gateway throttling rate limit per second"
  default     = 10000
  validation {
    condition     = var.api_throttling_rate_limit > 0
    error_message = "API throttling rate limit must be greater than 0."
  }
}

variable "api_throttling_burst_limit" {
  type        = number
  description = "API Gateway throttling burst limit"
  default     = 5000
  validation {
    condition     = var.api_throttling_burst_limit > 0
    error_message = "API throttling burst limit must be greater than 0."
  }
}

# SQS Configuration
variable "sqs_message_retention_seconds" {
  type        = number
  description = "SQS message retention period in seconds"
  default     = 1209600 # 14 days
  validation {
    condition     = var.sqs_message_retention_seconds >= 60 && var.sqs_message_retention_seconds <= 1209600
    error_message = "SQS message retention must be between 60 seconds and 14 days."
  }
}

# Backup Configuration
variable "backup_retention_period" {
  type        = number
  description = "Backup retention period in days for RDS"
  default     = 30
  validation {
    condition     = var.backup_retention_period >= 0 && var.backup_retention_period <= 35
    error_message = "Backup retention period must be between 0 and 35 days."
  }
}