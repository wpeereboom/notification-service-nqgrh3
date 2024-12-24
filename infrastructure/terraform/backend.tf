# Backend Configuration for Notification Service Infrastructure
# Version: 1.5+ (Terraform)
# Purpose: Defines state storage and locking mechanism for infrastructure management

terraform {
  # AWS S3 Backend Configuration
  backend "s3" {
    # State file storage configuration
    bucket = "notification-service-terraform-state"
    key    = "${var.environment}/terraform.tfstate"
    region = "us-east-1"

    # Enable server-side encryption for state file
    encrypt = true

    # State locking using DynamoDB
    dynamodb_table = "notification-service-terraform-locks"

    # Workspace configuration for environment isolation
    workspace_key_prefix = "notification-service"

    # Additional security configurations
    force_path_style = false
    kms_key_id      = "alias/terraform-state-key"

    # Versioning and backup configurations
    versioning = true

    # Access control
    acl = "private"

    # Enable logging for audit trail
    logging {
      target_bucket = "notification-service-logs"
      target_prefix = "terraform-state/"
    }

    # Object lifecycle rules
    lifecycle_rule {
      enabled = true

      noncurrent_version_transition {
        days          = 30
        storage_class = "STANDARD_IA"
      }

      noncurrent_version_expiration {
        days = 90
      }
    }

    # Cross-region replication for disaster recovery
    replication_configuration {
      role = "arn:aws:iam::ACCOUNT_ID:role/terraform-state-replication-role"

      rules {
        id     = "backup-to-west"
        status = "Enabled"

        destination {
          bucket        = "arn:aws:s3:::notification-service-terraform-state-backup"
          storage_class = "STANDARD_IA"
          region       = "us-west-2"

          # Enable encryption for replicated objects
          encryption_configuration {
            replica_kms_key_id = "arn:aws:kms:us-west-2:ACCOUNT_ID:key/backup-key-id"
          }
        }
      }
    }

    # Tags for resource management
    tags = {
      Environment = var.environment
      Service     = "notification-service"
      ManagedBy   = "terraform"
      Purpose     = "terraform-state-storage"
    }
  }

  # Required provider versions
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 4.0"
    }
  }

  # Minimum required Terraform version
  required_version = ">= 1.5.0"
}