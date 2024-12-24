# Main notification queue outputs
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

# Dead letter queue outputs
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