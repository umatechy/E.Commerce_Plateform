#!/usr/bin/env bash
# Umar Techy E-Commerce — READ-ONLY server check before deployment.
#
# Changes nothing: it only reads system facts, Docker's state and the
# listening ports, then says whether the chosen port and subnet are free.
# Safe to run on the shared server next to School ERP.
#
#   bash deploy/production/preflight.sh [port] [subnet]
#   bash deploy/production/preflight.sh 8085 172.30.240.0/24
set -uo pipefail

PORT="${1:-8085}"
SUBNET="${2:-172.30.240.0/24}"

section() { printf '\n== %s ==\n' "$1"; }

section "System"
uname -srm
[ -r /etc/os-release ] && . /etc/os-release && echo "$PRETTY_NAME"
echo "CPUs: $(nproc)"
free -h
echo
df -h / /var/lib/docker /opt 2>/dev/null | awk 'NR==1 || !seen[$1]++'
uptime

section "Docker"
docker --version 2>/dev/null || echo "docker: not found"
docker compose version 2>/dev/null || echo "docker compose: not found"
echo
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null
echo
echo "Memory and CPU in use now:"
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' 2>/dev/null

section "Docker networks and their subnets"
for net in $(docker network ls -q 2>/dev/null); do
    docker network inspect -f '{{.Name}}  {{range .IPAM.Config}}{{.Subnet}} {{end}}' "$net" 2>/dev/null
done

section "Host routes"
ip -4 route 2>/dev/null

section "Listening TCP ports"
ss -tlnp 2>/dev/null | awk 'NR==1 || /LISTEN/'

section "Web server"
if command -v nginx >/dev/null 2>&1; then
    echo "Nginx on the host: $(nginx -v 2>&1)"
    ls -1 /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null
else
    echo "No Nginx on the host. A container may hold ports 80/443 (see Docker above)."
fi
command -v certbot >/dev/null 2>&1 && echo "certbot: $(certbot --version 2>&1)" || echo "certbot: not on the host"

section "Verdict"
ok=1
if ss -tln 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]${PORT}\$"; then
    echo "PORT ${PORT}: IN USE — choose another ECOM_HTTP_PORT."
    ok=0
else
    echo "PORT ${PORT}: free."
fi
prefix="${SUBNET%.*}"
if docker network inspect $(docker network ls -q 2>/dev/null) -f '{{range .IPAM.Config}}{{.Subnet}} {{end}}' 2>/dev/null | grep -q "${prefix}\." \
    || ip -4 route 2>/dev/null | grep -q "${prefix}\."; then
    echo "SUBNET ${SUBNET}: overlaps something above — choose another ECOM_APP_SUBNET."
    ok=0
else
    echo "SUBNET ${SUBNET}: no overlap found."
fi
if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -q '^umartechy-ecom-'; then
    echo "Note: containers of the umartechy-ecom project already exist."
fi
[ "$ok" = 1 ] && echo "Ready for the next step (nothing was changed)." || echo "Fix the items above first (nothing was changed)."
