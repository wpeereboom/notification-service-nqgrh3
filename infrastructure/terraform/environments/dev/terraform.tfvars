# Environment Configuration
environment = "dev"
aws_region = "us-east-1"

# Network Configuration
vpc_cidr = "10.0.0.0/16"

# Database Configuration
database_instance_class    = "db.t3.medium"
enable_multi_az           = false
backup_retention_period   = 7
enable_deletion_protection = false

# Cache Configuration
cache_instance_class = "cache.t3.medium"

# Lambda Configuration
lambda_memory_size = 512  # MB
lambda_timeout     = 30   # seconds

# Resource Tags
tags = {
  Environment = "dev"
  Project     = "notification-service"
  ManagedBy   = "terraform"
}