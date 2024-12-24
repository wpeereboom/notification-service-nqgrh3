# Basic cluster configuration
variable "cluster_name" {
  type        = string
  description = "Name identifier for the ElastiCache Redis cluster"
}

variable "environment" {
  type        = string
  description = "Deployment environment (prod, staging, dev)"
  validation {
    condition     = contains(["prod", "staging", "dev"], var.environment)
    error_message = "Environment must be one of: prod, staging, dev"
  }
}

# Instance configuration
variable "node_type" {
  type        = string
  description = "ElastiCache node instance type (e.g., cache.r6g.large for production workloads)"
  default     = "cache.r6g.large"
}

variable "num_cache_nodes" {
  type        = number
  description = "Number of cache nodes in the cluster (minimum 2 for Multi-AZ in production)"
  default     = 2
  validation {
    condition     = var.num_cache_nodes >= 1
    error_message = "Number of cache nodes must be at least 1"
  }
}

# Network configuration
variable "subnet_ids" {
  type        = list(string)
  description = "List of subnet IDs for ElastiCache deployment (should be in different AZs for HA)"
}

variable "vpc_id" {
  type        = string
  description = "ID of the VPC where ElastiCache will be deployed"
}

variable "vpc_cidr" {
  type        = string
  description = "CIDR block of the VPC for security group rules"
}

# Monitoring and notifications
variable "sns_topic_arn" {
  type        = string
  description = "ARN of SNS topic for ElastiCache notifications and alerts"
}

# Resource tagging
variable "tags" {
  type        = map(string)
  description = "Resource tags for cost allocation and organization"
  default     = {}
}

# Redis specific configuration
variable "engine_version" {
  type        = string
  description = "Redis engine version"
  default     = "7.0"
}

variable "port" {
  type        = number
  description = "Port number for Redis connections"
  default     = 6379
}

variable "parameter_group_family" {
  type        = string
  description = "Redis parameter group family"
  default     = "redis7"
}

# Performance and maintenance settings
variable "maintenance_window" {
  type        = string
  description = "Preferred maintenance window (UTC)"
  default     = "sun:05:00-sun:07:00"
}

variable "snapshot_retention_limit" {
  type        = number
  description = "Number of days to retain automatic snapshots"
  default     = 7
}

variable "snapshot_window" {
  type        = string
  description = "Daily time range when snapshots are created"
  default     = "03:00-05:00"
}

variable "apply_immediately" {
  type        = bool
  description = "Whether to apply changes immediately or during maintenance window"
  default     = false
}

# Security settings
variable "at_rest_encryption_enabled" {
  type        = bool
  description = "Whether to enable encryption at rest"
  default     = true
}

variable "transit_encryption_enabled" {
  type        = bool
  description = "Whether to enable encryption in transit"
  default     = true
}

# Cache settings as specified in technical requirements
variable "maxmemory" {
  type        = number
  description = "Maximum memory limit in MB (512MB as per requirements)"
  default     = 512
}

variable "maxmemory_policy" {
  type        = string
  description = "Memory eviction policy (LRU as per requirements)"
  default     = "volatile-lru"
}

variable "timeout" {
  type        = number
  description = "Connection timeout in seconds (supports 1 hour TTL requirement)"
  default     = 3600
}