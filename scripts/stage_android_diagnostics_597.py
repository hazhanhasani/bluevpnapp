from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace_once(path: Path, old: str, new: str) -> None:
    text = path.read_text(encoding="utf-8")
    if old not in text:
        raise SystemExit(f"marker not found in {path}: {old[:100]!r}")
    path.write_text(text.replace(old, new, 1), encoding="utf-8")


update = ROOT / "android-source/BlueVpnUpdateManager.kt"
replace_once(
    update,
    "object BlueVpnUpdateManager {\n",
    '''object BlueVpnUpdateManager {\n    data class UpdateStatus(\n        val installedVersion: String,\n        val installedCode: Int,\n        val releaseChannel: String,\n        val betaTester: Boolean,\n        val latestVersion: String,\n        val latestCode: Int,\n        val updateAvailable: Boolean,\n        val autoUpdate: Boolean,\n    )\n\n''',
)
replace_once(
    update,
    '    private const val KEY_UPDATE_VERSION = "remote_update_version"\n',
    '    private const val KEY_UPDATE_VERSION = "remote_update_version"\n    private const val KEY_UPDATE_CODE = "remote_update_code"\n',
)
replace_once(
    update,
    "    fun check(\n",
    '''    fun status(context: Context): UpdateStatus {\n        val preferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)\n        val latestVersion = preferences.getString(KEY_UPDATE_VERSION, "").orEmpty()\n        val latestCode = preferences.getInt(KEY_UPDATE_CODE, 0)\n        val channel = preferences.getString(KEY_RELEASE_CHANNEL, "stable")\n            .orEmpty().trim().lowercase().let { if (it == "beta") "beta" else "stable" }\n        return UpdateStatus(\n            installedVersion = BuildConfig.VERSION_NAME,\n            installedCode = BuildConfig.VERSION_CODE,\n            releaseChannel = channel,\n            betaTester = preferences.getBoolean(KEY_BETA_TESTER, false),\n            latestVersion = latestVersion,\n            latestCode = latestCode,\n            updateAvailable = latestVersion.isNotBlank() && remoteBuildIsNewer(latestVersion, latestCode),\n            autoUpdate = preferences.getBoolean(KEY_AUTO_UPDATE, true),\n        )\n    }\n\n    fun check(\n''',
)
replace_once(
    update,
    '            .putString(KEY_UPDATE_VERSION, latestVersion)\n',
    '            .putString(KEY_UPDATE_VERSION, latestVersion)\n            .putInt(KEY_UPDATE_CODE, latestCode)\n',
)

settings = ROOT / "android-source/BlueVpnSettingsActivity.kt"
replace_once(
    settings,
    "import android.content.Intent\n",
    "import android.content.ClipData\nimport android.content.ClipboardManager\nimport android.content.Context\nimport android.content.Intent\n",
)
replace_once(
    settings,
    "import android.net.Uri\n",
    "import android.net.ConnectivityManager\nimport android.net.NetworkCapabilities\nimport android.net.Uri\n",
)
replace_once(
    settings,
    "import com.v2ray.ang.bluevpn.BlueVpnPalette\n",
    "import com.v2ray.ang.bluevpn.BlueVpnPalette\nimport com.v2ray.ang.bluevpn.BlueVpnRuntimeGate\n",
)
old_update_row = '''        sectionLabel(content, "برنامه")\n        content.addView(\n            settingRow(\n                title = "بررسی بروزرسانی",\n                value = BuildConfig.VERSION_NAME,\n                description = "دریافت آخرین نسخه BlueVPN",\n            ) {\n                BlueVpnUpdateManager.check(this, force = true, showStatus = true)\n            },\n        )\n'''
new_update_row = '''        sectionLabel(content, "برنامه")\n        val updateStatus = BlueVpnUpdateManager.status(this)\n        val updateChannelTitle = if (updateStatus.releaseChannel == "beta") "Beta" else "Stable"\n        content.addView(\n            settingRow(\n                title = "بررسی بروزرسانی",\n                value = "${BuildConfig.VERSION_NAME} • $updateChannelTitle",\n                description = when {\n                    updateStatus.updateAvailable && updateStatus.latestVersion.isNotBlank() ->\n                        "نسخه ${updateStatus.latestVersion} آماده دریافت است"\n                    updateStatus.releaseChannel == "beta" && updateStatus.betaTester ->\n                        "نسخه نصب‌شده آخرین Beta ثبت‌شده است"\n                    else -> "نسخه نصب‌شده آخرین Stable ثبت‌شده است"\n                },\n            ) {\n                BlueVpnUpdateManager.check(this, force = true, showStatus = true)\n            },\n        )\n        content.addView(\n            settingRow(\n                title = "عیب‌یابی BlueVPN",\n                value = "اجرای تست",\n                description = "بررسی شبکه، هر دو API، وضعیت اتصال و کانال بروزرسانی",\n            ) { showDiagnostics() },\n        )\n'''
replace_once(settings, old_update_row, new_update_row)

