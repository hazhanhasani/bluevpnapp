# BlueVPN 4.10.0 — Closed-loop BlueAI

- Every automatic route decision now creates a bounded pending decision record.
- Actual verified success/failure resolves that decision and updates local calibration error.
- Confidence is calibrated per privacy-safe network fingerprint.
- Pending route identifiers are hashed and expire after 10 minutes.
- Successful backend connection outcomes automatically resolve matching route-degradation incidents.
- Existing deterministic VPN/payment/provider guards remain authoritative.
