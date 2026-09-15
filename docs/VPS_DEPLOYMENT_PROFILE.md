# VPS Deployment Profile

## 1. Purpose

This document is a portable operational profile for the VPS behind the SSH alias `vps-probono-apps`. It is meant to help future AI/IDE agents understand the server before designing or deploying a new application.

It intentionally records only architecture, conventions, constraints, and safe operational facts. No secrets, private keys, access credentials, or live environment values are included.

## 2. Server Identity

- Hostname: `vps-probono-apps`
- Operating system: Ubuntu 24.04.5 LTS
- Kernel: Linux 6.8.0-139-generic
- Architecture: x86_64
- Virtualization / provider: KVM on DigitalOcean Droplet
- Time zone: UTC
- Uptime at audit: approximately 3 days, 21 hours
- Current login context observed: `admin-syedazlan` is the visible system user; root shell was used for read-only audit work via `sudo -n`

## 3. Hardware & Capacity

- CPU: 2 vCPU (`Intel DO-Regular`, x86_64)
- RAM: 3.8 GiB total
- RAM usage: approx. 1.4 GiB used, 2.4 GiB available
- Swap: 2.0 GiB configured, approx. 533 MiB used
- Root disk: 80 GiB virtual disk
- Root filesystem: `/dev/vda1` mounted as ext4
- Root disk usage: 13 GiB used, 64 GiB free, ~17% used
- Inode usage: not a limiting factor in the observed baseline

Assessment:

- There is meaningful headroom for a small new Laravel application.
- A medium Laravel project is feasible if it is moderate in traffic, database footprint, and background jobs.
- The most likely first bottleneck for a new app is not compute but memory + database + Docker image build overhead, especially if more queues, workers, and heavy jobs are added.

## 4. Operating System

- Base OS: Ubuntu 24.04.5 LTS (Noble)
- Package manager: APT
- Auto-upgrade service is present (`unattended-upgrades.service`)
- Docker is installed on the host; the host OS is not running a host-native PHP application stack for production app work

## 5. Access & SSH

- SSH daemon: active
- SSH port: 22
- SSH bind addresses: IPv4 and IPv6 wildcard listening (`0.0.0.0:22` and `[::]:22`)
- Root login: enabled (`permitrootlogin yes`)
- Public key auth: enabled
- Password auth: enabled
- Operational convention: SSH access is direct to the host; root access is available, but the server is also used with a named user (`admin-syedazlan`) and `sudo -n` for elevated read-only actions

Relevant convention:

- `SSH_ALIAS=vps-probono-apps`
- `DEPLOY_USER` is not a fixed dedicated application user for this audit, but the host is clearly managed directly by a system admin account rather than through an orchestrator-controlled service account
- `SUDO_REQUIRED_FOR_DOCKER=YES` for privileged Docker inspection and management commands

## 6. Network Topology

Host interfaces observed:

- `lo` — loopback
- `eth0` — public network, default route, public IPv4 `188.166.218.226/20`
- `eth1` — private network, `10.104.0.2/20`
- `docker0` — default Docker bridge, `172.17.0.1/16`
- `br-3782c143a4e6` — Docker bridge for one app network, `172.18.0.0/16`
- `br-3b1641f71138` — Docker bridge for the shared database network, `172.19.0.0/16`

Default route:

- `default via 188.166.208.1 dev eth0`

Observed listening ports (host-level):

- 22/tcp — SSH
- 80/tcp — Nginx
- 443/tcp — Nginx TLS terminator
- 127.0.0.1:8081/tcp — Dockerized app proxy entrypoint for the current job
- systemd-resolved also listens on localhost DNS ports

## 7. Firewall

- Firewall status: `UFW inactive`
- No active host firewall policy was observed during the audit
- This means there is no enforced host-level restriction against listening ports or an externally reachable database port unless the container or app configuration itself is limiting access

Important consequence:

- When evaluating future applications, assume the host does not provide an additional protection layer except the application-specific Docker network and reverse proxy configuration

## 8. Host Services

Meaningful services observed:

