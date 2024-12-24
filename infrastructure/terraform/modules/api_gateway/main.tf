# Provider configuration
terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 4.0"
    }
  }
}

# Local variables for resource naming and tagging
locals {
  name_prefix = "${var.project_name}-${var.environment}"
  common_tags = merge(var.tags, {
    Project     = var.project_name
    Environment = var.environment
    ManagedBy   = "terraform"
    Service     = "notification-api"
  })
}

# API Gateway REST API
resource "aws_api_gateway_rest_api" "main" {
  name = "${local.name_prefix}-notification-api"
  description = "Notification Service REST API"

  endpoint_configuration {
    types = ["REGIONAL"]
    vpc_endpoint_ids = [var.vpc_id]
  }

  tags = local.common_tags
}

# JWT Authorizer
resource "aws_api_gateway_authorizer" "jwt" {
  name                   = "${local.name_prefix}-jwt-authorizer"
  rest_api_id            = aws_api_gateway_rest_api.main.id
  type                   = "JWT"
  identity_source        = "$request.header.Authorization"
  
  jwt_configuration {
    audience = [var.jwt_audience]
    issuer   = var.jwt_issuer
  }
}

# WAF Web ACL
resource "aws_wafv2_web_acl" "main" {
  name        = "${local.name_prefix}-api-waf"
  description = "WAF rules for API Gateway protection"
  scope       = "REGIONAL"

  default_action {
    allow {}
  }

  # Rate limiting rule
  dynamic "rule" {
    for_each = var.waf_ip_rate_limit_enabled ? [1] : []
    content {
      name     = "rate-limit"
      priority = 1

      override_action {
        none {}
      }

      statement {
        rate_based_statement {
          limit              = var.rate_limit_rate
          aggregate_key_type = "IP"
        }
      }

      visibility_config {
        cloudwatch_metrics_enabled = true
        metric_name               = "${local.name_prefix}-rate-limit"
        sampled_requests_enabled  = true
      }
    }
  }

  # SQL Injection protection
  dynamic "rule" {
    for_each = var.waf_block_rules_enabled ? [1] : []
    content {
      name     = "sql-injection-protection"
      priority = 2

      override_action {
        none {}
      }

      statement {
        managed_rule_group_statement {
          name        = "AWSManagedRulesSQLiRuleSet"
          vendor_name = "AWS"
        }
      }

      visibility_config {
        cloudwatch_metrics_enabled = true
        metric_name               = "${local.name_prefix}-sql-injection"
        sampled_requests_enabled  = true
      }
    }
  }

  # XSS Protection
  dynamic "rule" {
    for_each = var.waf_block_rules_enabled ? [1] : []
    content {
      name     = "xss-protection"
      priority = 3

      override_action {
        none {}
      }

      statement {
        managed_rule_group_statement {
          name        = "AWSManagedRulesCommonRuleSet"
          vendor_name = "AWS"
        }
      }

      visibility_config {
        cloudwatch_metrics_enabled = true
        metric_name               = "${local.name_prefix}-xss-protection"
        sampled_requests_enabled  = true
      }
    }
  }

  visibility_config {
    cloudwatch_metrics_enabled = true
    metric_name               = "${local.name_prefix}-waf"
    sampled_requests_enabled  = true
  }

  tags = local.common_tags
}

# API Gateway Stage
resource "aws_api_gateway_stage" "main" {
  deployment_id = aws_api_gateway_deployment.main.id
  rest_api_id   = aws_api_gateway_rest_api.main.id
  stage_name    = var.stage_name

  xray_tracing_enabled = var.enable_xray

  dynamic "access_log_settings" {
    for_each = var.access_logging_enabled ? [1] : []
    content {
      destination_arn = aws_cloudwatch_log_group.api_logs[0].arn
      format         = jsonencode({
        requestId               = "$context.requestId"
        ip                     = "$context.identity.sourceIp"
        caller                 = "$context.identity.caller"
        user                   = "$context.identity.user"
        requestTime            = "$context.requestTime"
        httpMethod             = "$context.httpMethod"
        resourcePath           = "$context.resourcePath"
        status                 = "$context.status"
        protocol              = "$context.protocol"
        responseLength        = "$context.responseLength"
        integrationError      = "$context.integration.error"
        integrationStatus     = "$context.integration.status"
        integrationLatency    = "$context.integration.latency"
        integrationRequestId  = "$context.integration.requestId"
      })
    }
  }

  tags = local.common_tags
}

