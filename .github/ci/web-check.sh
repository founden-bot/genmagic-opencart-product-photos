#!/usr/bin/env bash
# Checks the installed extension over HTTP against a running OpenCart (php -S): sign in to the admin, open the
# settings page, save settings through the real route, and look for the panel on the real product edit form.
# GenMagic is never contacted: the checks stop at the "consent" and "token" refusals.
#
#   web-check.sh <base-url, e.g. http://127.0.0.1:8080/> [product-id]
set -u

base="${1:?base url}"
base="${base%/}/"
product="${2:-28}"
route="extension/genmagic/module/genmagic_photos"
jar="$(mktemp "${RUNNER_TEMP:-/tmp}/cookies.XXXXXX")"
failed=0

pass() { echo "PASS: $1"; }
fail() { echo "FAIL: $1"; failed=1; }
check() { if [ "$1" = "0" ]; then pass "$2"; else fail "$2"; fi; }

get() { curl -sS -c "$jar" -b "$jar" "$@"; }

# ---- sign in (login form posts to common/login.login with a one-time login_token)
login_page="$(get "${base}admin/index.php?route=common/login")"
action="$(printf '%s' "$login_page" | grep -o 'action="[^"]*common/login[^"]*"' | head -n 1 | sed -e 's/^action="//' -e 's/"$//' -e 's/&amp;/\&/g')"
if [ -z "$action" ]; then
  fail "the admin login form was not found"
  printf '%s\n' "$login_page" | head -n 5
  exit 1
fi

login_reply="$(get -d 'username=admin' -d 'password=password' "$action")"
user_token="$(printf '%s' "$login_reply" | grep -o 'user_token=[A-Za-z0-9]*' | head -n 1 | cut -d= -f2)"
if [ -z "$user_token" ]; then
  fail "admin sign in did not give a user token: ${login_reply:0:300}"
  exit 1
fi
pass "signed in to the admin"

url() { echo "${base}admin/index.php?route=$1&user_token=${user_token}"; }

# ---- the product form of a signed-out visitor never shows the panel
anon="$(curl -sS "${base}admin/index.php?route=catalog/product.form&product_id=${product}")"
printf '%s' "$anon" | grep -q 'genmagic-photos'; [ $? -ne 0 ]; check $? "signed out: no panel and no extension content on the product form"

# ---- the settings page
settings="$(get "$(url "$route")")"
printf '%s' "$settings" | grep -q 'GenMagic AI Product Photos'; check $? "settings page opens"
printf '%s' "$settings" | grep -q 'name="module_genmagic_photos_consent"'; check $? "settings page has the consent checkbox"
printf '%s' "$settings" | grep -q 'name="module_genmagic_photos_api_key"'; check $? "settings page has the API key field"

# ---- save through the real route: switched on, consent still off
saved="$(get -d 'module_genmagic_photos_status=1' -d 'module_genmagic_photos_consent=0' -d 'module_genmagic_photos_api_key=gm_web_check_key_0123' "$(url "$route.save")")"
printf '%s' "$saved" | grep -q '"success"'; check $? "settings saved through the admin route: ${saved:0:160}"

settings="$(get "$(url "$route")")"
printf '%s' "$settings" | grep -q 'gm_web_check_key_0123'; [ $? -ne 0 ]; check $? "the saved key is not sent back to the browser"

# ---- the panel on the real product edit form
form="$(get "$(url "catalog/product.form&product_id=${product}")")"
printf '%s' "$form" | grep -q 'id="form-product"'; check $? "the product edit form opens"
printf '%s' "$form" | grep -q 'id="genmagic-photos"'; check $? "the GenMagic photos panel is on the product edit form (added by the event)"
printf '%s' "$form" | grep -q 'Nothing has been sent to GenMagic yet'; check $? "without consent the panel says nothing is sent"
printf '%s' "$form" | tr '\n' ' ' | grep -Eq '</form>[[:space:]]*<fieldset id="genmagic-photos"'; check $? "the panel sits right after the product form"

# ---- routes refuse what they should
reply="$(get -d "product_id=${product}" -d 'style=studio' "$(url "$route.generate")")"
printf '%s' "$reply" | grep -q 'Nothing has been sent'; check $? "generate without consent is refused: ${reply:0:160}"

reply="$(get "$(url "$route.models")")"
printf '%s' "$reply" | grep -q '"error"'; check $? "models without consent is refused: ${reply:0:160}"

reply="$(get -d "product_id=${product}" -d 'style=studio' "${base}admin/index.php?route=${route}.generate&user_token=wrongtoken")"
printf '%s' "$reply" | grep -q '"success"'; [ $? -ne 0 ]; check $? "generate with a wrong user token does not work"

reply="$(get "$(url "$route.generate")")"
printf '%s' "$reply" | grep -q '"success"'; [ $? -ne 0 ]; check $? "generate called with GET does not work"

reply="$(curl -sS -d "product_id=${product}" "${base}admin/index.php?route=${route}.generate")"
printf '%s' "$reply" | grep -q '"success"'; [ $? -ne 0 ]; check $? "generate without signing in does not work"

# ---- consent given: the panel turns ready
saved="$(get -d 'module_genmagic_photos_status=1' -d 'module_genmagic_photos_consent=1' -d 'module_genmagic_photos_api_key=' "$(url "$route.save")")"
printf '%s' "$saved" | grep -q '"success"'; check $? "consent saved, key kept: ${saved:0:160}"

form="$(get "$(url "catalog/product.form&product_id=${product}")")"
printf '%s' "$form" | grep -q 'data-ready="1"'; check $? "with consent and a key the panel is ready"
printf '%s' "$form" | grep -q 'id="genmagic-make"'; check $? "the Make photo button is there"
printf '%s' "$form" | grep -q 'data-generate-url="[^"]*user_token='"${user_token}"'"'; check $? "the panel routes carry the admin user token"

# ---- switched off: the panel is gone
get -d 'module_genmagic_photos_status=0' -d 'module_genmagic_photos_consent=1' -d 'module_genmagic_photos_api_key=' "$(url "$route.save")" > /dev/null
form="$(get "$(url "catalog/product.form&product_id=${product}")")"
printf '%s' "$form" | grep -q 'id="genmagic-photos"'; [ $? -ne 0 ]; check $? "switched off: the panel is gone"

if [ "$failed" -ne 0 ]; then
  echo "web check: FAILED"
  exit 1
fi
echo "web check: all passed"
