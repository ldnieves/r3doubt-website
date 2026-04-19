# CLAUDE.md — R3DOUBT Security Group Website Redesign

## Project Context — R3DOUBT Security Group

**Live reference site:** <https://r3doubtsec.com/>

R3DOUBT Security Group is a cybersecurity solutions firm. The redesign must
preserve their brand identity and content while elevating the visual quality,
layout professionalism, and trust signals.

The design language must communicate:

- Technical authority
- Offensive and defensive security expertise
- No-nonsense operational credibility
- Enterprise and government-grade trust

Not "startup cyber."
Not neon hacker.
Not SaaS generic.

---

## Always Do First

- Invoke the `frontend-design` skill before writing any frontend code.
- Review `/brand_assets/` before designing — use any existing logo or palette.
- If a color palette is defined → use exact hex values. Never invent brand colors.
- Screenshot the live reference site at <https://r3doubtsec.com/> as the content source.

---

## Reference Site Workflow

Since <https://r3doubtsec.com/> is the redesign source:

1. Take a screenshot of the live site first: `node screenshot.mjs https://r3doubtsec.com/`
2. Extract all content (nav items, hero text, services, about, contact, footer).
3. Redesign using that content with the design system below.
4. Do NOT add sections not present on the live site.
5. Do NOT remove sections present on the live site.
6. Replace only visual design — layout structure should follow the original page flow.

---

## Local Server Rules

Always serve from localhost.

Start server: `node serve.mjs`

URL: <http://localhost:3000>

Never screenshot `file:///`.

If server is already running, do not start another instance.

---

## Screenshot Workflow (Mandatory)

Take screenshot: `node screenshot.mjs http://localhost:3000`

Saved automatically to: `/temporary screenshots/screenshot-N.png`

Optional labeled version: `node screenshot.mjs http://localhost:3000 hero-pass1`

After each screenshot:

- Read the PNG from `/temporary screenshots/`
- Compare visually against the live site reference
- Call out exact mismatches (spacing, color, font weight, layout)

Repeat until no visible differences remain. Minimum 2 comparison passes.

---

## Output Defaults

Unless told otherwise:

- Single `index.html`
- All styles inline
- Tailwind via CDN: `<script src="https://cdn.tailwindcss.com"></script>`
- Mobile-first responsive
- Placeholder images: <https://placehold.co/WIDTHxHEIGHT>

---

## R3DOUBT Design System

### Brand Personality

- Tactical precision
- Offensive security confidence
- Analyst-grade credibility
- Low-noise, high-signal communication
- Enterprise trust

### Primary Brand Direction

Derive exact values from `/brand_assets/` if available. If not, use:

Primary Base: `#090E18`
Secondary Base: `#0D1525`
Accent (Cyber Blue): `#1A5FD4`
Support Accent (Alert Red — urgency only): `#B71C1C`
Muted Text: `#8A9BB5`

Never use Tailwind default blue-600 or indigo-500.

---

### Typography Rules

Headings:

- Serif or Display style (e.g. Bebas Neue, Barlow Condensed, or similar military/technical display)
- Tight tracking: -0.03em to -0.05em
- Weight: 600–800
- All-caps for section labels

Body:

- Clean sans (Inter, IBM Plex Sans, Space Grotesk)
- Line-height: 1.7 minimum
- Never use same font for headings and body

---

### Shadows & Depth

Never use `shadow-md`.

Use layered tinted shadows:

- Blue-tinted, low opacity
- Soft blur (40px–60px)
- Subtle elevation differences

Surfaces: Base → Elevated → Floating

---

### Gradients

Layer multiple gradients. Optional grain overlay via SVG noise.

No flat backgrounds.

---

### Animations

Only animate:

- `transform`
- `opacity`

Never use `transition-all`.

---

### Interactive States (Mandatory)

Every clickable element must have:

- Hover state
- Focus-visible ring
- Active/press state

Buttons must:

- Slight lift on hover (`translateY(-2px)`)
- Slight press on active (`translateY(1px)`)
- Visible focus ring

---

### Images

All hero/section images must include:

- Gradient overlay (`bg-gradient-to-t from-black/60`)
- Optional color treatment with `mix-blend-multiply`

No flat raw images.

---

## Hard Rules

Do NOT:

- Add sections not on the live reference site
- Remove sections from the live reference site
- Stop after one screenshot pass
- Use default Tailwind blue or indigo
- Use `transition-all`
- Use flat `shadow-md`
- Use generic SaaS gradients
- Use neon or Matrix green aesthetic

---

## Visual Tone

Avoid:

- Matrix green / terminal hacker look
- Cyberpunk neon
- Startup SaaS vibe
- Stock photo overload

Aim for:

- Security operations center seriousness
- Government contractor quality
- Threat intelligence firm credibility
- Calm, confident, operational
