# Lambda Function Outputs
output "function_arn" {
  description = "The ARN of the Lambda function for cross-module integration"
  value       = aws_lambda_function.notification_processor.arn
}

output "function_name" {
  description = "The name of the Lambda function for reference in other resources"
  value       = aws_lambda_function.notification_processor.function_name
}

# IAM Role Outputs
output "function_role_arn" {
  description = "The ARN of the IAM role attached to the Lambda function for security auditing"
  value       = aws_iam_role.lambda_execution_role.arn
}

output "function_role_name" {
  description = "The name of the IAM role attached to the Lambda function for permission management"
  value       = aws_iam_role.lambda_execution_role.name
}

# CloudWatch Log Group Output
output "log_group_name" {
  description = "The name of the CloudWatch log group for Lambda function logs"
  value       = "/aws/lambda/notification-service-${var.environment}"
}