- `docker.service` — active, Docker engine running
- `nginx.service` — active, host reverse proxy
- `ssh.service` — active
- `cron.service` — active
- `unattended-upgrades.service` — active
- `certbot` renewal via snap timer exists
- No active host-native MySQL or Redis service was seen as the primary platform pattern

Service classification:

- Host-native: Nginx, SSH, cron, unattended upgrades, systemd timers
- Dockerized: application stacks, MySQL containers, deployment images
- Shared platform resource: `probono-db` external network and `probono-mysql` shared MySQL service
- Application-specific resource: current `zb-examine` app, nginx, and scheduler services
- Legacy / orphan resource: `zb-examine-prod-db-1` is a retained MySQL container from an earlier topology and is not treated as the active production pattern

## 9. Docker Platform

- Docker version: 29.8.0
- Compose version: v5.5.1
- Storage driver: `overlayfs`
- Docker root directory: `/var/lib/docker`
- Logging driver: `json-file`
- Docker network default: bridge-based external custom networks used for app isolation and shared dependency access

Docker resource summary:

- Active containers: 5
- Local volumes: 2
- Image disk footprint: moderate, with build cache also consuming a notable amount of disk space

## 10. Container Inventory

Observed running containers:

- `zb-examine-prod-nginx-1`
  - Image: `zb-examine-nginx:<release-sha>`
  - Role: reverse proxy / static asset frontend for the app
  - Published port: `127.0.0.1:8081->80/tcp`
  - Network: `zb-examine-prod_default`
  - Health check: healthy

- `zb-examine-prod-app-1`
  - Image: `zb-examine-app:<release-sha>`
  - Role: PHP-FPM runtime for Laravel app
  - Internal port: 9000/tcp
  - Networks: `zb-examine-prod_default` and `probono-db`
  - Health check: healthy

- `zb-examine-prod-scheduler-1`
  - Image: `zb-examine-app:<release-sha>`
  - Role: Laravel scheduler worker (`php artisan schedule:work`)
  - Network: `probono-db` and `zb-examine-prod_default`

- `probono-mysql-mysql-1`
  - Image: `mysql:8.4`
  - Role: shared MySQL infrastructure service
  - Network: `probono-db`
  - Alias: `probono-mysql`
  - Volume: `probono_mysql_data`
  - Health check: healthy

- `zb-examine-prod-db-1`
  - Image: `mysql:8.4`
  - Role: retained legacy / orphan MySQL container from an older application topology
  - Network: `zb-examine-prod_default`
  - Volume: `zb-examine-prod_mysql_data`
  - Health check: healthy
  - Not part of the active production database pattern for `zb-examine`

Observed topology:

- Internet -> host Nginx -> `127.0.0.1:8081` -> `zb-examine-prod-nginx-1`
- `zb-examine-prod-nginx-1` uses `fastcgi_pass app:9000`
- `zb-examine-prod-app-1` connects to both the app network and the shared DB network
- The active production database architecture is the shared MySQL service on `probono-db` with hostname/alias `probono-mysql`
- The `zb-examine-prod-db-1` container is a retained legacy/orphan object and should not be treated as the desired database architecture for new projects

## 11. Docker Networks

Networks found:

- `bridge` — default Docker bridge
- `host` — host network
- `none` — null network
- `probono-db` — custom bridge network, subnet `172.19.0.0/16`
- `zb-examine-prod_default` — custom bridge network, subnet `172.18.0.0/16`

Network roles:

- `probono-db`: shared infrastructure network for database access between multiple services/stacks
- `zb-examine-prod_default`: per-project app network for the current app stack

Shared database pattern observed:

- The active shared MySQL service runs on the external network `probono-db`
- Multiple production services can connect to the same database network and use the service alias `probono-mysql`
- The current app configuration uses `DB_HOST=probono-mysql` in its production Compose environment
- The `zb-examine-prod-db-1` container is a retained legacy/orphan MySQL container that should not be treated as current architecture

## 12. Database Architecture

### Active production database architecture

