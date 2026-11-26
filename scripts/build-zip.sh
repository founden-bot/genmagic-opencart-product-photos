#!/bin/sh
# Builds the OpenCart 4 package: dist/genmagic.ocmod.zip
#
# The Extension Installer (Extensions > Installer) takes the extension folder name from the file name, so the file
# must be called genmagic.ocmod.zip. Its root holds install.json and the admin/ tree; the installer copies them into
# extension/genmagic/ of the shop.
set -e
cd "$(dirname "$0")/../extension/genmagic"
mkdir -p ../../dist
OUT="../../dist/genmagic.ocmod.zip"
rm -f "$OUT"
zip -r -X "$OUT" install.json admin -x '*.DS_Store'
unzip -l "$OUT"
