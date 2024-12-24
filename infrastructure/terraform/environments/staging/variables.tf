# ---------------------------------------------------------------------------------------------------------------------
# STAGING ENVIRONMENT VARIABLES
# Defines staging environment-specific variables for the Notification Service infrastructure deployment
# ---------------------------------------------------------------------------------------------------------------------

variable "environment" {
  description = "Deployment environment name"
  type        = string
  default     = "staging"
}

variable "aws_region" {
  description = "AWS region for staging deployment"
  type        = string
  default     = "us-east-1"
}

variable "vpc_cidr" {
  description = "CIDR block for staging VPC"
  type        = string
  default     = "10.1.0.0/16"  # Staging VPC CIDR range
}

variable "database_instance_class" {
  description = "RDS instance type for staging environment"
  type        = string
  default     = "db.r6g.large"  # Smaller instance than production
}

variable "cache_instance_class" {
  description = "ElastiCache instance type for staging environment"
  type        = string
  default     = "cache.r6g.large"  # Smaller instance than production
}

variable "lambda_memory_size" {
  description = "Memory allocation for Lambda functions in MB"
  type        = number
  default     = 512  # Standard memory allocation for staging
}

variable "lambda_timeout" {
  description = "Lambda function execution timeout in seconds"
  type        = number
  default     = 30  # Standard timeout for staging
}

variable "enable_multi_az" {
  description = "Enable Multi-AZ deployment for RDS and ElastiCache"
  type        = bool
  default     = false  # Disabled for staging to reduce costs
}

variable "tags" {
  description = "Resource tags for staging environment"
  type        = map(string)
  default = {
    Environment     = "staging"
    Project         = "notification-service"
    ManagedBy      = "terraform"
    CostCenter     = "engineering"
    DataClass      = "internal"
    SecurityLevel  = "high"
  }
}

# ---------------------------------------------------------------------------------------------------------------------
# API Gateway Configuration
# ---------------------------------------------------------------------------------------------------------------------

variable "api_throttling_rate_limit" {
  description = "API Gateway account level throttling rate limit"
  type        = number
  default     = 5000  # Requests per second for staging
}

variable "api_throttling_burst_limit" {
  description = "API Gateway account level throttling burst limit"
  type        = number
  default     = 2500  # Burst limit for staging
}

# ---------------------------------------------------------------------------------------------------------------------
# Database Configuration
# ---------------------------------------------------------------------------------------------------------------------

variable "db_backup_retention_period" {
  description = "Number of days to retain database backups"
  type        = number
  default     = 7  # One week retention for staging
}

variable "db_deletion_protection" {
  description = "Enable deletion protection for RDS instances"
  type        = bool
  default     = false  # Disabled for staging to allow easier cleanup
}

# ---------------------------------------------------------------------------------------------------------------------
# Cache Configuration
# ---------------------------------------------------------------------------------------------------------------------

variable "cache_node_count" {
  description = "Number of cache nodes in the cluster"
  type        = number
  default     = 1  # Single node for staging
}

variable "cache_parameter_group_family" {
  description = "Cache parameter group family"
  type        = string
  default     = "redis7"  # Using Redis 7.x
}

# ---------------------------------------------------------------------------------------------------------------------
# SQS Configuration
# ---------------------------------------------------------------------------------------------------------------------

variable "sqs_message_retention_seconds" {
  description = "Message retention period in seconds"
  type        = number
  default     = 1209600  # 14 days
}

variable "sqs_visibility_timeout_seconds" {
  description = "Visibility timeout for messages"
  type        = number
  default     = 60  # 1 minute for staging
}

# ---------------------------------------------------------------------------------------------------------------------
# Lambda Configuration
# ---------------------------------------------------------------------------------------------------------------------

variable "lambda_concurrent_executions" {
  description = "Lambda function concurrent execution limit"
  type        = number
  default     = 100  # Lower concurrency for staging
}

variable "lambda_log_retention_days" {
  description = "CloudWatch log retention period in days"
  type        = number
  default     = 30  # 30 days retention for staging logs
}