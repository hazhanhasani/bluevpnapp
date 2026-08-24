# BlueVPN 5.3.7 — Entitlement distribution integrity

- Windows consumes every ordered Free subscription published by mobile/config.
- Partial Free-source failure no longer discards healthy Windows sources.
- Paid snapshots retain last-known-good configs independently per provider/source.
- A newly-added healthy paid source is delivered even while another source is down.
- All paid `/sub/{token}` responses enforce status, canonical expiry and quota.
- Provider transport uncertainty remains a bounded fail-open only for a valid plan.