- MySQL 8.4 is provisioned as a shared infrastructure service using Docker
- Network: `probono-db`
- Hostname / alias used by applications: `probono-mysql`
- Volume: `probono_mysql_data`
- Pattern: application containers attach to the shared Docker network and connect to the database through the network alias

This is the verified active production model for Laravel applications on this VPS.

### Retained legacy / orphan database container

- Container: `zb-examine-prod-db-1`
- Image: `mysql:8.4`
- Network: `zb-examine-prod_default`
- Volume: `zb-examine-prod_mysql_data`
- Status: retained legacy / orphan object, preserved from earlier topology and not part of the intended shared database model

Important operational rule:

- `DO NOT use this orphan container as the template for new applications.`
- `DO NOT remove it casually because retained infrastructure may require separate cleanup analysis.`
- Future Laravel applications should normally follow the established shared database model unless a project specifically requires a separate database lifecycle or isolation.

## 13. Reverse Proxy Architecture

The host reverse proxy is Nginx.

Observed config layout:

- `/etc/nginx/nginx.conf`
- `/etc/nginx/sites-available/zb-examine`
- `/etc/nginx/sites-enabled/zb-examine` symlink

Observed domain mapping:

- `zb-examine.sydigitalsolution.com` -> `https://zb-examine.sydigitalsolution.com`
- `www.zb-examine.sydigitalsolution.com` -> redirect to apex domain
- Request handling: TLS terminates on Nginx at 443 and proxies to `http://127.0.0.1:8081`
- Upstream port 8081 is served by the Dockerized Nginx container

Observed reverse-proxy conventions:

- default host server on port 80 returns `444` to orphan traffic
- ACME challenge handling uses `/var/www/letsencrypt/.well-known/acme-challenge/`
- HTTP to HTTPS redirect is enabled
- `client_max_body_size 4m` is configured for the app domain
- Additional headers: `X-Content-Type-Options` and `X-Frame-Options`

## 14. Domains & TLS

- TLS tool: Certbot
- Observed certificate: `zb-examine.sydigitalsolution.com`
- Certificate validity: current and valid at audit time
- Certificate location: `/etc/letsencrypt/live/zb-examine.sydigitalsolution.com/`
- Renewal mechanism: systemd timer / snap-managed Certbot renewal

Convention for future domains:

- One domain per app, proxied via the host Nginx config
- TLS is handled at the host layer, not inside each application container
- ACME challenge is served from `/var/www/letsencrypt`
- A future domain should be added as a host Nginx server block with a matching `server_name` entry and Certbot certificate issuance through the same pattern

## 15. Existing Applications

Applications found in the server’s deployment roots:

- `/opt/zb-examine`
  - Primary production application project observed on this VPS
  - Modern Laravel app
  - Production Compose file: `compose.prod.yaml`
  - Production Dockerfile: `docker/php/Dockerfile.production`
  - Nginx production config: `docker/nginx/production.conf`
  - DB convention: uses the shared MySQL infrastructure on the `probono-db` network via `probono-mysql`

- `/opt/probono-infrastructure/mysql`
  - Shared database infrastructure, not a separate application stack
  - Compose file: `compose.yaml`
  - Named external network: `probono-db`
  - Volume: `probono_mysql_data`
  - Backup timer: `probono-mysql-backup.service` and `.timer`

Only one active application project was directly observed in the audited deployment roots. The legacy `zb-examine-prod-db-1` container is not treated as a second application deployment or as the desired production pattern.

Other notable directories:

- `/var/www/letsencrypt` — ACME challenge hosting
- `/var/www/html` — default static Nginx page, not used as a live app deployment root

## 16. Production Container Pattern

The current production application pattern is a modern Dockerized Laravel stack built around immutable release-tagged images.

Observed production pattern:

- `compose.prod.yaml`
- `docker/php/Dockerfile.production`
- Nginx runtime stage in the same Dockerfile
- App image tagged with a release/commit SHA via `RELEASE_ID`
- `app` service runs PHP-FPM on port 9000
- `nginx` service runs Nginx and forwards to the app service
- `scheduler` container runs `php artisan schedule:work`
- Health checks are configured for both app and Nginx
- Restart policy: `unless-stopped`
- Shared DB network is attached as `probono-db`
- Project network is attached as `zb-examine-prod_default`
- Frontend build is done in Docker before runtime (`npm ci`, asset build, Vite output)
- Composer dependencies are installed in a dedicated build stage; production runtime final image is optimized for PHP-FPM

