variable "function_name" {
  type        = string
  description = "The name of the Lambda function for the notification service"
}

variable "runtime" {
  type        = string
  description = "Runtime environment for the Lambda function"
  default     = "provided.al2"  # AWS Lambda custom runtime for PHP 8.2
}

variable "handler" {
  type        = string
  description = "Handler function path for the Lambda function"
}

variable "memory_size" {
  type        = number
  description = "Memory allocation for the Lambda function in MB"
  default     = 512

  validation {
    condition     = var.memory_size >= 128 && var.memory_size <= 10240 && floor(var.memory_size) == var.memory_size
    error_message = "Memory size must be between 128 MB and 10240 MB and must be a whole number."
  }
}

variable "timeout" {
  type        = number
  description = "Function execution timeout in seconds"
  default     = 30

  validation {
    condition     = var.timeout >= 1 && var.timeout <= 900
    error_message = "Timeout must be between 1 and 900 seconds."
  }
}

variable "vpc_config" {
  type = object({
    subnet_ids         = list(string)
    security_group_ids = list(string)
  })
  description = "VPC configuration for Lambda function including subnet IDs and security group IDs"
}

variable "environment_variables" {
  type        = map(string)
  description = "Environment variables for Lambda function configuration"
  default     = {}
}

variable "tags" {
  type        = map(string)
  description = "Resource tags for Lambda function and related resources"
  default = {
    Environment = "production"
    Service     = "notification-service"
    Managed     = "terraform"
  }
}

variable "log_retention_days" {
  type        = number
  description = "CloudWatch log retention period in days"
  default     = 30

  validation {
    condition     = contains([0, 1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1827, 3653], var.log_retention_days)
    error_message = "Log retention days must be one of the allowed values as per AWS CloudWatch Logs retention policy."
  }
}

variable "reserved_concurrent_executions" {
  type        = number
  description = "Amount of reserved concurrent executions for this lambda function"
  default     = -1 # -1 means no specific limit

  validation {
    condition     = var.reserved_concurrent_executions >= -1
    error_message = "Reserved concurrent executions must be -1 or greater."
  }
}

variable "layers" {
  type        = list(string)
  description = "List of Lambda Layer Version ARNs to attach to the Lambda function"
  default     = []
}

variable "tracing_config" {
  type        = string
  description = "X-Ray tracing mode configuration (Active or PassThrough)"
  default     = "Active"

  validation {
    condition     = contains(["Active", "PassThrough"], var.tracing_config)
    error_message = "Tracing config must be either 'Active' or 'PassThrough'."
  }
}

variable "dead_letter_config" {
  type = object({
    target_arn = string
  })
  description = "Configuration for the Lambda function's dead letter queue"
  default     = null
}

variable "kms_key_arn" {
  type        = string
  description = "ARN of the KMS key used to encrypt environment variables"
  default     = null
}