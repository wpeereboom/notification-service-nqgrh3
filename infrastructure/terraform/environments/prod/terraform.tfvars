# Core Environment Configuration
environment = "prod"
aws_region  = "us-east-1"

# VPC Configuration
vpc_cidr = "10.0.0.0/16"

# RDS Configuration
db_instance_class        = "db.r6g.xlarge"  # High-performance instance for production workload
engine_version          = "14.7"            # PostgreSQL version
backup_retention_period = 30                # 30 days retention for compliance
enable_multi_az         = true             # Enable Multi-AZ for high availability

# ElastiCache Configuration
cache_instance_class = "cache.r6g.large"    # Production-grade cache instance

# Lambda Configuration
lambda_memory_size = 512                    # 512MB for production workload
lambda_timeout     = 30                     # 30 seconds timeout

# Resource Tags
tags = {
  Environment     = "production"
  Project         = "notification-service"
  CostCenter      = "notification-prod"
  DataClass       = "confidential"
  BackupSchedule  = "daily"
  MaintenanceDay  = "sunday"
  Owner           = "platform-team"
  BusinessUnit    = "engineering"
  ComplianceLevel = "high"
}

# Database Specific Settings
db_parameters = {
  max_connections                  = "1000"
  shared_buffers                  = "8GB"
  effective_cache_size            = "24GB"
  maintenance_work_mem            = "2GB"
  checkpoint_completion_target    = "0.9"
  wal_buffers                    = "16MB"
  default_statistics_target      = "100"
  random_page_cost               = "1.1"
  effective_io_concurrency       = "200"
  work_mem                       = "20971kB"
  min_wal_size                  = "2GB"
  max_wal_size                  = "8GB"
  max_worker_processes          = "8"
  max_parallel_workers_per_gather = "4"
  max_parallel_workers          = "8"
  max_parallel_maintenance_workers = "4"
}

# Monitoring and Alerting Configuration
monitoring_config = {
  detailed_monitoring_enabled = true
  logs_retention_days        = 90
  metric_alarms_enabled     = true
  evaluation_periods        = 3
  alarm_threshold_cpu       = 80
  alarm_threshold_memory    = 80
  alarm_threshold_disk      = 85
}

# Auto Scaling Configuration
autoscaling_config = {
  min_capacity          = 2
  max_capacity          = 10
  target_cpu_value      = 70
  target_memory_value   = 70
  scale_in_cooldown     = 300
  scale_out_cooldown    = 180
}

# Backup Configuration
backup_config = {
  backup_window      = "03:00-04:00"
  maintenance_window = "sun:04:00-sun:05:00"
  snapshot_retention = 30
  cross_region_copy  = true
  backup_regions     = ["us-west-2"]
}

# Security Configuration
security_config = {
  ssl_enforcement          = true
  deletion_protection      = true
  iam_authentication      = true
  enable_encryption       = true
  enable_cloudwatch_logs  = true
  enable_performance_insights = true
  performance_insights_retention = 7
}

# Network Configuration
network_config = {
  private_subnet_count = 3
  public_subnet_count  = 3
  nat_gateway_count    = 3
  enable_vpn_gateway   = true
  enable_flow_logs     = true
}