#!/bin/sh
# Rebuilds the Play Store icon and feature graphic in store/play from the app's own
# icon, logo and fonts. Needs macOS (sips) and Google Chrome. Run from mobile/.
set -e
cd "$(dirname "$0")/.."
sips -s format png -z 512 512 ios/Runner/Assets.xcassets/AppIcon.appiconset/Icon-App-1024x1024@1x.png --out store/play/icon-512.png >/dev/null
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --hide-scrollbars \
  --force-device-scale-factor=1 --window-size=1024,500 --allow-file-access-from-files \
  --screenshot="$PWD/store/play/feature-graphic.png" "file://$PWD/store/play/src/feature-graphic.html" 2>/dev/null
# Play wants the banner without transparency.
sips -s format jpeg -s formatOptions 92 store/play/feature-graphic.png --out store/play/feature-graphic.jpg >/dev/null
rm store/play/feature-graphic.png
sips -g pixelWidth -g pixelHeight store/play/icon-512.png store/play/feature-graphic.jpg
