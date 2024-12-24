# Environment Identifier
variable "environment" {
  type        = string
  default     = "prod"
  description = "Production environment identifier"
}

# Regional Configuration
variable "aws_region" {
  type        = string
  default     = "us-east-1"
  description = "Primary AWS region for production deployment"
}

variable "backup_region" {
  type        = string
  default     = "us-west-2"
  description = "Backup AWS region for disaster recovery"
}

# Network Configuration
variable "vpc_cidr" {
  type        = string
  description = "Production VPC CIDR block"
  # Production CIDR block should be carefully chosen to avoid conflicts
  default     = "10.0.0.0/16"
  validation {
    condition     = can(cidrhost(var.vpc_cidr, 0))
    error_message = "VPC CIDR must be a valid IPv4 CIDR block."
  }
}

# Database Configuration
variable "database_instance_class" {
  type        = string
  default     = "db.r6g.xlarge"
  description = "Production RDS instance type for high performance"
  validation {
    condition     = can(regex("^db\\.[a-z0-9]+\\.[a-z0-9]+$", var.database_instance_class))
    error_message = "Database instance class must be a valid RDS instance type."
  }
}

# High Availability Configuration
variable "enable_multi_az" {
  type        = bool
  default     = true
  description = "Enable Multi-AZ deployment for production high availability"
}

# Backup Configuration
variable "backup_retention_period" {
  type        = number
  default     = 30
  description = "Production backup retention period in days"
  validation {
    condition     = var.backup_retention_period >= 0 && var.backup_retention_period <= 35
    error_message = "Backup retention period must be between 0 and 35 days."
  }
}

# Lambda Configuration
variable "lambda_memory_size" {
  type        = number
  default     = 512
  description = "Production Lambda function memory allocation in MB"
  validation {
    condition     = var.lambda_memory_size >= 128 && var.lambda_memory_size <= 10240
    error_message = "Lambda memory must be between 128 MB and 10240 MB."
  }
}

# API Gateway Configuration
variable "api_throttling_rate_limit" {
  type        = number
  default     = 100000
  description = "Production API Gateway throttling rate limit per minute"
  validation {
    condition     = var.api_throttling_rate_limit > 0
    error_message = "API throttling rate limit must be greater than 0."
  }
}

variable "api_throttling_burst_limit" {
  type        = number
  default     = 50000
  description = "Production API Gateway throttling burst limit"
  validation {
    condition     = var.api_throttling_burst_limit > 0
    error_message = "API throttling burst limit must be greater than 0."
  }
}

# Resource Tagging
variable "tags" {
  type        = map(string)
  description = "Production environment resource tags"
  default = {
    Environment = "prod"
    Project     = "notification-service"
    ManagedBy   = "terraform"
    CostCenter  = "production"
    Backup      = "required"
    Monitoring  = "enhanced"
  }
  validation {
    condition     = length(var.tags) > 0
    error_message = "At least one tag must be specified."
  }
}

# Cache Configuration
variable "cache_instance_class" {
  type        = string
  default     = "cache.r6g.large"
  description = "Production ElastiCache instance type for Redis"
  validation {
    condition     = can(regex("^cache\\.[a-z0-9]+\\.[a-z0-9]+$", var.cache_instance_class))
    error_message = "Cache instance class must be a valid ElastiCache instance type."
  }
}

# SQS Configuration
variable "sqs_message_retention_seconds" {
  type        = number
  default     = 1209600 # 14 days
  description = "Production SQS message retention period in seconds"
  validation {
    condition     = var.sqs_message_retention_seconds >= 60 && var.sqs_message_retention_seconds <= 1209600
    error_message = "SQS message retention must be between 60 seconds and 14 days."
  }
}