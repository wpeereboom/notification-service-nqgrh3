# AWS ElastiCache Redis Module
# Provider version: ~> 4.0
# Configures a highly available Redis cluster with automated backups and security controls

locals {
  # Core configuration
  cluster_name    = var.cluster_name
  environment     = var.environment
  node_type       = var.node_type != "" ? var.node_type : "cache.r6g.large"
  num_cache_nodes = var.num_cache_nodes != 0 ? var.num_cache_nodes : 2

  # Network configuration
  subnet_ids = var.subnet_ids
  vpc_id     = var.vpc_id
  vpc_cidr   = var.vpc_cidr

  # Monitoring and notifications
  sns_topic_arn = var.sns_topic_arn

  # Common tags
  tags = merge(
    var.tags,
    {
      Environment = local.environment
      Service     = "notification-service"
      Managed_by  = "terraform"
    }
  )
}

# ElastiCache Redis Cluster
resource "aws_elasticache_cluster" "redis" {
  cluster_id           = local.cluster_name
  engine              = "redis"
  engine_version      = "7.0"
  node_type           = local.node_type
  num_cache_nodes     = local.num_cache_nodes
  parameter_group_name = "default.redis7"
  port                = 6379

  # Network configuration
  subnet_group_name  = aws_elasticache_subnet_group.main.name
  security_group_ids = [aws_security_group.redis.id]

  # Maintenance and backup settings
  maintenance_window      = "sun:05:00-sun:06:00"
  notification_topic_arn  = local.sns_topic_arn
  snapshot_retention_limit = 7
  snapshot_window        = "03:00-04:00"

  # Performance and availability settings
  az_mode = local.num_cache_nodes > 1 ? "cross-az" : "single-az"

  # Cache parameters
  parameter_group_name = aws_elasticache_parameter_group.redis.name

  # Tags
  tags = local.tags
}

# ElastiCache Parameter Group
resource "aws_elasticache_parameter_group" "redis" {
  family = "redis7"
  name   = "${local.environment}-${local.cluster_name}-params"

  # Cache settings based on requirements
  parameter {
    name  = "maxmemory-policy"
    value = "volatile-lru"  # LRU eviction for keys with TTL
  }

  parameter {
    name  = "maxmemory-samples"
    value = "10"  # Recommended for production workloads
  }

  tags = local.tags
}

# Subnet Group for Multi-AZ deployment
resource "aws_elasticache_subnet_group" "main" {
  name       = "${local.environment}-${local.cluster_name}-redis"
  subnet_ids = local.subnet_ids

  tags = local.tags
}

# Security Group for Redis access
resource "aws_security_group" "redis" {
  name        = "${local.environment}-${local.cluster_name}-redis"
  description = "Security group for Redis cluster access"
  vpc_id      = local.vpc_id

  # Redis port ingress
  ingress {
    from_port   = 6379
    to_port     = 6379
    protocol    = "tcp"
    cidr_blocks = [local.vpc_cidr]
    description = "Allow Redis access from within VPC"
  }

  # Allow all egress
  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
    description = "Allow all outbound traffic"
  }

  tags = merge(
    local.tags,
    {
      Name = "${local.environment}-${local.cluster_name}-redis-sg"
    }
  )
}

# CloudWatch Alarms for monitoring
resource "aws_cloudwatch_metric_alarm" "cache_cpu" {
  alarm_name          = "${local.environment}-${local.cluster_name}-cpu-utilization"
  alarm_description   = "Redis cluster CPU utilization"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = "2"
  metric_name        = "CPUUtilization"
  namespace          = "AWS/ElastiCache"
  period             = "300"
  statistic          = "Average"
  threshold          = "75"
  alarm_actions      = [local.sns_topic_arn]
  ok_actions         = [local.sns_topic_arn]

  dimensions = {
    CacheClusterId = aws_elasticache_cluster.redis.id
  }

  tags = local.tags
}

resource "aws_cloudwatch_metric_alarm" "cache_memory" {
  alarm_name          = "${local.environment}-${local.cluster_name}-memory-utilization"
  alarm_description   = "Redis cluster memory utilization"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = "2"
  metric_name        = "DatabaseMemoryUsagePercentage"
  namespace          = "AWS/ElastiCache"
  period             = "300"
  statistic          = "Average"
  threshold          = "80"
  alarm_actions      = [local.sns_topic_arn]
  ok_actions         = [local.sns_topic_arn]

  dimensions = {
    CacheClusterId = aws_elasticache_cluster.redis.id
  }

  tags = local.tags
}