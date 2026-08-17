# BlueVPN Site 1.1.0 — Product-led Website Redesign

- `bluevpn-site/front-page.php` — delegates to the new shared Home v2 layout.
- `bluevpn-site/inc/home-v2.php` — new full product-led landing page using real app screenshot slots.
- `bluevpn-site/assets/css/site.css` — new responsive hero, app showcase, compatibility rail, bento benefits, network, account, steps, premium, FAQ and final CTA UI.
- `bluevpn-site/functions.php` — theme 1.1.0 + four WordPress media slots for real BlueVPN app screenshots.
- `bluevpn-site/inc/elementor/widgets.php` — new `bluevpn-home-v2` Elementor widget with real screenshot controls.
- `bluevpn-site/inc/class-bluevpn-elementor.php` — Seed v3 + one-time Home migration while preserving other Elementor pages.
- `bluevpn-site/style.css` — theme version 1.1.0.
- `bluevpn-site/ELEMENTOR-README.txt` — updated Elementor/media instructions.
- Tests updated for the new real-app-first and native-responsive website contract.

Validation: 444/444 Python tests PASS; release validator PASS; PHP lint PASS.
