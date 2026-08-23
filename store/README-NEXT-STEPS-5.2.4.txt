BlueVPN 5.2.4 — Store publishing next steps

Android / Google Play
1. Deploy the full BlueVPN 5.2.4 project ZIP through the BlueVPN Deploy Bot.
2. Run the Android workflow with the existing Android signing secrets.
3. Expected Play artifact: BlueVPN-5.2.4-GooglePlay.aab.
4. The 5.2.4 hotfix uses DependencyHandler.add("fdroidImplementation", ...) for direct/F-Droid-only Tapsell dependencies, so AGP 9 can compile the Gradle Kotlin script while the Play flavor stays Tapsell-free.
5. Upload the signed AAB to an Internal testing track first and complete the VpnService/Data Safety declarations in this package.

Windows / Microsoft Store
1. Keep WINDOWS_SIGN_PFX_BASE64 and WINDOWS_SIGN_PFX_PASSWORD configured with a CA-trusted code-signing certificate.
2. Run Build BlueVPN Windows with store_release=true.
3. Expected artifacts are versioned Microsoft Store offline installers for x64 and arm64.
4. Submit the signed immutable HTTPS installer URL through Partner Center using the included checklist.

Telegram webhook
5.2.4 uses WordPress ?rest_route= routing for the Telegram webhook so hosts/CDNs that return 404 for /wp-json rewrite paths can still deliver webhook updates.
