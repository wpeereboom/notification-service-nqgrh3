# VPC Outputs
output "vpc_id" {
  description = "The ID of the VPC used for network resource association"
  value       = aws_vpc.main.id
}

output "vpc_cidr" {
  description = "The CIDR block of the VPC used for network planning and security group rules"
  value       = aws_vpc.main.cidr_block
}

# Subnet Outputs
output "public_subnet_ids" {
  description = "List of public subnet IDs across availability zones for load balancer deployment"
  value       = [for subnet in aws_subnet.public : subnet.id]
}

output "private_subnet_ids" {
  description = "List of private subnet IDs across availability zones for secure application deployment"
  value       = [for subnet in aws_subnet.private : subnet.id]
}

# Additional Network Resource Outputs
output "internet_gateway_id" {
  description = "ID of the Internet Gateway attached to the VPC"
  value       = aws_internet_gateway.main.id
}

output "nat_gateway_ids" {
  description = "List of NAT Gateway IDs provisioned for private subnet internet access"
  value       = var.enable_nat_gateway ? [for nat in aws_nat_gateway.main : nat.id] : []
}

output "availability_zones" {
  description = "List of availability zones used for subnet deployment"
  value       = var.availability_zones
}

# Route Table Outputs
output "public_route_table_id" {
  description = "ID of the public route table for internet-facing resources"
  value       = aws_route_table.public.id
}

output "private_route_table_ids" {
  description = "List of private route table IDs for internal resources"
  value       = [for rt in aws_route_table.private : rt.id]
}

# VPC Flow Log Outputs
output "flow_log_group_name" {
  description = "Name of the CloudWatch Log Group for VPC flow logs"
  value       = var.enable_flow_logs ? aws_cloudwatch_log_group.flow_logs[0].name : null
}

output "flow_log_role_arn" {
  description = "ARN of the IAM role used for VPC flow logs"
  value       = var.enable_flow_logs ? aws_iam_role.flow_logs[0].arn : null
}

# Network Metadata
output "vpc_metadata" {
  description = "Map of VPC metadata including environment and deployment information"
  value = {
    environment = var.environment
    dns_hostnames_enabled = var.enable_dns_hostnames
    nat_gateway_enabled   = var.enable_nat_gateway
    flow_logs_enabled     = var.enable_flow_logs
  }
}