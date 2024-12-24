# Environment Configuration
environment = "staging"
aws_region = "us-east-1"
project_name = "notification-service"

# Network Configuration
vpc_cidr = "10.1.0.0/16"  # Staging VPC CIDR block

# RDS Configuration
database_instance_class = "db.r6g.large"  # Staging-appropriate instance size
enable_multi_az = false  # Multi-AZ disabled for staging to reduce costs

# ElastiCache Configuration
cache_instance_class = "cache.r6g.medium"  # Staging-appropriate cache instance

# Lambda Configuration
lambda_memory_size = 512  # 512MB memory allocation for Lambda functions
lambda_timeout = 30      # 30 second timeout for Lambda functions

# Resource Tags
tags = {
  Environment     = "staging"
  Project         = "notification-service"
  ManagedBy       = "terraform"
  CostCenter      = "engineering"
  DataClass       = "internal"
  BusinessUnit    = "platform"
  OnCallTeam      = "platform-engineering"
  SecurityZone    = "internal"
  BackupSchedule  = "daily"
  MaintenanceDay  = "sunday"
}

# API Gateway Configuration
api_gateway_throttling_rate_limit = 1000
api_gateway_throttling_burst_limit = 2000

# SQS Configuration
sqs_message_retention_seconds = 1209600  # 14 days
sqs_visibility_timeout_seconds = 30
sqs_max_receive_count = 3

# Database Configuration
db_backup_retention_period = 7  # 7 days backup retention for staging
db_deletion_protection = false  # Allow deletion in staging
db_storage_size = 100          # 100GB storage allocation

# Redis Configuration
redis_port = 6379
redis_num_cache_nodes = 1      # Single node for staging
redis_parameter_group_family = "redis7"
redis_snapshot_retention_limit = 7

# Lambda Concurrency Configuration
lambda_reserved_concurrent_executions = 100

# WAF Configuration
waf_rule_rate_limit = 2000  # Requests per 5 minutes per IP

# Monitoring Configuration
alarm_evaluation_periods = 2
alarm_period_seconds = 300     # 5 minutes
cpu_utilization_threshold = 70 # 70% CPU threshold for staging
memory_utilization_threshold = 70