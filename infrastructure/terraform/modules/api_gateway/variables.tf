# Project and environment configuration
variable "project_name" {
  type        = string
  description = "Project name used for resource naming and tagging"
}

variable "environment" {
  type        = string
  description = "Deployment environment (prod, staging, dev)"
  validation {
    condition     = contains(["prod", "staging", "dev"], var.environment)
    error_message = "Environment must be one of: prod, staging, dev"
  }
}

# Network configuration
variable "vpc_id" {
  type        = string
  description = "VPC ID for API Gateway VPC Link integration"
}

# JWT Authorizer configuration
variable "jwt_issuer" {
  type        = string
  description = "JWT token issuer URL for API Gateway authorizer configuration"
}

variable "jwt_audience" {
  type        = string
  description = "JWT token audience for API Gateway authorizer validation"
}

# WAF and Rate Limiting configuration
variable "rate_limit_rate" {
  type        = number
  description = "WAF rate limit requests per minute per IP address"
  default     = 1000 # Default based on notification endpoint requirements
}

variable "rate_limit_burst" {
  type        = number
  description = "WAF rate limit burst capacity for handling traffic spikes"
  default     = 100 # 10% of base rate limit for burst handling
}

# Lambda Integration configuration
variable "lambda_function_arns" {
  type        = map(string)
  description = "Map of Lambda function ARNs for API Gateway integrations (e.g., {notification = 'arn:aws:lambda:...'})"
}

# Observability configuration
variable "enable_xray" {
  type        = bool
  description = "Enable AWS X-Ray tracing for API Gateway requests"
  default     = true
}

# Resource tagging
variable "tags" {
  type        = map(string)
  description = "Resource tags for the API Gateway and related resources"
  default = {
    Service     = "notification-service"
    Managed_by  = "terraform"
  }
}

# API Stage configuration
variable "stage_name" {
  type        = string
  description = "API Gateway deployment stage name"
  default     = "v1"
}

# Endpoint throttling configuration
variable "endpoint_throttling" {
  type = map(object({
    rate_limit  = number
    burst_limit = number
  }))
  description = "Per-endpoint throttling configuration"
  default = {
    "POST/notifications" = {
      rate_limit  = 1000
      burst_limit = 100
    }
    "GET/notifications" = {
      rate_limit  = 2000
      burst_limit = 200
    }
    "POST/templates" = {
      rate_limit  = 100
      burst_limit = 10
    }
  }
}

# WAF configuration
variable "waf_block_rules_enabled" {
  type        = bool
  description = "Enable WAF managed rules for SQL injection and XSS protection"
  default     = true
}

variable "waf_ip_rate_limit_enabled" {
  type        = bool
  description = "Enable WAF IP-based rate limiting"
  default     = true
}

# Logging configuration
variable "access_logging_enabled" {
  type        = bool
  description = "Enable API Gateway access logging to CloudWatch Logs"
  default     = true
}

variable "log_retention_days" {
  type        = number
  description = "Number of days to retain API Gateway logs"
  default     = 30
}

# Custom domain configuration
variable "custom_domain" {
  type = object({
    enabled     = bool
    domain_name = string
    zone_id     = string
  })
  description = "Custom domain configuration for API Gateway"
  default = {
    enabled     = false
    domain_name = ""
    zone_id     = ""
  }
}

# Cache configuration
variable "cache_enabled" {
  type        = bool
  description = "Enable API Gateway cache"
  default     = false
}

variable "cache_size" {
  type        = string
  description = "API Gateway cache size (0.5GB, 1.6GB, 6.1GB, 13.5GB, 28.4GB, 58.2GB, 118GB, 237GB)"
  default     = "0.5GB"
}