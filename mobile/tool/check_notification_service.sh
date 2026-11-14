#!/bin/sh
# Compiles the iOS notification service extension with a small harness for this Mac and runs it.
# No simulator, device or push needed. Run from mobile/.
set -e
out="$(mktemp -d)"
trap 'rm -rf "$out"' EXIT
xcrun swiftc -O -o "$out/check" ios/NotificationService/NotificationService.swift ios/NotificationServiceTests/main.swift -framework UserNotifications
"$out/check"