This is the pattern a future Laravel project should treat as the preferred standard on this VPS.

## 17. Environment & Secret Management

Production configuration conventions:

- Project environment variables are managed through Compose environment injection and env files, not committed into Git as live secrets
- Docker services in this project use `env_file` plus explicit `environment` keys for DB configuration
- The current pattern uses names such as:
  - `DB_CONNECTION`
  - `DB_HOST`
  - `DB_PORT`
  - `DB_DATABASE`
  - `DB_USERNAME`
  - `DB_PASSWORD`
  - `APP_*`
  - `CACHE_*`
  - `SESSION_*`
  - `QUEUE_*`
  - `MAIL_*`
  - `AWS_*`
  - `FILESYSTEM_*`
- The shared MySQL infra directory includes a private env file and credential directory, confirming that production secrets are intentionally kept outside the Git repo on the host

## 18. Persistent Storage

Persistent data currently used by the app pattern:

- Docker named volumes:
  - `probono_mysql_data`
  - `zb-examine-prod_mysql_data`
- Host-level config and certificate directories:
  - `/etc/letsencrypt`
  - `/var/www/letsencrypt`
  - `/opt/probono-infrastructure/mysql`
- Dockerized application state is not being stored on a host bind mount in the app pattern reviewed here; storage is primarily handled by named volumes and containerized runtime directories

Recommended future data placement:

- MySQL data: Docker volume, not ephemeral container storage
- App runtime directories: immutable image layer plus runtime writable directories if needed
- TLS certs: host filesystem under `/etc/letsencrypt`
- Uploads / content storage: either Docker volume or object storage depending on app needs, but the server’s observed pattern prioritizes host-managed persistence and shared infrastructure rather than a single monolithic host application root

## 19. Backup Architecture

Infrastructure observed:

- Systemd timer: `probono-mysql-backup.timer`
- Service unit: `probono-mysql-backup.service`
- Timer schedule: daily at `18:15:00 UTC`
- Backup script target: `/usr/local/sbin/probono-mysql-backup-scheduled`

Conventions:

- Backups are scheduled using systemd, not ad hoc cron entries
- The backup is focused on the shared MySQL infrastructure, which is the main critical data store behind this project
- The service is designed to run with `User=root` and `UMask=0077`, consistent with secure local backup handling
- Exact remote/destination details were not inspected beyond the backup system unit and script location to avoid exposing credentials or backup destinations

## 20. Scheduled Jobs

- There are no meaningful app cron jobs visible in the host-level cron configuration for the current app
- The Laravel app uses a `scheduler` container running `php artisan schedule:work`
- The host primary cron service is active for system maintenance, but not for app orchestration as the main pattern

## 21. Logging & Diagnostics

- Docker logs use the default `json-file` driver
- Nginx logs: `/var/log/nginx/access.log` and `/var/log/nginx/error.log`
- System logs: standard systemd journal
- TLS certs: `/etc/letsencrypt/live/...`
- App logs for Laravel are stored in container runtime paths and not exposed directly on the host in the pattern reviewed here

Recommended first places to inspect during troubleshooting:

- `docker ps` and `docker inspect`
- `docker logs <container>`
- `journalctl -u docker.service`
- `journalctl -u nginx.service`
- `/var/log/nginx/access.log`
- `/var/log/nginx/error.log`
- app health checks and container statuses

## 22. Port Allocation

Authoritative port summary observed:

- 22/tcp — SSH, host
- 80/tcp — Nginx HTTP, host
- 443/tcp — Nginx HTTPS, host
- 127.0.0.1:8081/tcp — current Laravel app nginx proxy entrypoint
- 3306/tcp — MySQL service traffic on Dockerized DB stacks
- 33060/tcp — MySQL X protocol traffic on Dockerized DB stacks
- 9000/tcp — app PHP-FPM, internal to Docker network

