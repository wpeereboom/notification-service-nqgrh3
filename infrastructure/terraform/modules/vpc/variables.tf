# Terraform variable definitions for VPC module
# Version: ~> 1.5

variable "environment" {
  type        = string
  description = "Environment name (e.g., prod, staging, dev)"
  
  validation {
    condition     = contains(["prod", "staging", "dev"], var.environment)
    error_message = "Environment must be one of: prod, staging, dev"
  }
}

variable "vpc_cidr" {
  type        = string
  description = "CIDR block for the VPC"
  default     = "10.0.0.0/16"

  validation {
    condition     = can(cidrhost(var.vpc_cidr, 0))
    error_message = "VPC CIDR block must be a valid IPv4 CIDR notation"
  }
}

variable "availability_zones" {
  type        = list(string)
  description = "List of availability zones for multi-AZ deployment"

  validation {
    condition     = length(var.availability_zones) >= 2
    error_message = "At least 2 availability zones must be specified for high availability"
  }
}

variable "enable_nat_gateway" {
  type        = bool
  description = "Whether to create NAT Gateway for private subnets"
  default     = true
}

variable "enable_dns_hostnames" {
  type        = bool
  description = "Whether to enable DNS hostnames in the VPC"
  default     = true
}

variable "tags" {
  type        = map(string)
  description = "Tags to apply to all resources"
  default     = {}

  validation {
    condition     = can([for k, v in var.tags : regex("^[\\w\\s\\-\\.\\:]+$", v)])
    error_message = "Tag values can only contain alphanumeric characters, spaces, and the following special characters: - . :"
  }
}

# Additional validation for production environment requirements
variable "production_requirements" {
  type        = map(bool)
  description = "Required settings for production environment"
  default = {
    multi_az          = true
    private_subnets   = true
    nat_gateway       = true
    dns_hostnames     = true
  }
}

# Network ACL rules variable for security configuration
variable "network_acls" {
  type = map(list(object({
    rule_number = number
    protocol    = string
    action      = string
    cidr_block  = string
    from_port   = number
    to_port     = number
  })))
  description = "Network ACL rules for VPC subnets"
  default     = {}
}

# Flow log configuration
variable "enable_flow_logs" {
  type        = bool
  description = "Whether to enable VPC flow logs"
  default     = true
}

variable "flow_logs_retention_days" {
  type        = number
  description = "Number of days to retain VPC flow logs"
  default     = 30

  validation {
    condition     = var.flow_logs_retention_days >= 30
    error_message = "Flow logs retention must be at least 30 days for compliance"
  }
}