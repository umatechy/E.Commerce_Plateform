#!/usr/bin/env bash
# Umar Techy E-Commerce — put one secret into the server's .env without it
# ever appearing in a chat, a command line, the shell history or a log.
#
# The value is read from standard input and never printed. Only the keys
# below are accepted. Run it from Windows PowerShell, which asks for the
# value without showing it:
#
#   $s = Read-Host "Value" -AsSecureString
#   [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s)) |
#     ssh root@2.29.20.203 "bash /opt/umartechy-ecommerce/deploy/production/set-secret.sh MAIL_PASSWORD"
#
# The app picks the new value up when its containers are recreated
# (deploy.sh, or: docker compose ... up -d app worker scheduler).
set -euo pipefail

ALLOWED="MAIL_PASSWORD BACKUP_S3_ACCESS_KEY BACKUP_S3_SECRET_KEY"
KEY="${1:-}"
case " $ALLOWED " in
    *" $KEY "*) ;;
    *) echo "set-secret: the key must be one of: $ALLOWED" >&2; exit 2 ;;
esac

ENV_FILE="$(cd "$(dirname "$0")/../.." && pwd)/.env"
[ -f "$ENV_FILE" ] || { echo "set-secret: $ENV_FILE not found" >&2; exit 1; }

IFS= read -r VALUE || true
VALUE="${VALUE%$'\r'}"
[ -n "$VALUE" ] || { echo "set-secret: no value received; nothing changed" >&2; exit 1; }
case "$VALUE" in
    *"'"*) echo "set-secret: the value contains a single quote ('), which .env cannot hold safely; choose another value" >&2; exit 1 ;;
esac

# Written as KEY='value' (taken literally by Docker Compose), replacing any
# earlier line for the key; the file stays mode 600.
umask 077
tmp="$(mktemp "${ENV_FILE}.XXXXXX")"
KEY="$KEY" VALUE="$VALUE" awk '
    BEGIN { key = ENVIRON["KEY"]; line = key "='\''" ENVIRON["VALUE"] "'\''"; done = 0 }
    $0 ~ "^#? *" key "=" { if (!done) { print line; done = 1 } ; next }
    { print }
    END { if (!done) print line }
' "$ENV_FILE" > "$tmp"
chmod 600 "$tmp"
mv "$tmp" "$ENV_FILE"
echo "set-secret: $KEY is set (${#VALUE} characters). Recreate the app containers to use it."
