# SSE scaling notes

## Connection model

Each active SSE client (sse.php) holds **two PostgreSQL connections**:

| Connection | Purpose |
|---|---|
| PDO (`new PDO(...)`) | Issues `LISTEN` commands; participates in session auth |
| `pg_connect(...)` | Non-blocking `pg_get_notify()` poll every 200 ms |

PostgreSQL's default `max_connections = 100`. With ~10 connections reserved for web requests, admin queries, and migrations, the effective SSE capacity is:

```
floor((100 - 10) / 2) = 45 concurrent SSE users
```

## Recommended postgresql.conf changes

```ini
# /etc/postgresql/16/main/postgresql.conf
max_connections = 200        # allows ~90 concurrent SSE users with headroom
shared_buffers  = 256MB      # raise alongside max_connections
```

After editing, reload: `systemctl reload postgresql`

## Apache MPM limits

Each SSE request holds an Apache worker for its lifetime (up to `MAX_LIFETIME = 3600 s`).
For `mpm_prefork` (default with mod_php):

```apache
# /etc/apache2/mods-available/mpm_prefork.conf
<IfModule mpm_prefork_module>
    StartServers        5
    MinSpareServers     5
    MaxSpareServers    10
    MaxRequestWorkers  80    # leave 20 headroom for short web requests
    MaxConnectionsPerChild 0
</IfModule>
```

For `mpm_event` (PHP-FPM):
- SSE connections park in `keep-alive` state and don't consume FPM workers.
- Set `MaxRequestWorkers` high enough for concurrent SSE sockets.

## Proxy / load-balancer considerations

Reverse proxies (nginx, AWS ALB) buffer responses by default. This breaks SSE.

**nginx:**
```nginx
location /sse.php {
    proxy_pass         http://backend;
    proxy_buffering    off;
    proxy_cache        off;
    proxy_read_timeout 3700s;   # slightly longer than MAX_LIFETIME
    chunked_transfer_encoding on;
}
```

**Apache as reverse proxy:**
The `X-Accel-Buffering: no` header set in the VHost config signals nginx-compatible proxies to disable buffering.

## Future migration path: dedicated Node.js SSE process

At >200 concurrent SSE users the two-connections-per-user model becomes a bottleneck. The recommended migration path:

1. Create a Node.js process that connects once to PostgreSQL with `LISTEN` on all channels.
2. Maintain a Map of `channel → Set<SSEResponse>` for fan-out.
3. Route `/sse.php` traffic to the Node process via `ProxyPass`.
4. The PHP API layer continues to write notifications normally (PostgreSQL `NOTIFY` fires automatically via the trigger in `006_sse.sql`).

This reduces PostgreSQL connections from `2 × N` to `1` regardless of SSE user count.
