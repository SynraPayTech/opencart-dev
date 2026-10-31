#!/usr/bin/env sh
# Builds dist/synra_pay.ocmod.zip for OpenCart 4 (Extensions → Installer → Upload).
# Layout inside the zip: install.json + admin/ + catalog/ + system/ at the root,
# with forward-slash entries (PowerShell's Compress-Archive writes backslashes,
# which break the extension on Linux hosts).
set -e
cd "$(dirname "$0")"
mkdir -p dist
rm -rf dist/stage dist/synra_pay.ocmod.zip
mkdir -p dist/stage
cp -R synra_pay/. dist/stage/
cd dist/stage
if command -v zip >/dev/null 2>&1; then
  zip -qr ../synra_pay.ocmod.zip . -x '*.DS_Store'
else
  /c/Windows/System32/tar.exe -a -cf ../synra_pay.ocmod.zip install.json admin catalog system
fi
cd ..
rm -rf stage
ls -la synra_pay.ocmod.zip
