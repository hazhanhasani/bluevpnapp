# BlueVPN 4.11.7 — Connection Stability + Exhaustive WARP Recovery

Fixes:
- Predictive failover no longer reacts to one noisy RTT/loss sample.
- A route must produce at least 3 consecutive bad health samples and the active
  session must be at least 25 seconds old before an automatic failover can run.
- Crash recovery no longer forces the app into the low-end/lightweight runtime
  profile; low-end mode is based only on actual device capability.
- Recovery marker duration reduced from 24 hours to 10 minutes.
- WARP no longer abandons a protocol after one bad native scan winner.
- Each enabled WARP strategy gets a bounded fresh scan and one alternate scan
  profile when the failure is endpoint/data-plane related.
- Cached-route failure -> fresh scan -> alternate scan -> next protocol.
- Global WARP deadline still bounds the entire attempt.
- SOCKS greeting, SOCKS CONNECT, tunneled HTTPS and exit validation remain
  mandatory before a WARP path is accepted.
