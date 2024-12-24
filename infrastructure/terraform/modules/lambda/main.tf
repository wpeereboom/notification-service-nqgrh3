# Configure required providers
terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 4.0"
    }
  }
}

# Variables for Lambda configuration
variable "environment" {
  description = "Deployment environment (e.g., prod, staging, dev)"
  type        = string
}

variable "vpc_config" {
  description = "VPC configuration for Lambda functions"
  type = object({
    subnet_ids         = list(string)
    security_group_ids = list(string)
  })
}

variable "kms_key_arn" {
  description = "KMS key ARN for CloudWatch log encryption"
  type        = string
}

# IAM role for Lambda execution
resource "aws_iam_role" "lambda_execution_role" {
  name = "notification-service-lambda-${var.environment}"
  
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action = "sts:AssumeRole"
      Effect = "Allow"
      Principal = {
        Service = "lambda.amazonaws.com"
      }
    }]
  })

  tags = {
    Environment = var.environment
    Service     = "notification-service"
    ManagedBy   = "terraform"
  }
}

# IAM policy attachments for Lambda role
resource "aws_iam_role_policy_attachment" "lambda_basic" {
  role       = aws_iam_role.lambda_execution_role.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSLambdaBasicExecutionRole"
}

resource "aws_iam_role_policy_attachment" "lambda_vpc" {
  role       = aws_iam_role.lambda_execution_role.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSLambdaVPCAccessExecutionRole"
}

# Custom policy for notification service permissions
resource "aws_iam_role_policy" "notification_service_policy" {
  name = "notification-service-policy-${var.environment}"
  role = aws_iam_role.lambda_execution_role.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "sqs:SendMessage",
          "sqs:ReceiveMessage",
          "sqs:DeleteMessage",
          "sqs:GetQueueAttributes"
        ]
        Resource = ["arn:aws:sqs:*:*:notification-*"]
      },
      {
        Effect = "Allow"
        Action = [
          "sns:Publish"
        ]
        Resource = ["arn:aws:sns:*:*:notification-*"]
      }
    ]
  })
}

# CloudWatch Log Group for Lambda functions
resource "aws_cloudwatch_log_group" "lambda_logs" {
  name              = "/aws/lambda/notification-service-${var.environment}"
  retention_in_days = 30
  kms_key_id       = var.kms_key_arn

  tags = {
    Environment = var.environment
    Service     = "notification-service"
    ManagedBy   = "terraform"
  }
}

# Lambda function for notification processing
resource "aws_lambda_function" "notification_processor" {
  filename         = "notification_processor.zip"
  function_name    = "notification-processor-${var.environment}"
  role            = aws_iam_role.lambda_execution_role.arn
  handler         = "index.handler"
  runtime         = "provided.al2"  # Custom runtime for PHP 8.2
  memory_size     = 512
  timeout         = 30

  vpc_config {
    subnet_ids         = var.vpc_config.subnet_ids
    security_group_ids = var.vpc_config.security_group_ids
  }

  environment {
    variables = {
      ENVIRONMENT = var.environment
      LOG_LEVEL   = var.environment == "prod" ? "INFO" : "DEBUG"
    }
  }

  layers = ["arn:aws:lambda:${data.aws_region.current.name}:${data.aws_caller_identity.current.account_id}:layer:php-8-2:1"]

  tags = {
    Environment = var.environment
    Service     = "notification-service"
    ManagedBy   = "terraform"
    Function    = "message-processing"
  }
}

# Data sources for current AWS context
data "aws_region" "current" {}
data "aws_caller_identity" "current" {}

# Outputs
output "lambda_function_arn" {
  description = "ARN of the notification processor Lambda function"
  value       = aws_lambda_function.notification_processor.arn
}

output "lambda_function_name" {
  description = "Name of the notification processor Lambda function"
  value       = aws_lambda_function.notification_processor.function_name
}

output "lambda_role_arn" {
  description = "ARN of the Lambda execution role"
  value       = aws_iam_role.lambda_execution_role.arn
}