ARG NODE_PORT=7000

FROM redis:7-alpine

RUN cat <<EOF > "/etc/redis/redis.conf"
bind 0.0.0.0
port $NODE_PORT
cluster-enabled yes
cluster-config-file nodes-${NODE_PORT}.conf
cluster-node-timeout 5000
appendonly yes
dir /redis-data/node-${NODE_PORT}
EOF
