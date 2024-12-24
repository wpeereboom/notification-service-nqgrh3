# Environment identifier
variable "environment" {
  type        = string
  default     = "dev"
  description = "Environment identifier for the development deployment"
}

# AWS Region configuration
variable "aws_region" {
  type        = string
  default     = "us-east-1"
  description = "AWS region for development environment deployment"
}

# Network configuration
variable "vpc_cidr" {
  type        = string
  default     = "10.0.0.0/16"
  description = "CIDR block for development VPC network"
}

# RDS configuration
variable "database_instance_class" {
  type        = string
  default     = "db.t3.medium"
  description = "RDS instance class for development environment (smaller than production)"
}

# ElastiCache configuration
variable "cache_instance_class" {
  type        = string
  default     = "cache.t3.medium"
  description = "ElastiCache instance class for development environment (smaller than production)"
}

# Lambda configuration
variable "lambda_memory_size" {
  type        = number
  default     = 512
  description = "Memory allocation for Lambda functions in MB"
}

variable "lambda_timeout" {
  type        = number
  default     = 30
  description = "Timeout for Lambda functions in seconds"
}

# High Availability configuration
variable "enable_multi_az" {
  type        = bool
  default     = false
  description = "Enable Multi-AZ deployment for RDS and ElastiCache (disabled for development)"
}

# Resource tagging
variable "tags" {
  type = map(string)
  default = {
    Environment     = "dev"
    Project         = "notification-service"
    ManagedBy       = "terraform"
    CostCenter      = "development"
    DataClass       = "non-prod"
    SecurityProfile = "development"
  }
  description = "Default tags for development environment resources"
}