Consequence for future projects:

- Do not choose an arbitrary host port for a new app without checking existing container mappings
- The observed pattern prefers local-only host binding for public-facing app containers, and a reverse proxy at the host level for externally reachable traffic
- The host-level port 80/443 must remain owned by Nginx

## 23. Deployment Workflow

The observed deployment flow follows a Docker image release pattern rather than a host-native app checkout pattern.

Pattern discovered:

- The app is checked out and versioned under `/opt/zb-examine`
- Production relies on `compose.prod.yaml`
- Images are built with `RELEASE_ID` equal to the full Git SHA
- Images are tagged immutably: `zb-examine-app:${RELEASE_ID}` and `zb-examine-nginx:${RELEASE_ID}`
- The app container is built from `docker/php/Dockerfile.production`
- The app and Nginx container are brought up with `docker compose` and health checks
- Scheduler container runs separately and is restarted with the app release

The deployment convention is therefore close to:

- build release image from git commit
- tag image with commit SHA
- deploy via Docker Compose using that release tag
- verify health checks before considering the service fully live

## 24. Rollback Pattern

Rollback is best handled by the same immutable image/tag pattern used by the current app:

- Reuse an earlier tagged image using the stored `RELEASE_ID`
- Redeploy the associated Compose configuration or earlier image tag
- Restore the previous health check status before considering the rollback complete

The server does not show a host-native release mechanism beyond the Docker release-tag workflow. The pattern is consistent with immutable releases rather than live mutation of a repository checkout in place.

## 25. Security Notes

Factually observed:

- UFW is inactive
- SSH is directly exposed on port 22
- `sshd -T` effective settings observed: `port 22`, `passwordauthentication yes`, `pubkeyauthentication yes`, `permitrootlogin yes`
- Nginx terminates TLS at the host and then proxies to a localhost-only app port
- Database services are Dockerized and reachable within Docker networks; no restrictive host firewall is active
- The host uses `unless-stopped` restart policies and health checks for the app
- Secret material is kept in host-local files and not committed to Git

Notable risks or constraints:

- There is no host firewall in place to reduce port exposure
- Root SSH login is enabled, which is a policy choice rather than a deployment misconfiguration
- Public database exposure can be a concern if an app is run with a broad host bind or if future services are not properly isolated

## 26. Current Capacity / Headroom

- CPU: 2 vCPU, currently low load
- RAM: 3.8 GiB total, around 2.4 GiB available
- Swap: 2 GiB exists, about 1.5 GiB free
- Disk: 80 GiB root disk with ~64 GiB available
- Docker storage and image cache use additional disk space, but the server still has room for a moderate app footprint

Current capacity assessment:

- `CURRENT_CAPACITY=MODERATE`
- `HEADROOM=GOOD FOR SMALL LARAVEL APP, ACCEPTABLE FOR MEDIUM APP WITH CAREFUL DESIGN`
- `SUITABLE_FOR_NEW_SMALL_LARAVEL_APP=YES`
- `SUITABLE_FOR_NEW_MEDIUM_LARAVEL_APP=YES, WITH CAUTION`
- `LIKELY_FIRST_RESOURCE_BOTTLENECK=RAM + shared DB + Docker build/cache usage`

## 27. New Laravel Project Blueprint

Important: this document is a snapshot of the VPS at the AUDIT_DATE. Before deployment, always re-check:

- available disk and RAM
- running containers and their statuses
- Docker networks and aliases
- occupied host ports
- Nginx site mappings
- current Docker and Compose versions

Do not assume this snapshot remains permanently current.


This is the default recommendation for a new Laravel app to be hosted on this VPS.

### REQUIRED BY VPS ARCHITECTURE

- Host Nginx owns public port 80/443
- App containers should not be directly exposed to the public Internet
- Use Docker Compose for release-tagged deployments
- Use a local-only published port such as `127.0.0.1:NNNN:80` for the app-facing Nginx service
- Use the existing external network `probono-db` for shared database access when appropriate
- Build immutable release-tagged images using a Git SHA or similar unique value
- Keep TLS termination at the host layer via Nginx and Certbot
- Treat `/opt/<project>` as the standard app root for production checkout

