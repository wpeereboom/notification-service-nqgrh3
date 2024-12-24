# Provider configuration
terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 4.0"
    }
  }
}

# PostgreSQL parameter group with optimized settings
resource "aws_db_parameter_group" "postgres14" {
  family = "postgres14"
  name   = "notification-service-${var.environment}-pg14"

  parameter {
    name  = "max_connections"
    value = var.max_connections
  }

  parameter {
    name  = "shared_buffers"
    value = "{DBInstanceClassMemory/4}"
  }

  parameter {
    name  = "work_mem"
    value = "16384"
  }

  parameter {
    name  = "maintenance_work_mem"
    value = "2097152"
  }

  parameter {
    name  = "effective_cache_size"
    value = "{DBInstanceClassMemory*3/4}"
  }

  parameter {
    name  = "ssl"
    value = "1"
  }

  parameter {
    name  = "log_min_duration_statement"
    value = "1000"
  }

  tags = merge(var.tags, {
    Name = "notification-service-${var.environment}-pg14"
  })
}

# DB subnet group for RDS instances
resource "aws_db_subnet_group" "main" {
  name        = "notification-service-${var.environment}"
  subnet_ids  = var.private_subnet_ids
  description = "Subnet group for notification service RDS instances"

  tags = merge(var.tags, {
    Name = "notification-service-${var.environment}"
  })
}

# Primary RDS instance
resource "aws_db_instance" "primary" {
  identifier = "notification-service-${var.environment}"
  
  # Engine configuration
  engine                      = "postgres"
  engine_version             = var.engine_version
  instance_class             = var.db_instance_class
  allocated_storage          = var.allocated_storage
  storage_type               = var.storage_type
  storage_encrypted          = true
  kms_key_id                = var.kms_key_id

  # Database configuration
  db_name                    = var.db_name
  username                   = var.db_username
  password                   = var.db_password
  port                      = 5432

  # Network configuration
  db_subnet_group_name      = aws_db_subnet_group.main.name
  vpc_security_group_ids    = var.vpc_security_group_ids
  publicly_accessible       = false
  multi_az                  = var.multi_az

  # Parameter and option groups
  parameter_group_name      = aws_db_parameter_group.postgres14.name

  # Backup configuration
  backup_retention_period   = var.backup_retention_period
  backup_window            = "03:00-04:00"
  maintenance_window       = "Mon:04:00-Mon:05:00"
  copy_tags_to_snapshot    = true

  # Performance Insights
  performance_insights_enabled          = true
  performance_insights_retention_period = var.performance_insights_retention_period
  performance_insights_kms_key_id      = var.kms_key_id

  # Enhanced monitoring
  monitoring_interval = var.monitoring_interval
  monitoring_role_arn = aws_iam_role.rds_monitoring.arn

  # Auto minor version upgrade
  auto_minor_version_upgrade = true

  # Deletion protection
  deletion_protection = var.environment == "prod" ? true : false

  tags = merge(var.tags, {
    Name = "notification-service-${var.environment}-primary"
  })
}

# Read replica instance
resource "aws_db_instance" "replica" {
  count = var.environment == "prod" ? 1 : 0

  identifier = "notification-service-${var.environment}-replica"
  
  # Replica configuration
  replicate_source_db    = aws_db_instance.primary.id
  instance_class         = var.db_instance_class
  
  # Network configuration
  vpc_security_group_ids = var.vpc_security_group_ids
  publicly_accessible    = false

  # Performance Insights
  performance_insights_enabled          = true
  performance_insights_retention_period = var.performance_insights_retention_period
  performance_insights_kms_key_id      = var.kms_key_id

  # Enhanced monitoring
  monitoring_interval = var.monitoring_interval
  monitoring_role_arn = aws_iam_role.rds_monitoring.arn

  # Auto minor version upgrade
  auto_minor_version_upgrade = true

  tags = merge(var.tags, {
    Name = "notification-service-${var.environment}-replica"
  })
}

# IAM role for enhanced monitoring
resource "aws_iam_role" "rds_monitoring" {
  name = "notification-service-${var.environment}-rds-monitoring"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "monitoring.rds.amazonaws.com"
        }
      }
    ]
  })

  tags = var.tags
}

# Attach enhanced monitoring policy to IAM role
resource "aws_iam_role_policy_attachment" "rds_monitoring" {
  role       = aws_iam_role.rds_monitoring.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonRDSEnhancedMonitoringRole"
}

# CloudWatch alarms for monitoring
resource "aws_cloudwatch_metric_alarm" "database_cpu" {
  alarm_name          = "notification-service-${var.environment}-database-cpu"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = "2"
  metric_name        = "CPUUtilization"
  namespace          = "AWS/RDS"
  period             = "300"
  statistic          = "Average"
  threshold          = "80"
  alarm_description  = "This metric monitors database CPU utilization"
  alarm_actions      = []  # Add SNS topic ARN for notifications

  dimensions = {
    DBInstanceIdentifier = aws_db_instance.primary.id
  }

  tags = var.tags
}

resource "aws_cloudwatch_metric_alarm" "database_memory" {
  alarm_name          = "notification-service-${var.environment}-database-memory"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = "2"
  metric_name        = "FreeableMemory"
  namespace          = "AWS/RDS"
  period             = "300"
  statistic          = "Average"
  threshold          = "1000000000"  # 1GB in bytes
  alarm_description  = "This metric monitors database freeable memory"
  alarm_actions      = []  # Add SNS topic ARN for notifications

  dimensions = {
    DBInstanceIdentifier = aws_db_instance.primary.id
  }

  tags = var.tags
}