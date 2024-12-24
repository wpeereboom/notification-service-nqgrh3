# Environment name for resource naming and tagging
variable "environment" {
  type        = string
  description = "Environment name for resource naming (e.g., prod, staging, dev)"

  validation {
    condition     = contains(["prod", "staging", "dev"], var.environment)
    error_message = "Environment must be one of: prod, staging, dev."
  }
}

# Message visibility timeout - set to support Lambda processing time
variable "visibility_timeout_seconds" {
  type        = number
  description = "The visibility timeout for the queue in seconds"
  default     = 30

  validation {
    condition     = var.visibility_timeout_seconds >= 0 && var.visibility_timeout_seconds <= 43200
    error_message = "Visibility timeout must be between 0 and 43200 seconds (12 hours)."
  }
}

# Message retention period in main queue - set to 14 days for compliance
variable "message_retention_seconds" {
  type        = number
  description = "The number of seconds Amazon SQS retains a message in main queue"
  default     = 1209600  # 14 days

  validation {
    condition     = var.message_retention_seconds >= 60 && var.message_retention_seconds <= 1209600
    error_message = "Message retention must be between 60 seconds and 1209600 seconds (14 days)."
  }
}

# DLQ retention period - set to 7 days for failed message analysis
variable "dlq_retention_seconds" {
  type        = number
  description = "The number of seconds Amazon SQS retains a message in dead letter queue"
  default     = 604800  # 7 days

  validation {
    condition     = var.dlq_retention_seconds >= 60 && var.dlq_retention_seconds <= 1209600
    error_message = "DLQ retention must be between 60 seconds and 1209600 seconds (14 days)."
  }
}

# Maximum receive count before message moves to DLQ
variable "max_receive_count" {
  type        = number
  description = "The maximum number of times that a message can be received before being moved to DLQ"
  default     = 3

  validation {
    condition     = var.max_receive_count >= 1 && var.max_receive_count <= 1000
    error_message = "Maximum receive count must be between 1 and 1000."
  }
}

# Maximum message size - set to support rich notification content
variable "max_message_size" {
  type        = number
  description = "The limit of how many bytes a message can contain"
  default     = 262144  # 256 KB

  validation {
    condition     = var.max_message_size >= 1024 && var.max_message_size <= 262144
    error_message = "Maximum message size must be between 1024 bytes (1 KB) and 262144 bytes (256 KB)."
  }
}

# Message delay seconds - configurable for rate limiting
variable "delay_seconds" {
  type        = number
  description = "The time in seconds that the delivery of all messages in the queue will be delayed"
  default     = 0

  validation {
    condition     = var.delay_seconds >= 0 && var.delay_seconds <= 900
    error_message = "Delay seconds must be between 0 and 900 seconds (15 minutes)."
  }
}

# Long polling wait time - optimized for batch processing
variable "receive_wait_time_seconds" {
  type        = number
  description = "The time for which a ReceiveMessage call will wait for a message to arrive"
  default     = 20  # Maximum recommended for long polling

  validation {
    condition     = var.receive_wait_time_seconds >= 0 && var.receive_wait_time_seconds <= 20
    error_message = "Receive wait time must be between 0 and 20 seconds."
  }
}

# Resource tagging for cost allocation and management
variable "tags" {
  type        = map(string)
  description = "A map of tags to assign to the SQS queues"
  default     = {}

  validation {
    condition     = length(var.tags) <= 50
    error_message = "Maximum number of tags is 50."
  }
}