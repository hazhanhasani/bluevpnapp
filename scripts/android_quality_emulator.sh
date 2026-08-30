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
#
# Do not use connectedBenchmarkAndroidTest in CI here. AGP's UTP
# AndroidAdditionalTestOutputPlugin tries to copy multi-megabyte Perfetto traces
# back to the runner and can fail with java.io.EOFException even after the
# benchmark itself has run correctly. Install both benchmark APKs with Gradle,
# then invoke AndroidJUnitRunner directly over adb. This still executes the real
# Macrobenchmark tests but avoids the flaky UTP trace-copy plugin.
upstream/V2rayNG/gradlew -p upstream/V2rayNG \
  :app:installPlaystoreBenchmark \
  :benchmark:installBenchmark \
  -PABI_FILTERS=x86_64 \
  --stacktrace --no-daemon --build-cache

BENCH_COMPONENT="$(
  adb shell pm list instrumentation |
    tr -d '\r' |
    grep 'com.bluevpn.benchmark' |
    grep "(target=$BLUEVPN_QA_APPLICATION_ID)" |
    sed -n 's/^instrumentation:\([^ ]*\).*/\1/p' |
    head -n 1
)"
if [[ -z "$BENCH_COMPONENT" ]]; then
  echo "::error::BlueVPN Macrobenchmark instrumentation was not installed."
  adb shell pm list instrumentation || true
  exit 1
fi

mkdir -p reports/android-quality
BENCH_LOG="reports/android-quality/macrobenchmark-instrumentation.txt"
set +e
adb shell am instrument -w -r \
  -e class com.bluevpn.benchmark.BlueVpnLocationsMacrobenchmark \
  -e androidx.benchmark.suppressErrors EMULATOR \
  "$BENCH_COMPONENT" | tee "$BENCH_LOG"
BENCH_ADB_STATUS=${PIPESTATUS[0]}
set -e

if [[ $BENCH_ADB_STATUS -ne 0 ]]; then
  echo "::error::Macrobenchmark adb instrumentation exited with $BENCH_ADB_STATUS."
  exit "$BENCH_ADB_STATUS"
fi
if grep -Eq 'FAILURES!!!|INSTRUMENTATION_FAILED|Process crashed|shortMsg=Process' "$BENCH_LOG"; then
  echo "::error::Macrobenchmark instrumentation reported a test failure."
  exit 1
fi
if ! grep -Eq 'OK \([0-9]+ tests?\)|OK \([0-9]+ test\)' "$BENCH_LOG"; then
  echo "::error::Macrobenchmark instrumentation did not report JUnit success."
  exit 1
fi
