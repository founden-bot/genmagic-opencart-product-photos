#!/usr/bin/env bash
# Downloads the newest official OpenCart release of a series from github.com/opencart/opencart, unpacks it into
# <target> and installs it with its own command line installer (install/cli_install.php).
#
#   install-opencart.sh <series, e.g. 4.1.0> <target-dir> [base-url]
#
# Environment: DB_HOST DB_PORT DB_USER DB_PASS DB_NAME (defaults match the workflow's MySQL service), GITHUB_TOKEN.
set -euo pipefail

series="${1:?series like 4.1.0}"
target="${2:?target directory}"
base_url="${3:-http://127.0.0.1:8080/}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
DB_NAME="${DB_NAME:-opencart}"

work="$(mktemp -d "${RUNNER_TEMP:-/tmp}/oc-download.XXXXXX")"

auth=()
if [ -n "${GITHUB_TOKEN:-}" ]; then
  auth=(-H "Authorization: Bearer ${GITHUB_TOKEN}")
fi

# The newest published (not pre-release) release whose tag starts with the series, and its release zip.
curl -fsSL "${auth[@]}" -H 'Accept: application/vnd.github+json' \
  'https://api.github.com/repos/opencart/opencart/releases?per_page=100' -o "$work/releases.json"

url="$(jq -r --arg series "$series." '
  [ .[] | select(.prerelease == false and (.tag_name | startswith($series))) ]
  | sort_by(.tag_name | split(".") | map(tonumber? // 0))
  | last
  | .assets[]? | select(.name | test("^opencart-.*\\.zip$")) | .browser_download_url' "$work/releases.json" | head -n 1)"

tag="$(jq -r --arg series "$series." '
  [ .[] | select(.prerelease == false and (.tag_name | startswith($series))) ]
  | sort_by(.tag_name | split(".") | map(tonumber? // 0)) | last | .tag_name' "$work/releases.json")"

if [ -z "$url" ] || [ "$url" = "null" ]; then
  echo "ERROR: no published OpenCart release found for series ${series}"
  exit 1
fi

echo "OpenCart release ${tag}: ${url}"
curl -fsSL -o "$work/opencart.zip" "$url"

mkdir -p "$work/unpacked"
unzip -q "$work/opencart.zip" -d "$work/unpacked"

upload="$(find "$work/unpacked" -maxdepth 3 -type d -name upload | head -n 1)"
if [ -z "$upload" ] || [ ! -f "$upload/install/cli_install.php" ]; then
  echo "ERROR: the release zip has no upload/install/cli_install.php"
  exit 1
fi

mkdir -p "$target"
cp -R "$upload/." "$target/"

cd "$target"
cp config-dist.php config.php
cp admin/config-dist.php admin/config.php
chmod 666 config.php admin/config.php
chmod -R 777 system/storage image

php install/cli_install.php install \
  --username admin --email admin@example.com --password password \
  --http_server "$base_url" \
  --db_driver mysqli --db_hostname "$DB_HOST" --db_username "$DB_USER" --db_password "$DB_PASS" \
  --db_database "$DB_NAME" --db_port "$DB_PORT" --db_prefix oc_

# As on a live store: the installer folder is removed once the shop is installed.
rm -rf install

grep -E "define\('OPENCART_VERSION'|OPENCART_VERSION" system/startup.php | head -n 1 || true
echo "installed ${tag} into ${target}"
