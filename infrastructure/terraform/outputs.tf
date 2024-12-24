# Output values for the Notification Service infrastructure
# These outputs expose essential resource information for external reference and cross-stack integration

# Environment identifier output
# Used for environment-specific configuration and conditional resource provisioning
output "environment" {
  description = "The current deployment environment (prod/staging/dev)"
  value       = var.environment
  sensitive   = false
}

# Main SQS queue URL output
# Enables message producers to send notifications for processing
output "sqs_queue_url" {
  description = "The URL of the main SQS queue for notification processing"
  value       = aws_sqs_queue.notification_queue.url
  sensitive   = false
}

# Dead Letter Queue (DLQ) URL output
# Enables monitoring and handling of failed message processing attempts
output "sqs_dlq_url" {
  description = "The URL of the dead-letter queue for failed notifications"
  value       = aws_sqs_queue.notification_dlq.url
  sensitive   = false
}

# RDS endpoint output
# Provides database connection information for application configuration
output "rds_endpoint" {
  description = "The endpoint URL for the RDS PostgreSQL instance"
  value       = aws_db_instance.notification_db.endpoint
  sensitive   = false
}

# API Gateway endpoint output
# Exposes the API endpoint for client applications
output "api_gateway_endpoint" {
  description = "The endpoint URL for the API Gateway"
  value       = aws_apigatewayv2_api.notification_api.api_endpoint
  sensitive   = false
}

# Redis cache endpoint output
# Provides cache connection information for application configuration
output "redis_endpoint" {
  description = "The endpoint for the Redis ElastiCache cluster"
  value       = aws_elasticache_cluster.notification_cache.cache_nodes[0].address
  sensitive   = false
}

# CloudWatch Log Group name output
# Enables external log aggregation and monitoring integration
output "cloudwatch_log_group" {
  description = "The name of the CloudWatch Log Group for notification service logs"
  value       = aws_cloudwatch_log_group.notification_logs.name
  sensitive   = false
}

# KMS key ARN output
# Provides encryption key information for cross-account access
output "kms_key_arn" {
  description = "The ARN of the KMS key used for encryption"
  value       = aws_kms_key.notification_key.arn
  sensitive   = false
}

# VPC ID output
# Enables network integration with other services
output "vpc_id" {
  description = "The ID of the VPC where the notification service is deployed"
  value       = aws_vpc.notification_vpc.id
  sensitive   = false
}

# Private subnet IDs output
# Provides subnet information for resource deployment
output "private_subnet_ids" {
  description = "The IDs of private subnets where the notification service resources are deployed"
  value       = aws_subnet.private[*].id
  sensitive   = false
}

# Lambda function ARNs output
# Enables event source mapping and permission management
output "lambda_function_arns" {
  description = "The ARNs of Lambda functions used in the notification service"
  value = {
    processor = aws_lambda_function.notification_processor.arn
    dlq_handler = aws_lambda_function.dlq_handler.arn
  }
  sensitive = false
}