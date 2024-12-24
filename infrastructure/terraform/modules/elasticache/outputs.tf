# Output the ElastiCache cluster identifier
output "cluster_id" {
  value       = aws_elasticache_cluster.redis.id
  description = "The ID of the ElastiCache Redis cluster"
}

# Output the primary endpoint for Redis cluster access
output "primary_endpoint" {
  value       = aws_elasticache_cluster.redis.cache_nodes[0].address
  description = "The primary endpoint address for the Redis cluster"
}

# Output the port number for Redis cluster access
output "port" {
  value       = aws_elasticache_cluster.redis.port
  description = "The port number on which the Redis cluster accepts connections"
}

# Output the security group ID for Redis cluster
output "security_group_id" {
  value       = aws_security_group.redis.id
  description = "The ID of the security group associated with the Redis cluster"
}

# Output the subnet group name for Redis cluster
output "subnet_group_name" {
  value       = aws_elasticache_subnet_group.main.name
  description = "The name of the subnet group where the Redis cluster is deployed"
}

# Output the parameter group name
output "parameter_group_name" {
  value       = aws_elasticache_parameter_group.redis.name
  description = "The name of the parameter group used by the Redis cluster"
}

# Output the Redis version
output "redis_version" {
  value       = aws_elasticache_cluster.redis.engine_version
  description = "The version of Redis running on the cluster"
}

# Output the maintenance window
output "maintenance_window" {
  value       = aws_elasticache_cluster.redis.maintenance_window
  description = "The weekly time range for maintenance operations"
}

# Output the number of cache nodes
output "num_cache_nodes" {
  value       = aws_elasticache_cluster.redis.num_cache_nodes
  description = "The number of cache nodes in the cluster"
}

# Output the node type
output "node_type" {
  value       = aws_elasticache_cluster.redis.node_type
  description = "The compute and memory capacity of the nodes"
}