#!/usr/bin/env bash
set -euo pipefail

chmod +x upstream/V2rayNG/gradlew

# android-emulator-runner executes each YAML script line independently with sh -c.
# Keep all stateful QA logic in this real Bash script so functions and variables
# persist for the complete emulator session.
QA_DEVICE_ROOT=""
for root in "/sdcard/Download" "/storage/emulated/0/Download" "/data/local/tmp"; do
  if adb shell "mkdir -p '$root' >/dev/null 2>&1 && test -d '$root' && test -w '$root'" >/dev/null 2>&1; then
    QA_DEVICE_ROOT="$root"
    break
  fi
done

if [[ -z "$QA_DEVICE_ROOT" ]]; then
  echo "::error::No writable Android QA screenshot directory is available."
  adb shell 'ls -ld /sdcard /storage /storage/emulated /storage/emulated/0 /data/local/tmp 2>/dev/null || true'
  exit 1
fi

QA_DEVICE_DIR="$QA_DEVICE_ROOT/bluevpn-qa"
echo "Resolved Android QA directory: $QA_DEVICE_DIR"
adb shell "rm -rf '$QA_DEVICE_DIR' && mkdir -p '$QA_DEVICE_DIR' && test -d '$QA_DEVICE_DIR' && test -w '$QA_DEVICE_DIR'"

# Capture light/dark screenshots before Macrobenchmark replaces the target app.
upstream/V2rayNG/gradlew -p upstream/V2rayNG \
  :app:connectedPlaystoreDebugAndroidTest \
  -PABI_FILTERS=x86_64 \
  "-Pandroid.testInstrumentationRunnerArguments.bluevpnQaDir=$QA_DEVICE_DIR" \
  --stacktrace --no-daemon --build-cache

mkdir -p reports/android-quality/qa
adb shell "test -s '$QA_DEVICE_DIR/locations-light-rtl.png'"
adb shell "test -s '$QA_DEVICE_DIR/locations-dark-rtl.png'"
adb pull "$QA_DEVICE_DIR/." reports/android-quality/qa/

# Macrobenchmark runs only after screenshots are safely copied off-device.
upstream/V2rayNG/gradlew -p upstream/V2rayNG \
  :benchmark:connectedBenchmarkAndroidTest \
  -PABI_FILTERS=x86_64 \
  --stacktrace --no-daemon --build-cache