# CloudWatch Log Group for API Gateway logs
resource "aws_cloudwatch_log_group" "api_logs" {
  count             = var.access_logging_enabled ? 1 : 0
  name              = "/aws/apigateway/${local.name_prefix}-api"
  retention_in_days = var.log_retention_days
  tags              = local.common_tags
}

# WAF association with API Gateway
resource "aws_wafv2_web_acl_association" "api" {
  resource_arn = aws_api_gateway_stage.main.arn
  web_acl_arn  = aws_wafv2_web_acl.main.arn
}

# API Gateway Method Settings (for throttling)
resource "aws_api_gateway_method_settings" "all" {
  rest_api_id = aws_api_gateway_rest_api.main.id
  stage_name  = aws_api_gateway_stage.main.stage_name
  method_path = "*/*"

  settings {
    metrics_enabled        = true
    logging_level         = "INFO"
    data_trace_enabled    = true
    throttling_burst_limit = var.rate_limit_burst
    throttling_rate_limit  = var.rate_limit_rate
  }
}

# Custom Domain (if enabled)
resource "aws_api_gateway_domain_name" "main" {
  count           = var.custom_domain.enabled ? 1 : 0
  domain_name     = var.custom_domain.domain_name
  certificate_arn = aws_acm_certificate.api[0].arn

  endpoint_configuration {
    types = ["REGIONAL"]
  }

  tags = local.common_tags
}

# ACM Certificate for custom domain
resource "aws_acm_certificate" "api" {
  count             = var.custom_domain.enabled ? 1 : 0
  domain_name       = var.custom_domain.domain_name
  validation_method = "DNS"

  lifecycle {
    create_before_destroy = true
  }

  tags = local.common_tags
}

# API Gateway Deployment
resource "aws_api_gateway_deployment" "main" {
  rest_api_id = aws_api_gateway_rest_api.main.id

  lifecycle {
    create_before_destroy = true
  }

  depends_on = [
    aws_api_gateway_method_settings.all
  ]
}

# CloudWatch Metrics Alarm for 4XX errors
resource "aws_cloudwatch_metric_alarm" "api_4xx_errors" {
  alarm_name          = "${local.name_prefix}-api-4xx-errors"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = "5"
  metric_name         = "4XXError"
  namespace           = "AWS/ApiGateway"
  period              = "300"
  statistic           = "Sum"
  threshold           = "50"
  alarm_description   = "This metric monitors API Gateway 4XX errors"
  alarm_actions       = []  # Add SNS topic ARN if needed

  dimensions = {
    ApiName = aws_api_gateway_rest_api.main.name
    Stage   = aws_api_gateway_stage.main.stage_name
  }

  tags = local.common_tags
}

# CloudWatch Metrics Alarm for 5XX errors
resource "aws_cloudwatch_metric_alarm" "api_5xx_errors" {
  alarm_name          = "${local.name_prefix}-api-5xx-errors"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = "5"
  metric_name         = "5XXError"
  namespace           = "AWS/ApiGateway"
  period              = "300"
  statistic           = "Sum"
  threshold           = "25"
  alarm_description   = "This metric monitors API Gateway 5XX errors"
  alarm_actions       = []  # Add SNS topic ARN if needed

  dimensions = {
    ApiName = aws_api_gateway_rest_api.main.name
    Stage   = aws_api_gateway_stage.main.stage_name
  }

  tags = local.common_tags
}