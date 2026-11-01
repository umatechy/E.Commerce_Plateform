#!/bin/sh
# On the server, typed by the owner: puts one secret into ~/shop/shared/.env.
# The value is read without being shown and is not kept in the shell history.
#   sh set-secret.sh DB_PASSWORD
set -eu
ENV_FILE="${SHOP:-$HOME/shop}/shared/.env"
KEY="${1:-}"
case "$KEY" in
  DB_PASSWORD|BACKUP_REHEARSAL_PASSWORD|MAIL_PASSWORD) ;;
  *) echo "Use one of: DB_PASSWORD BACKUP_REHEARSAL_PASSWORD MAIL_PASSWORD"; exit 1 ;;
esac
[ -f "$ENV_FILE" ] || { echo "$ENV_FILE is missing."; exit 1; }

printf 'Paste the value for %s (nothing will show), then Enter: ' "$KEY"
stty -echo 2>/dev/null || true
IFS= read -r VALUE
stty echo 2>/dev/null || true
echo
[ -n "$VALUE" ] || { echo "Nothing entered; nothing changed."; exit 1; }

# Written by awk from the environment, so no character in it needs escaping for sed.
TMP="$(mktemp "$ENV_FILE.XXXXXX")"
chmod 600 "$TMP"
KEY="$KEY" VALUE="$VALUE" awk '
  BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VALUE"]; gsub(/\\/, "\\\\", v); gsub(/"/, "\\\"", v); gsub(/\$/, "\\$", v) }
  index($0, k "=") == 1 { print k "=\"" v "\""; done = 1; next }
  { print }
  END { if (!done) print k "=\"" v "\"" }
' "$ENV_FILE" > "$TMP"
mv "$TMP" "$ENV_FILE"
chmod 600 "$ENV_FILE"
echo "$KEY saved (${#VALUE} characters)."
