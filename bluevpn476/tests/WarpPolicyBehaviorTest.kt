import com.v2ray.ang.bluevpn.BlueVpnWarpPolicy

fun requireThat(value: Boolean, message: String) { if (!value) error(message) }

fun main() {
    val fastHealthy = BlueVpnWarpPolicy.candidateScore(18, 2, 0, 320, false)
    val slowFlaky = BlueVpnWarpPolicy.candidateScore(8, 12, 3, 1800, false)
    requireThat(fastHealthy > slowFlaky, "healthy low-latency candidate must rank first")

    val cached = BlueVpnWarpPolicy.candidateScore(4, 1, 0, 500, true)
    val uncached = BlueVpnWarpPolicy.candidateScore(4, 1, 0, 500, false)
    requireThat(cached > uncached, "fresh LKG must receive a bounded ranking bonus")

    requireThat(BlueVpnWarpPolicy.backoffMs("EXIT_IRAN", 1) >= 30 * 60_000L, "Iran exit requires long cooldown")
    requireThat(BlueVpnWarpPolicy.backoffMs("NETWORK_CHANGED", 4) <= 2_000L, "network change must not poison endpoint history")
    requireThat(BlueVpnWarpPolicy.backoffMs("PORT_IN_USE", 1) < BlueVpnWarpPolicy.backoffMs("DNS_FAILED", 2), "port collision should retry faster than DNS failure")

    requireThat(BlueVpnWarpPolicy.lkgFresh(10_000L, 9_000L, 2_000L), "fresh LKG rejected")
    requireThat(!BlueVpnWarpPolicy.lkgFresh(20_000L, 9_000L, 2_000L), "stale LKG accepted")
    requireThat(!BlueVpnWarpPolicy.lkgFresh(8_000L, 9_000L, 2_000L), "future timestamp accepted")

    requireThat(BlueVpnWarpPolicy.effectiveIpMode("auto", true, true) == "dual", "auto dual-stack behavior broken")
    requireThat(BlueVpnWarpPolicy.effectiveIpMode("auto", true, false) == "v4", "auto IPv4-only behavior broken")
    requireThat(BlueVpnWarpPolicy.effectiveIpMode("v4", true, true) == "v4", "explicit v4 ignored")
    println("WarpPolicyBehaviorTest PASS")
}