marker = "    private fun showBackgroundReliability() {\n"
diagnostics = r'''    private fun showDiagnostics() {
        if (isFinishing || isDestroyed) return
        Toast.makeText(this, "در حال بررسی وضعیت BlueVPN…", Toast.LENGTH_SHORT).show()
        lifecycleScope.launch {
            val report = withContext(Dispatchers.IO) { buildDiagnosticReport() }
            if (isFinishing || isDestroyed) return@launch
            AlertDialog.Builder(this@BlueVpnSettingsActivity)
                .setTitle("گزارش عیب‌یابی BlueVPN")
                .setMessage(report)
                .setPositiveButton("کپی گزارش") { _, _ ->
                    val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
                    clipboard?.setPrimaryClip(ClipData.newPlainText("BlueVPN diagnostics", report))
                    Toast.makeText(this@BlueVpnSettingsActivity, "گزارش کپی شد", Toast.LENGTH_SHORT).show()
                }
                .setNegativeButton("بستن", null)
                .show()
        }
    }

    private fun buildDiagnosticReport(): String {
        val cm = getSystemService(ConnectivityManager::class.java)
        val network = cm?.activeNetwork
        val capabilities = network?.let { cm.getNetworkCapabilities(it) }
        val networkType = when {
            capabilities?.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) == true -> "Wi-Fi"
            capabilities?.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) == true -> "Mobile"
            capabilities?.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) == true -> "Ethernet"
            capabilities != null -> "Other"
            else -> "Offline"
        }
        val validated = capabilities?.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) == true
        val phase = BlueVpnRuntimeGate.connectionPhase(this).name
        val account = BlueVpnAccountManager.snapshot(this)
        val update = BlueVpnUpdateManager.status(this)
        val apiRows = BlueVpnAccountManager.apiBaseUrls().joinToString("\n") { base ->
            val result = runCatching {
                val connection = URL(base.trimEnd('/') + "/health").openConnection() as HttpURLConnection
                try {
                    connection.requestMethod = "GET"
                    connection.connectTimeout = 3_500
                    connection.readTimeout = 3_500
                    connection.instanceFollowRedirects = false
                    connection.setRequestProperty("Accept", "application/json")
                    val code = connection.responseCode
                    if (code in 200..399) "OK ($code)" else "HTTP $code"
                } finally {
                    connection.disconnect()
                }
            }.getOrElse { "FAILED (${it.javaClass.simpleName})" }
            "• $base: $result"
        }
        val channel = if (update.releaseChannel == "beta") "Beta" else "Stable"
        val latest = update.latestVersion.ifBlank { "نامشخص" }
        return buildString {
            appendLine("BlueVPN ${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})")
            appendLine("Channel: $channel${if (update.betaTester) " • tester" else ""}")
            appendLine("Latest known: $latest${if (update.updateAvailable) " • update available" else ""}")
            appendLine("Network: $networkType • validated=${if (validated) "yes" else "no"}")
            appendLine("Connection phase: $phase")
            appendLine("Account session: ${if (account.email.isNotBlank()) "signed-in" else "guest"}")
            appendLine("Entitlement: ${if (account.subscriptionActive) "premium" else "free"}")
            appendLine("Control plane:")
            append(apiRows)
            appendLine()
            append("No token, email, subscription URL or secret is included.")
        }
    }

'''
replace_once(settings, marker, diagnostics + marker)

test = ROOT / "tests/test_dual_control_plane_581.py"
insert_before = '    def test_health_monitor_probes_both_domains(self):\n'
new_tests = '''    def test_android_settings_exposes_privacy_safe_dual_domain_diagnostics(self):\n        settings = (ROOT / "android-source/BlueVpnSettingsActivity.kt").read_text(encoding="utf-8")\n        updater = (ROOT / "android-source/BlueVpnUpdateManager.kt").read_text(encoding="utf-8")\n        self.assertIn("عیب‌یابی BlueVPN", settings)\n        self.assertIn("BlueVpnAccountManager.apiBaseUrls()", settings)\n        self.assertIn('trimEnd(\'/\') + "/health"', settings)\n        self.assertIn("BlueVpnRuntimeGate.connectionPhase", settings)\n        self.assertIn("No token, email, subscription URL or secret is included.", settings)\n        self.assertIn("data class UpdateStatus", updater)\n        self.assertIn("fun status(context: Context): UpdateStatus", updater)\n        self.assertIn("KEY_UPDATE_CODE", updater)\n\n'''
replace_once(test, insert_before, new_tests + insert_before)

print("Android diagnostics/update-status patch applied")
