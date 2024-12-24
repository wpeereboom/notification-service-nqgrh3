# Environment configuration
variable "environment" {
  type        = string
  description = "Deployment environment (prod, staging, dev)"
  
  validation {
    condition     = contains(["prod", "staging", "dev"], var.environment)
    error_message = "Environment must be one of: prod, staging, dev."
  }
}

# Instance configuration
variable "db_instance_class" {
  type        = string
  default     = "db.r6g.xlarge"
  description = "RDS instance class for the database server"

  validation {
    condition     = can(regex("^db\\.[a-z0-9]+\\.[a-z0-9]+$", var.db_instance_class))
    error_message = "DB instance class must be a valid RDS instance type."
  }
}

variable "engine_version" {
  type        = string
  default     = "14.7"
  description = "PostgreSQL engine version"

  validation {
    condition     = can(regex("^14\\.", var.engine_version))
    error_message = "PostgreSQL version must be 14.x as per requirements."
  }
}

variable "allocated_storage" {
  type        = number
  description = "Allocated storage size in GB"

  validation {
    condition     = var.allocated_storage >= 20 && var.allocated_storage <= 65536
    error_message = "Allocated storage must be between 20 GB and 65536 GB."
  }
}

# Database configuration
variable "db_name" {
  type        = string
  description = "Name of the PostgreSQL database"

  validation {
    condition     = can(regex("^[a-zA-Z][a-zA-Z0-9_]*$", var.db_name))
    error_message = "Database name must start with a letter and contain only alphanumeric characters and underscores."
  }
}

variable "db_username" {
  type        = string
  sensitive   = true
  description = "Master username for database access"

  validation {
    condition     = can(regex("^[a-zA-Z][a-zA-Z0-9_]*$", var.db_username))
    error_message = "Username must start with a letter and contain only alphanumeric characters and underscores."
  }
}

variable "db_password" {
  type        = string
  sensitive   = true
  description = "Master password for database access"

  validation {
    condition     = length(var.db_password) >= 16
    error_message = "Password must be at least 16 characters long."
  }
}

# High availability configuration
variable "multi_az" {
  type        = bool
  default     = true
  description = "Enable Multi-AZ deployment for high availability"
}

variable "backup_retention_period" {
  type        = number
  default     = 30
  description = "Number of days to retain automated backups"

  validation {
    condition     = var.backup_retention_period >= 7 && var.backup_retention_period <= 35
    error_message = "Backup retention period must be between 7 and 35 days."
  }
}

# Network configuration
variable "private_subnet_ids" {
  type        = list(string)
  description = "List of private subnet IDs for RDS deployment"

  validation {
    condition     = length(var.private_subnet_ids) >= 2
    error_message = "At least two private subnets are required for high availability."
  }
}

variable "vpc_security_group_ids" {
  type        = list(string)
  description = "List of security group IDs for RDS access"

  validation {
    condition     = length(var.vpc_security_group_ids) > 0
    error_message = "At least one security group ID must be provided."
  }
}

# Performance configuration
variable "max_connections" {
  type        = number
  default     = 1000
  description = "Maximum number of database connections"

  validation {
    condition     = var.max_connections >= 100 && var.max_connections <= 5000
    error_message = "Maximum connections must be between 100 and 5000."
  }
}

# Resource tagging
variable "tags" {
  type        = map(string)
  default     = {}
  description = "Resource tags for RDS instances and related resources"

  validation {
    condition     = length(coalesce(var.tags["Environment"], "")) > 0
    error_message = "Environment tag is required."
  }
}

# Monitoring configuration
variable "monitoring_interval" {
  type        = number
  default     = 60
  description = "Enhanced monitoring interval in seconds"

  validation {
    condition     = contains([0, 1, 5, 10, 15, 30, 60], var.monitoring_interval)
    error_message = "Monitoring interval must be one of: 0, 1, 5, 10, 15, 30, 60."
  }
}

# Storage configuration
variable "storage_type" {
  type        = string
  default     = "gp3"
  description = "Storage type for the RDS instance"

  validation {
    condition     = contains(["gp2", "gp3", "io1"], var.storage_type)
    error_message = "Storage type must be one of: gp2, gp3, io1."
  }
}

variable "storage_encrypted" {
  type        = bool
  default     = true
  description = "Enable storage encryption for the RDS instance"
}