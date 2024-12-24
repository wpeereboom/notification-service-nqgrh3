# API Gateway Identifiers
output "api_id" {
  description = "The unique identifier of the API Gateway REST API, used for resource referencing and integration with other AWS services"
  value       = aws_api_gateway_rest_api.main.id
}

output "api_arn" {
  description = "The ARN of the API Gateway REST API, used for IAM policy attachments and resource permissions"
  value       = aws_api_gateway_rest_api.main.arn
}

# API Gateway Endpoints and URLs
output "api_endpoint" {
  description = "The HTTPS endpoint URL of the API Gateway, used for service discovery and client integration"
  value       = aws_api_gateway_rest_api.main.endpoint
}

output "execution_arn" {
  description = "The execution ARN required for Lambda permission configuration and IAM policy attachments"
  value       = aws_api_gateway_rest_api.main.execution_arn
}

output "invoke_url" {
  description = "The fully-qualified URL to invoke the API endpoint, including the stage name"
  value       = aws_api_gateway_stage.main.invoke_url
}

# Stage Information
output "stage_name" {
  description = "The name of the deployed API stage (e.g., 'prod', 'staging')"
  value       = aws_api_gateway_stage.main.stage_name
}

output "stage_arn" {
  description = "The ARN of the API Gateway stage, used for WAF association and resource policies"
  value       = aws_api_gateway_stage.main.arn
}

# Security Configuration
output "authorizer_id" {
  description = "The ID of the JWT authorizer configured for the API Gateway"
  value       = aws_api_gateway_authorizer.jwt.id
}

output "waf_web_acl_arn" {
  description = "The ARN of the WAF Web ACL protecting the API Gateway"
  value       = aws_wafv2_web_acl.main.arn
}

# Custom Domain (if enabled)
output "custom_domain_url" {
  description = "The custom domain URL for the API Gateway (if configured)"
  value       = var.custom_domain.enabled ? aws_api_gateway_domain_name.main[0].domain_name : null
}

# Monitoring and Logging
output "cloudwatch_log_group_name" {
  description = "The name of the CloudWatch Log Group for API Gateway access logs (if enabled)"
  value       = var.access_logging_enabled ? aws_cloudwatch_log_group.api_logs[0].name : null
}

output "cloudwatch_role_arn" {
  description = "The ARN of the IAM role used for CloudWatch logging"
  value       = var.access_logging_enabled ? aws_cloudwatch_log_group.api_logs[0].arn : null
}

# Resource Tags
output "api_tags" {
  description = "Tags applied to the API Gateway resources for resource management and cost allocation"
  value       = aws_api_gateway_rest_api.main.tags
}

# Throttling Configuration
output "throttling_settings" {
  description = "API Gateway throttling configuration for rate limiting and burst control"
  value = {
    burst_limit = var.rate_limit_burst
    rate_limit  = var.rate_limit_rate
  }
}