### RECOMMENDED CONVENTION

- Repository layout:
  - `Dockerfile.production`
  - `docker/php/Dockerfile.production`
  - `docker/nginx/production.conf`
  - `compose.prod.yaml`
  - env file managed outside Git
- Runtime architecture:
  - `app` container running PHP-FPM on port 9000, internal only
  - `nginx` container for web serving, published only on localhost on a chosen high port
  - `scheduler` container running Laravel schedule worker
  - optional `queue` worker if the app needs background jobs
- Shared infrastructure:
  - use `probono-db` as the normal external DB network if the project is not isolated
  - prefer `DB_HOST=probono-mysql` service alias for database access
- Health checks:
  - application port health check
  - Nginx health check on the upstream endpoint
  - `restart: unless-stopped`
- Asset build:
  - composer install in a build stage
  - npm ci + Vite build in a frontend stage
  - runtime image copies final built assets

### OPTIONAL / PROJECT-DEPENDENT

- A dedicated project-local MySQL container
- Extra queue workers or Horizon workers
- Redis or cache integration if the app needs more background processing
- Additional services such as sidecars or a separate database network that is justified by application requirements

## 28. Rules for Future AI Agents

- Read this document before designing production infrastructure.
- Do not create a second MySQL service unless the app clearly needs an isolated database lifecycle.
- Do not expose application containers directly to the public Internet.
- Do not pick a host port without checking current allocations.
- Do not assume a firewall is blocking ports; UFW is currently inactive.
- Do not store production secrets in Git.
- Keep app release tags immutable via a Git SHA or equivalent build ID.
- Use the host Nginx reverse proxy and Certbot flow for public HTTPS.
- Use the existing `probono-db` network pattern when a shared database is suitable.
- Use the `probono-mysql` hostname/alias as the default shared DB connection target when that architecture matches the project.
- Verify health checks before considering deployment successful.
- Do not modify shared Docker networks without understanding dependent projects.
- Treat `zb-examine-prod-db-1` as legacy/orphan infrastructure, not as a template.

## 29. Useful Read-Only Diagnostic Commands

These are safe commands for future agents to read current state without mutating the system:

```bash
hostnamectl
cat /etc/os-release
uname -a
lscpu
free -h
swapon --show
lsblk
df -h
df -i
whoami
id
sudo -n -l
sshd -T
ip addr
ip route
ss -tulpn
systemctl --type=service --state=running --no-pager
sudo -n docker ps -a
sudo -n docker images
sudo -n docker network ls
sudo -n docker volume ls
sudo -n docker system df
sudo -n docker inspect <container>
sudo -n docker network inspect <network>
certbot certificates
sudo -n ufw status verbose
sudo -n ufw status numbered
nginx -T
ls -l /etc/nginx/sites-available /etc/nginx/sites-enabled
ls -l /etc/letsencrypt/live
systemctl list-timers --all --no-pager
```

## 30. Audit Metadata

- AUDIT_DATE=2026-09-15
- VPS_HOSTNAME=vps-probono-apps
- SSH_ALIAS=vps-probono-apps
- AUDITED_BY=IDE Agent
- AUDIT_MODE=READ_ONLY
- OS=Ubuntu 24.04.5 LTS
- KERNEL=6.8.0-139-generic
- ARCH=x86_64
- HOST_PROVIDER=DigitalOcean KVM Droplet
- DOCKER_VERSION=29.8.0
- COMPOSE_VERSION=v5.5.1
- HOST_REVERSE_PROXY=Nginx
- TLS_TOOL=Certbot
- FIREWALL=inactive
- DATABASE_ARCHITECTURE=shared MySQL network plus optional app-local MySQL container
- SHARED_DATABASE_NETWORK=probono-db
- PUBLIC_APP_ENTRYPOINT=host Nginx on 80/443
- DOCUMENT_STATUS=read-only audit completed, no system modifications made
