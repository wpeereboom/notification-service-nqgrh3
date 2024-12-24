# AWS Provider version constraint
terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 4.0"
    }
  }
}

# Dead Letter Queue (DLQ) for handling failed messages
resource "aws_sqs_queue" "notification_dlq" {
  # Queue name with environment prefix for clear identification
  name = "${var.environment}-notification-dlq"

  # DLQ-specific retention period (default 7 days)
  message_retention_seconds = var.dlq_retention_seconds

  # Processing configuration
  visibility_timeout_seconds  = var.visibility_timeout_seconds
  max_message_size           = var.max_message_size
  delay_seconds              = var.delay_seconds
  receive_wait_time_seconds  = var.receive_wait_time_seconds

  # Enable server-side encryption using AWS managed keys
  sqs_managed_sse_enabled = true

  # Resource tagging for cost allocation and management
  tags = merge(
    var.tags,
    {
      Name = "${var.environment}-notification-dlq"
      Type = "DLQ"
    }
  )
}

# Main notification queue optimized for high throughput
resource "aws_sqs_queue" "notification_queue" {
  # Queue name with environment prefix
  name = "${var.environment}-notification-queue"

  # Message retention configuration (default 14 days)
  message_retention_seconds = var.message_retention_seconds

  # Processing configuration
  visibility_timeout_seconds  = var.visibility_timeout_seconds
  max_message_size           = var.max_message_size
  delay_seconds              = var.delay_seconds
  receive_wait_time_seconds  = var.receive_wait_time_seconds

  # Enable server-side encryption using AWS managed keys
  sqs_managed_sse_enabled = true

  # DLQ configuration for failed message handling
  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.notification_dlq.arn
    maxReceiveCount     = var.max_receive_count
  })

  # Resource tagging for cost allocation and management
  tags = merge(
    var.tags,
    {
      Name = "${var.environment}-notification-queue"
      Type = "Main"
    }
  )
}

# Outputs for queue integration
output "notification_queue_id" {
  description = "The ID of the main notification queue"
  value       = aws_sqs_queue.notification_queue.id
}

output "notification_queue_arn" {
  description = "The ARN of the main notification queue"
  value       = aws_sqs_queue.notification_queue.arn
}

output "notification_queue_url" {
  description = "The URL of the main notification queue"
  value       = aws_sqs_queue.notification_queue.url
}

output "notification_dlq_id" {
  description = "The ID of the dead letter queue"
  value       = aws_sqs_queue.notification_dlq.id
}

output "notification_dlq_arn" {
  description = "The ARN of the dead letter queue"
  value       = aws_sqs_queue.notification_dlq.arn
}

output "notification_dlq_url" {
  description = "The URL of the dead letter queue"
  value       = aws_sqs_queue.notification_dlq.url
}