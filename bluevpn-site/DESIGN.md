# BlueVPN Design System

This file is the design contract for the WordPress theme in `bluevpn-site/`.

## Product direction
- Premium, calm VPN/SaaS interface.
- RTL-first and mobile-first.
- Keep the BlueVPN identity: deep navy, electric blue, clean white surfaces.
- Prefer clarity and product screenshots over decorative density.
- Motion should be subtle, transform/opacity based, and disabled for `prefers-reduced-motion`.

## Layout
- Content max width: 1180px.
- Desktop section spacing: 96–120px.
- Mobile section spacing: 56–72px.
- Mobile horizontal gutter: 18px.
- Text columns should stay readable; avoid very long Persian lines.
- Hero becomes a single-column composition below 760px.

## Core tokens
```css
--bv2-bg: #f6f9ff;
--bv2-surface: #ffffff;
--bv2-surface-soft: #eef5ff;
--bv2-ink: #071426;
--bv2-muted: #5f6f86;
--bv2-line: rgba(15, 42, 76, .10);
--bv2-brand: #2474ff;
--bv2-brand-2: #55a6ff;
--bv2-success: #17b978;
--bv2-radius-sm: 14px;
--bv2-radius-md: 20px;
--bv2-radius-lg: 28px;
--bv2-shadow-sm: 0 10px 30px rgba(21, 63, 125, .08);
--bv2-shadow-md: 0 22px 60px rgba(21, 63, 125, .13);
--bv2-ease: cubic-bezier(.2,.8,.2,1);
```

## Typography
- Use the existing Persian font stack provided by the theme.
- Body: 15–18px depending on viewport.
- H1: clamp(2.25rem, 5vw, 4.8rem).
- H2: clamp(1.8rem, 3.2vw, 3.2rem).
- Body line-height: 1.9 for Persian copy.
- Use font-weight contrast instead of excessive color contrast.

## Components
### Header
- Sticky, compact, glass-like surface after scroll.
- Mobile menu button must stay at least 44×44px.
- Primary account/download actions should remain visually distinct.

### Hero
- Product-first visual hierarchy.
- One primary CTA and one quiet secondary CTA.
- Proof points are compact chips, not a dense row.
- On mobile, copy comes first and product art follows without horizontal squeezing.

### Cards
- White/soft surfaces with a light border.
- Consistent radius and shadow.
- Hover effects only on pointer devices.
- Avoid giant empty areas on mobile; cards should shrink to content height.

### Interactive states
- Visible `:focus-visible` ring.
- Buttons: 44px minimum touch target.
- Hover translation <= 4px.
- Respect reduced motion.

## Responsive rules
- <= 760px: single-column hero, feature cards stack, mobile-readable type, full-width primary CTA.
- <= 480px: tighter gutters and 16px card padding, but do not shrink body text below 15px.
- Do not use desktop dimensions merely scaled down on mobile.

## Accessibility
- Maintain AA contrast for text.
- Decorative SVG/icon content uses `aria-hidden`.
- Interactive elements require visible keyboard focus.
- Do not communicate state with color alone.

## Performance
- Prefer bundled WebP assets already in the repository.
- No heavy animation libraries.
- Avoid layout-shifting image containers.
- New UI polish should normally live in `assets/css/design-system-v2.css` so legacy styles stay easy to compare and roll back.
