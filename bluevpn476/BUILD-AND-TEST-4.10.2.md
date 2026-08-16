# BlueVPN 4.10.8 — Premium Verification Recovery

Fixes the Premium state-machine regression visible on Android:
- Xray RUNNING is not treated as CONNECTED without real data-plane proof.
- Existing recovered sessions can complete verification from stock v2rayNG real-ping.
- Existing-session verification has a terminal recovery path after bounded retries.
- Every failover candidate gets an absolute 28-second VERIFYING deadline.
- Deadline expiry quarantines only the failed candidate and advances to the next
  candidate in the same entitlement-isolated Premium queue.
- Unexpected-process recovery clears persisted CONNECTED state and stops the old
  Xray/VpnService before beginning the next explicit connection.
- Recovery never invokes WARP/Free preparation for an active Premium entitlement.

Validation executed locally:
- release cleanup: PASS
- release validator: PASS
- Python regression: PASS (30 modules)
- PHP release validation: PASS (23/23)
- workflow YAML: PASS
- test manifest: exact
- PHP manifest: exact
