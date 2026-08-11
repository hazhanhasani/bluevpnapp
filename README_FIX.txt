BlueVPN build fix for v4.0.23

Build error:
BlueVpnSubscriptionsActivity.kt:673:96 Argument type mismatch: actual type is Int, but Float was expected.

Fix:
setLineSpacing(0,1.18f)
->
setLineSpacing(0f,1.18f)

Replace:
android-source/BlueVpnSubscriptionsActivity.kt

The GitHub workflow copies this canonical file to:
upstream/V2rayNG/app/src/main/java/com/v2ray/ang/ui/BlueVpnSubscriptionsActivity.kt
before Gradle compilation.
