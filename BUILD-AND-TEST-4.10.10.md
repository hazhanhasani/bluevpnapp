# BlueVPN 4.10.10 — Support EditText Kotlin Compile Fix

GitHub Android build failed in `BlueVpnSupportActivity.kt` because `messageInput`
is an Android `EditText`. Kotlin exposes `EditText.text` as `Editable`, so direct
assignments of a `String` do not compile.

Fixed the exact three failures reported by GitHub:
- line 689: `messageInput.text = ""` -> `messageInput.setText("")`
- line 1029: `messageInput.text = ""` -> `messageInput.setText("")`
- line 1046: `messageInput.text = value` -> `messageInput.setText(value)`

A regression test scans the complete support activity and rejects any future
`messageInput.text = ...` assignment.

Validation executed:
- Release validator: PASS
- Python regression suite: 338/338 PASS
- PHP release lint: 24/24 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP release manifest: exact
- Android Gradle compile/assemble: not re-run locally because pinned upstream v2rayNG is bootstrapped by GitHub CI.
