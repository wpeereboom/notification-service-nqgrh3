# Primary instance connection details
output "primary_endpoint" {
  description = "Connection endpoint for the primary RDS instance"
  value       = aws_db_instance.primary.endpoint
  sensitive   = false
}

output "primary_address" {
  description = "DNS address of the primary RDS instance"
  value       = aws_db_instance.primary.address
  sensitive   = false
}

output "primary_port" {
  description = "Port number on which the primary database accepts connections"
  value       = aws_db_instance.primary.port
  sensitive   = false
}

output "database_name" {
  description = "Name of the default database"
  value       = aws_db_instance.primary.db_name
  sensitive   = false
}

# Read replica connection details
output "replica_endpoint" {
  description = "Connection endpoint for the read replica RDS instance"
  value       = length(aws_db_instance.replica) > 0 ? aws_db_instance.replica[0].endpoint : null
  sensitive   = false
}

output "replica_address" {
  description = "DNS address of the read replica RDS instance"
  value       = length(aws_db_instance.replica) > 0 ? aws_db_instance.replica[0].address : null
  sensitive   = false
}

output "replica_port" {
  description = "Port number on which the read replica accepts connections"
  value       = length(aws_db_instance.replica) > 0 ? aws_db_instance.replica[0].port : null
  sensitive   = false
}

# Formatted connection string
output "connection_string" {
  description = "PostgreSQL connection string for the primary instance"
  value       = "postgresql://${aws_db_instance.primary.username}@${aws_db_instance.primary.endpoint}/${aws_db_instance.primary.db_name}"
  sensitive   = true # Marked sensitive as it contains authentication details
}

# Instance configuration details
output "instance_class" {
  description = "RDS instance class in use"
  value       = aws_db_instance.primary.instance_class
  sensitive   = false
}

output "multi_az_enabled" {
  description = "Whether Multi-AZ deployment is enabled"
  value       = aws_db_instance.primary.multi_az
  sensitive   = false
}

# Enhanced monitoring details
output "monitoring_interval" {
  description = "Enhanced monitoring interval in seconds"
  value       = aws_db_instance.primary.monitoring_interval
  sensitive   = false
}

output "monitoring_role_arn" {
  description = "ARN of the IAM role used for enhanced monitoring"
  value       = aws_iam_role.rds_monitoring.arn
  sensitive   = false
}

# Storage configuration
output "allocated_storage" {
  description = "Amount of allocated storage in gibibytes"
  value       = aws_db_instance.primary.allocated_storage
  sensitive   = false
}

output "storage_type" {
  description = "Storage type associated with the RDS instance"
  value       = aws_db_instance.primary.storage_type
  sensitive   = false
}

# Backup configuration
output "backup_retention_period" {
  description = "Number of days for which automated backups are retained"
  value       = aws_db_instance.primary.backup_retention_period
  sensitive   = false
}

output "backup_window" {
  description = "Daily time range during which automated backups are created"
  value       = aws_db_instance.primary.backup_window
  sensitive   = false
}

# Performance insights
output "performance_insights_enabled" {
  description = "Whether Performance Insights is enabled"
  value       = aws_db_instance.primary.performance_insights_enabled
  sensitive   = false
}

# Resource identifiers
output "db_parameter_group_name" {
  description = "Name of the DB parameter group"
  value       = aws_db_parameter_group.postgres14.name
  sensitive   = false
}

output "db_subnet_group_name" {
  description = "Name of the DB subnet group"
  value       = aws_db_subnet_group.main.name
  sensitive   = false
}