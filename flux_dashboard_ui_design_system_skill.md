---
name: flux-dashboard-ui-design-system
version: 1.0.0
type: ui-ux-skill
source_template: "Flux Dashboard — Next.js 16 Admin Template"
source_demo: "https://dashboardpack.com/live-demo-preview/?livedemo=391457"
source_app: "https://flux.dashboardpack.com/"
source_docs:
  - "https://flux.dashboardpack.com/docs"
  - "https://flux.dashboardpack.com/docs/theming"
  - "https://flux.dashboardpack.com/docs/components"
target_stack:
  - Next.js
  - React
  - TypeScript
  - Tailwind CSS v4
  - shadcn/ui
  - Radix UI
  - TanStack Table
  - Recharts
design_language:
  - gradient-forward
  - startup/SaaS
  - frosted-glass
  - colored-layered-shadows
  - dot-grid-backgrounds
  - high-contrast-dark-sidebar
  - rounded-modern-surfaces
---

# Flux Dashboard UI Design System & Component Guidelines

## 0. PURPOSE

Use this file as the visual reproduction contract for applications that should match the **Flux Dashboard** visual language.

The system is intended for:
- CRM / ERP applications
- SaaS dashboards
- lead-management systems
- billing and subscription portals
- internal admin panels
- project / deployment / operations dashboards

### Fidelity Rule

Use two token classes:

1. **VERIFIED** — values or behaviors explicitly exposed by the official Flux documentation or live application.
2. **REPRODUCTION** — implementation values selected to visually reproduce the live template where DashboardPack does not publicly expose the exact internal constant.

Never claim a `REPRODUCTION` token is a vendor-extracted source value.

---

# 1. DESIGN PRINCIPLES

The Flux visual system is defined by these characteristics:

- Clean, light main workspace with a darker navigation rail.
- Violet → electric-blue brand language.
- Strong use of semantic design tokens rather than hard-coded component colors.
- Smooth rounded surfaces; default signature radius is **0.875rem / 14px**.
- White cards against a very light cool-neutral page canvas.
- Frosted / translucent top-level navigation surfaces.
- Soft colored layered shadows rather than heavy gray drop shadows.
- High information density without looking cramped.
- Compact controls and badges.
- Gradient or glow treatment reserved for important CTA and branded moments.
- Inter for normal UI text.
- JetBrains Mono for technical identifiers, code, hashes, versions, branches, and system-like data.
- Responsive data tables become card-like/mobile layouts rather than forcing wide horizontal tables.
- Light, dark, and system themes.
- RTL-compatible logical spacing.
- Three density modes: Compact, Default, Comfortable.

---

# 2. BRAND & COLOR PALETTE

## 2.1 Verified Core Tokens — Light Theme

These values are exposed in the official Flux theming documentation.

| Token | OKLCh | Hex approximation | RGB | Usage |
|---|---:|---:|---:|---|
| `--primary` | `oklch(0.55 0.25 285)` | `#6D45F9` | `109, 69, 249` | Main CTA, active state, primary link, chart emphasis |
| `--accent` | `oklch(0.60 0.20 230)` | `#0090DF` | `0, 144, 223` | Electric-blue highlight, hover accent, gradient endpoint |
| `--primary-foreground` | `oklch(0.985 0.002 285)` | `#FAFAFB` | `250, 250, 251` | Text/icons on primary |
| `--background` | `oklch(0.985 0.002 285)` | `#FAFAFB` | `250, 250, 251` | App canvas |
| `--foreground` | `oklch(0.145 0.02 285)` | `#090912` | `9, 9, 18` | Primary text |
| `--card` | `oklch(1 0 0)` | `#FFFFFF` | `255, 255, 255` | Cards, dropdowns, forms |
| `--muted` | `oklch(0.96 0.005 285)` | `#F1F1F5` | `241, 241, 245` | Muted panels, neutral chips, skeletons |
| `--muted-foreground` | `oklch(0.556 0.015 285)` | `#72727C` | `114, 114, 124` | Secondary text |
| `--border` | `oklch(0.922 0.005 285)` | `#E5E5E8` | `229, 229, 232` | Borders, separators, table lines |

> Hex/RGB values are sRGB conversions of the documented OKLCh values and are intended as practical implementation equivalents.

---

## 2.2 Reproduction Semantic Tokens — Light Theme

Use these values when a Hex-based system is required.

```css
:root {
  --bg-page: #FAFAFB;
  --bg-surface: #FFFFFF;
  --bg-subtle: #F6F6F8;
  --bg-muted: #F1F1F5;
  --bg-elevated: rgba(255,255,255,0.88);

  --text-primary: #090912;
  --text-secondary: #4F4F5A;
  --text-muted: #72727C;
  --text-disabled: #A6A6AE;
  --text-inverse: #FAFAFB;

  --border-default: #E5E5E8;
  --border-subtle: #ECECF0;
  --border-strong: #D6D6DC;

  --brand-primary: #6D45F9;
  --brand-primary-hover: #5E37EC;
  --brand-primary-active: #512DD8;
  --brand-primary-soft: #F0ECFF;

  --brand-accent: #0090DF;
  --brand-accent-soft: #E8F7FF;

  --focus-ring: rgba(109, 69, 249, 0.30);

  --sidebar-bg: #11111A;
  --sidebar-bg-hover: #1A1A26;
  --sidebar-bg-active: rgba(109, 69, 249, 0.18);
  --sidebar-border: rgba(255,255,255,0.08);
  --sidebar-text: #D8D8E2;
  --sidebar-text-muted: #8B8B9A;
  --sidebar-text-active: #FFFFFF;
}
```

### Usage Rules

- Primary CTA: `--brand-primary`
- Primary hover: darken by ~6–9%
- Primary active: darken by ~12–16%
- Active nav: primary at 14–20% alpha
- Page background: never pure white; use `#FAFAFB`
- Main cards: pure white
- Nested/secondary cards: `#F6F6F8` or `#F1F1F5`
- Default text: `#090912`
- Avoid medium-gray headings; headings remain near-black
- Use violet and blue deliberately, not on every component

---

## 2.3 Status & Feedback Colors

These are **REPRODUCTION tokens** aligned to Flux's semantic component model.

### Success

| Role | Hex | RGB |
|---|---|---|
| Strong | `#16A66A` | `22, 166, 106` |
| Hover | `#12895A` | `18, 137, 90` |
| Chip BG | `#E9F8F1` | `233, 248, 241` |
| Chip Text | `#087A4B` | `8, 122, 75` |
| Border | `#BDEBD5` | `189, 235, 213` |

Use for:
- Active
- Paid
- Completed
- Healthy
- Production success
- Positive trends

### Warning

| Role | Hex | RGB |
|---|---|---|
| Strong | `#D89012` | `216, 144, 18` |
| Hover | `#B9780D` | `185, 120, 13` |
| Chip BG | `#FFF6DF` | `255, 246, 223` |
| Chip Text | `#956000` | `149, 96, 0` |
| Border | `#F7DB96` | `247, 219, 150` |

Use for:
- Pending
- Processing
- In progress
- Needs review
- Approaching deadline

### Danger

| Role | Hex | RGB |
|---|---|---|
| Strong | `#E5484D` | `229, 72, 77` |
| Hover | `#C83B40` | `200, 59, 64` |
| Chip BG | `#FFF0F0` | `255, 240, 240` |
| Chip Text | `#B4232A` | `180, 35, 42` |
| Border | `#F6C7CA` | `246, 199, 202` |

Use for:
- Failed
- Cancelled
- Suspended
- Overdue
- Delete/destructive

### Info / Neutral

| Role | Hex | RGB |
|---|---|---|
| Strong | `#4D72E8` | `77, 114, 232` |
| Chip BG | `#EEF3FF` | `238, 243, 255` |
| Chip Text | `#3456BA` | `52, 86, 186` |
| Border | `#CDD8FF` | `205, 216, 255` |

Use for:
- Draft
- Preview
- Neutral information
- New
- System notices

---

## 2.4 Gradient System

### Primary Brand Gradient

```css
background: linear-gradient(
  135deg,
  #6D45F9 0%,
  #7254F8 42%,
  #0090DF 100%
);
```

Use for:
- flagship CTA
- hero stat
- highlighted pricing plan
- premium / upgrade moment
- selected analytics treatment

Do **not** use gradient on every button.

### Gradient Text

```css
background: linear-gradient(90deg, #6D45F9 0%, #0090DF 100%);
-webkit-background-clip: text;
background-clip: text;
color: transparent;
```

### Soft Brand Wash

```css
background:
  radial-gradient(circle at 15% 15%, rgba(109,69,249,.12), transparent 32%),
  radial-gradient(circle at 85% 20%, rgba(0,144,223,.10), transparent 30%),
  #FAFAFB;
```

---

# 3. TYPOGRAPHY SYSTEM

## 3.1 Font Families

### Primary UI Font — VERIFIED

```css
font-family:
  Inter,
  ui-sans-serif,
  system-ui,
  -apple-system,
  BlinkMacSystemFont,
  "Segoe UI",
  sans-serif;
```

### Technical / Mono Font — VERIFIED

```css
font-family:
  "JetBrains Mono",
  ui-monospace,
  SFMono-Regular,
  Menlo,
  Monaco,
  Consolas,
  monospace;
```

Use mono for:
- deployment hashes
- branch names
- version numbers
- API keys
- environment IDs
- record IDs when the technical feel is desirable
- code snippets

Do not use monospace for ordinary customer names or long body copy.

---

## 3.2 Type Scale

The following scale is a **REPRODUCTION scale** designed to match the live Flux hierarchy.

| Style | Size | Weight | Line Height | Tracking |
|---|---:|---:|---:|---:|
| Display / dashboard greeting | 32px | 700 | 40px | -0.025em |
| H1 | 30px | 700 | 38px | -0.022em |
| H2 | 24px | 700 | 32px | -0.018em |
| H3 | 18px | 650 | 26px | -0.012em |
| H4 / Card title | 15px | 600 | 22px | -0.006em |
| Body large | 16px | 400 | 24px | 0 |
| Body default | 14px | 400 | 22px | 0 |
| Body compact | 13px | 400 | 20px | 0 |
| Table | 13px | 400–500 | 20px | 0 |
| Label | 12px | 500–600 | 16px | 0.01em |
| Badge | 11px | 600 | 16px | 0.015em |
| Micro / metadata | 11px | 500 | 16px | 0.01em |

### Metric Typography

Dashboard metric value:

```css
font-size: 28px;
line-height: 34px;
font-weight: 700;
letter-spacing: -0.025em;
font-variant-numeric: tabular-nums;
```

Large hero metric:

```css
font-size: 36px;
line-height: 42px;
font-weight: 750;
letter-spacing: -0.035em;
font-variant-numeric: tabular-nums;
```

### Uppercase Rules

Use uppercase sparingly.

Allowed:
- tiny overline labels
- environment labels
- short category tags
- system metadata

```css
font-size: 10px;
line-height: 14px;
font-weight: 700;
letter-spacing: 0.08em;
text-transform: uppercase;
```

Avoid uppercase table headings unless the page is intentionally technical.

---

# 4. SHADOWS, RADII & ELEVATION

## 4.1 Radius System

Flux documentation identifies **0.875rem / 14px** as the signature radius.

Use the following system:

```css
--radius-xs: 6px;
--radius-sm: 8px;
--radius-md: 10px;
--radius-lg: 14px;   /* Flux signature */
--radius-xl: 18px;
--radius-2xl: 22px;
--radius-pill: 9999px;
```

### Component Mapping

| Component | Radius |
|---|---:|
| Badge | 9999px or 8px |
| Checkbox | 5–6px |
| Input | 10px |
| Select | 10px |
| Standard Button | 10px |
| Main CTA | 10–12px |
| Card | 14px |
| Dashboard stat card | 14px |
| Dropdown | 12px |
| Popover | 14px |
| Sheet | 16–18px exposed edge |
| Modal | 16px |
| Large marketing panel | 18–22px |

---

## 4.2 Elevation Tokens

DashboardPack documents colored layered shadows as part of the Flux signature. Exact shadow constants are not public; use this reproduction scale.

```css
--shadow-xs:
  0 1px 2px rgba(9, 9, 18, 0.04);

--shadow-card:
  0 1px 2px rgba(9, 9, 18, 0.04),
  0 4px 12px rgba(9, 9, 18, 0.035);

--shadow-card-hover:
  0 2px 5px rgba(9, 9, 18, 0.05),
  0 12px 26px rgba(109, 69, 249, 0.07);

--shadow-dropdown:
  0 12px 30px rgba(9, 9, 18, 0.12),
  0 3px 8px rgba(9, 9, 18, 0.06);

--shadow-modal:
  0 26px 70px rgba(9, 9, 18, 0.20),
  0 8px 20px rgba(109, 69, 249, 0.08);

--shadow-flux:
  0 16px 40px rgba(109, 69, 249, 0.12),
  0 6px 18px rgba(0, 144, 223, 0.08);
```

### Elevation Rules

- Default cards remain very subtle.
- Dropdown/popover can be visibly elevated.
- Modal is the strongest neutral elevation.
- Branded / promoted cards may use `--shadow-flux`.
- Avoid black shadows over 25% opacity.

---

# 5. GLOBAL LAYOUT SYSTEM

## 5.1 Primary Desktop Shell

### Sidebar

```text
Expanded width:   256–272px
Collapsed width:  68–76px
Recommended:      264px / 72px
Position:         fixed / sticky left
Height:           100dvh
```

### Header

```text
Desktop height: 64px
Mobile height:  56px
Position: sticky top
Layer: above page content, below dialogs
```

### Main Content

```text
Desktop padding: 24px
Large desktop:   28–32px
Tablet:          20px
Mobile:          16px
```

### Container Modes — VERIFIED behavior

Flux supports:
- `fluid`: full available width
- `boxed`: centered max-width

Recommended boxed max width:

```css
max-width: 1440px;
margin-inline: auto;
```

For dense CRM tables, use fluid.

---

## 5.2 Grid Rules

Use 12-column desktop logic.

```css
--grid-gap-sm: 12px;
--grid-gap-md: 16px;
--grid-gap-lg: 24px;
--grid-gap-xl: 32px;
```

Dashboard:
- KPI cards: 4 columns at ≥1280px
- 2 columns at 768–1279px
- 1 column under 768px

Two-panel dashboard:
- primary chart: 7–8 columns
- secondary widget: 4–5 columns

Three-panel:
- 6 / 3 / 3
- 5 / 4 / 3
- 8 / 4

Avoid arbitrary uneven gaps.

---

# 6. SPACING SCALE

Use a 4px baseline.

```text
0    = 0
1    = 4px
1.5  = 6px
2    = 8px
2.5  = 10px
3    = 12px
4    = 16px
5    = 20px
6    = 24px
8    = 32px
10   = 40px
12   = 48px
16   = 64px
```

### Common Rules

- Icon ↔ text: 8px
- Form label ↔ control: 6–8px
- Inline buttons: 8px
- Card internal sections: 16–20px
- Card padding: 20–24px
- Page section gap: 24–32px
- Page title ↔ description: 4–6px
- Filters ↔ data table: 16px
- Table toolbar row: 12–16px

---

# 7. HEADER & TOP NAVIGATION

## 7.1 Header Surface

Flux documentation explicitly describes a **frosted glass header**.

Recommended implementation:

```css
.app-header {
  height: 64px;
  background: rgba(255, 255, 255, 0.80);
  border-bottom: 1px solid rgba(229, 229, 232, 0.86);
  backdrop-filter: blur(14px) saturate(150%);
  -webkit-backdrop-filter: blur(14px) saturate(150%);
}
```

If sidebar is dark, header may begin after sidebar or span full viewport depending on chosen shell.

---

## 7.2 Header Content Placement

Preferred order:

```text
LEFT:
- sidebar collapse trigger
- optional breadcrumb
- optional page context

CENTER / FLEX:
- command/search launcher

RIGHT:
- quick-create / action
- theme/customizer
- notification
- help
- avatar + profile menu
```

### Command Search

Desktop:
- width: 260–340px
- height: 36–40px
- icon: 16px
- shortcut hint on right: `⌘K` / `Ctrl K`

```css
background: #F6F6F8;
border: 1px solid #E5E5E8;
border-radius: 10px;
```

### Header Icon Button

```text
size: 36×36px
icon: 17–18px
radius: 9–10px
```

Hover:

```css
background: #F1F1F5;
```

---

# 8. SIDEBAR / NAVIGATION MENU

The official theming documentation exposes dedicated `--sidebar-*` tokens and describes the sidebar as separately themed.

## 8.1 Sidebar Base

```css
.sidebar {
  width: 264px;
  background: #11111A;
  color: #D8D8E2;
  border-inline-end: 1px solid rgba(255,255,255,.08);
}
```

### Brand Row

```text
height: 64px
padding-inline: 18px
logo icon: 28–32px
brand text: 14–16px, 650
```

---

## 8.2 Nav Item

Default:

```text
height: 38–42px
padding-inline: 12px
outer margin-inline: 8px
icon: 17–19px
icon-to-label: 10px
radius: 9–10px
font-size: 13px
font-weight: 500
```

### Default State

```css
color: #B8B8C5;
background: transparent;
```

### Hover State

```css
color: #FFFFFF;
background: #1A1A26;
```

### Active State

Preferred Flux-style treatment:

```css
color: #FFFFFF;
background:
  linear-gradient(
    90deg,
    rgba(109,69,249,.24),
    rgba(0,144,223,.10)
  );
box-shadow:
  inset 0 0 0 1px rgba(109,69,249,.18);
```

Optional active marker:

```css
width: 3px;
height: 18px;
border-radius: 999px;
background: #8C72FF;
```

Do not combine an oversized left bar **and** an aggressive full pill. Keep the state refined.

---

## 8.3 Section Labels

```css
font-size: 10px;
font-weight: 700;
letter-spacing: .08em;
text-transform: uppercase;
color: #777789;
padding: 16px 20px 7px;
```

---

## 8.4 Nested Submenu

```text
indent: 30–36px
item height: 34–38px
font size: 12.5–13px
```

Use a subtle connector or vertical guide only when nesting exceeds one level.

Do not exceed 3 visual hierarchy levels.

---

# 9. PAGE HEADER

Use this structure:

```text
Breadcrumb (optional)
H1
Supporting description
Right-aligned page actions
```

Desktop:

```css
display: flex;
align-items: flex-start;
justify-content: space-between;
gap: 20px;
margin-bottom: 24px;
```

H1:
- 28–30px / 700
- `#090912`

Description:
- 14px
- `#72727C`
- max width around 680px

Primary action:
- 36–40px height

---

# 10. CARDS, WIDGETS & DASHBOARDS

## 10.1 Standard Card

```css
.card {
  background: #FFFFFF;
  border: 1px solid #E5E5E8;
  border-radius: 14px;
  box-shadow:
    0 1px 2px rgba(9,9,18,.04),
    0 4px 12px rgba(9,9,18,.03);
}
```

Padding:
- compact: 16px
- default: 20px
- spacious: 24px

---

## 10.2 Card Header

```text
min-height: 52–60px
padding: 18–20px 20px
display: flex
align-items: center
justify-content: space-between
```

Title:
- 14–15px
- 600

Description:
- 12–13px
- muted

Action:
- ghost or outline
- small size
- never stronger than the card's primary information

---

## 10.3 Stat / KPI Card

Recommended anatomy:

```text
[label]                         [icon/menu]
[value]
[trend chip/text] [comparison text]
[optional sparkline]
```

KPI label:
- 12–13px / 500
- muted

Value:
- 28–32px / 700
- tabular nums

Trend:
- 11–12px / 600
- semantic color

Icon container:
- 34–38px
- muted or brand-soft background
- 9–10px radius

---

## 10.4 Highlighted / Brand Card

Use sparingly.

```css
background:
  linear-gradient(135deg, rgba(109,69,249,.98), rgba(0,144,223,.94));
color: white;
border: 1px solid rgba(255,255,255,.15);
box-shadow: var(--shadow-flux);
```

Use for:
- upgrade
- revenue hero
- important release
- premium callout

---

# 11. DATA TABLES / GRID / LIST VIEWS

Flux uses TanStack Table and officially supports:
- sorting
- filtering
- pagination
- CSV export
- mobile-responsive card views

## 11.1 Table Container

```css
background: #FFFFFF;
border: 1px solid #E5E5E8;
border-radius: 14px;
overflow: hidden;
```

For very large enterprise tables, the table can live directly on the canvas with a bordered toolbar and sticky header.

---

## 11.2 Toolbar

Default:

```text
height: auto
min-height: 56px
padding: 12–16px
gap: 8–12px
```

Order:
- tabs / status filters
- result count
- spacer
- search
- advanced filter
- export
- primary create action (if appropriate)

Do not overload the toolbar with >6 visible controls.

---

## 11.3 Table Header Row

```css
height: 42px;
background: #F8F8FA;
border-bottom: 1px solid #E5E5E8;
```

TH text:

```css
font-size: 11px;
line-height: 16px;
font-weight: 600;
color: #666673;
letter-spacing: .01em;
```

Use uppercase only for technical/admin-heavy views.

---

## 11.4 Table Data Row

Standard:

```text
height: 52–58px
```

Dense:

```text
height: 44–48px
```

Row:

```css
background: #FFFFFF;
border-bottom: 1px solid #ECECF0;
transition: background-color 120ms ease;
```

Hover:

```css
background: #FAFAFC;
```

Selected:

```css
background: #F5F2FF;
```

Avoid zebra striping by default. Flux feels cleaner with white rows + hover/selected states.

---

## 11.5 Cell Padding

Default:

```css
padding: 12px 14px;
```

Compact:

```css
padding: 8px 12px;
```

Primary identity cell:
- avatar 30–34px
- name 13px / 600
- secondary email 11–12px muted

Amount:
- right aligned
- tabular nums
- 13px / 600

Action column:
- 36px icon button
- right aligned

---

## 11.6 Mobile Table Behavior

Do not force horizontal scrolling for normal CRUD data.

At mobile width:
- hide low-priority columns
- transform each row into a compact card
- keep title/name + status visible
- expose secondary values as label/value pairs
- move actions into overflow menu

---

# 12. BUTTON SYSTEM

Official components expose these variants:
- default
- destructive
- outline
- secondary
- ghost
- link

Use the following reproduction dimensions.

## 12.1 Sizes

| Size | Height | Horizontal Padding | Font |
|---|---:|---:|---:|
| xs | 28px | 9px | 11px |
| sm | 32px | 11px | 12px |
| md/default | 38px | 14px | 13px |
| lg | 44px | 18px | 14px |

Icon-only:
- 32 / 36 / 40px squares depending on context

---

## 12.2 Primary Button

```css
background: #6D45F9;
color: #FAFAFB;
border: 1px solid #6D45F9;
border-radius: 10px;
font-weight: 600;
```

Hover:

```css
background: #5E37EC;
box-shadow:
  0 8px 20px rgba(109,69,249,.22),
  0 2px 8px rgba(0,144,223,.08);
transform: translateY(-1px);
```

Active:

```css
background: #512DD8;
transform: translateY(0);
```

Disabled:

```css
opacity: .48;
box-shadow: none;
cursor: not-allowed;
```

---

## 12.3 Gradient CTA

Use `flux-glow` visual language only for high-value CTA.

```css
background: linear-gradient(135deg, #6D45F9, #0090DF);
color: white;
```

Hover can increase glow, not scale aggressively.

---

## 12.4 Secondary Button

```css
background: #F1F1F5;
color: #22222D;
border: 1px solid transparent;
```

Hover:

```css
background: #E9E9EE;
```

---

## 12.5 Outline Button

```css
background: #FFFFFF;
color: #22222D;
border: 1px solid #DCDCE2;
```

Hover:

```css
background: #F8F8FA;
border-color: #CDCDD5;
```

---

## 12.6 Ghost Button

```css
background: transparent;
color: #4F4F5A;
border: 1px solid transparent;
```

Hover:

```css
background: #F1F1F5;
color: #090912;
```

---

## 12.7 Danger Button

```css
background: #E5484D;
color: white;
```

Hover:
`#C83B40`

Destructive outline:

```css
background: #FFFFFF;
color: #B4232A;
border: 1px solid #F1B8BC;
```

---

# 13. INPUTS, SELECTS & FORM CONTROLS

## 13.1 Input

```css
height: 40px;
padding-inline: 12px;
background: #FFFFFF;
color: #090912;
border: 1px solid #DCDCE2;
border-radius: 10px;
font-size: 13px;
```

Placeholder:

```css
color: #9696A1;
```

Hover:

```css
border-color: #CBCBD3;
```

Focus:

```css
border-color: #6D45F9;
box-shadow: 0 0 0 3px rgba(109,69,249,.16);
outline: none;
```

Disabled:

```css
background: #F6F6F8;
color: #A6A6AE;
cursor: not-allowed;
```

---

## 13.2 Error State

```css
border-color: #E5484D;
box-shadow: 0 0 0 3px rgba(229,72,77,.12);
```

Error text:
- 11–12px
- `#B4232A`
- margin-top 5–6px

---

## 13.3 Label

```css
font-size: 12px;
line-height: 16px;
font-weight: 600;
color: #34343E;
```

Required marker:
- danger
- do not color whole label red

---

## 13.4 Select

Same geometry as input.

Chevron:
- 16px
- muted
- right inset: 10–12px

Dropdown:
- radius 12px
- shadow-dropdown
- max-height 280–340px
- item height 34–38px

---

## 13.5 Textarea

```text
min-height: 96px
padding: 10–12px
resize: vertical
```

---

## 13.6 Checkbox

```text
size: 16×16px
radius: 5px
border: #CFCFD7
checked BG: #6D45F9
check: white
```

Focus ring:
`rgba(109,69,249,.22)`

---

## 13.7 Switch

```text
track: 34×20px
thumb: 16px
off BG: #D7D7DE
on BG: #6D45F9
```

Use 120–160ms motion.

---

# 14. BADGES / STATUS CHIPS

Official Flux components expose:
- default
- secondary
- destructive
- success
- warning
- outline

## 14.1 Geometry

Default:

```text
height: 22px
padding-inline: 8px
font-size: 11px
font-weight: 600
radius: 9999px
```

Compact:

```text
height: 20px
padding-inline: 7px
```

### Success

```css
background: #E9F8F1;
color: #087A4B;
border: 1px solid #BDEBD5;
```

### Warning

```css
background: #FFF6DF;
color: #956000;
border: 1px solid #F7DB96;
```

### Danger

```css
background: #FFF0F0;
color: #B4232A;
border: 1px solid #F6C7CA;
```

### Info

```css
background: #EEF3FF;
color: #3456BA;
border: 1px solid #CDD8FF;
```

### Neutral

```css
background: #F1F1F5;
color: #5F5F6B;
border: 1px solid #E1E1E6;
```

### Brand

```css
background: #F0ECFF;
color: #5B37D6;
border: 1px solid #D9D0FF;
```

---

# 15. TABS & SEGMENTED CONTROLS

## Standard Tabs

```text
height: 36–40px
gap: 20–24px
```

Inactive:
- muted text

Active:
- foreground
- 600 weight
- 2px brand underline or subtle brand pill

For dashboard status filtering, prefer segmented pills.

## Segmented Control

```css
display: inline-flex;
padding: 3px;
background: #F1F1F5;
border-radius: 10px;
```

Item:
- 30–34px height
- 10–12px horizontal padding

Selected:

```css
background: #FFFFFF;
color: #090912;
box-shadow: 0 1px 3px rgba(9,9,18,.07);
```

---

# 16. DROPDOWNS, POPOVERS & COMMAND PALETTE

## 16.1 Dropdown

```css
background: rgba(255,255,255,.98);
border: 1px solid #E5E5E8;
border-radius: 12px;
box-shadow: var(--shadow-dropdown);
padding: 6px;
```

Item:
- min-height 34px
- 12px horizontal padding
- 12–13px font
- radius 8px

Hover:
`#F3F3F6`

Danger item:
- red text
- pale red hover

---

## 16.2 Command Palette

Flux officially includes `Cmd+K`.

Recommended:
- width: 560–640px
- max-height: 70vh
- radius: 16px
- large top search row: 48–52px
- section labels: 10–11px uppercase
- result row: 40–44px
- icon: 17px
- shortcut key pill: neutral

Use stronger modal shadow but lighter backdrop than destructive dialogs.

---

# 17. MODALS, DIALOGS & SLIDE-OVERS

Official Flux components include Dialog and Sheet.

## 17.1 Backdrop

```css
background: rgba(9, 9, 18, 0.46);
backdrop-filter: blur(2px);
```

For command palette:
`rgba(9,9,18,.32)`

---

## 17.2 Modal

Default:

```text
width: 480–560px
max-width: calc(100vw - 32px)
radius: 16px
```

Large:
- 720–860px

Structure:

```text
Header: 20–24px padding
Body:   20–24px
Footer: 16–20px
```

Footer:
- right-aligned on desktop
- full-width stacked actions on narrow mobile when necessary

Shadow:
`--shadow-modal`

---

## 17.3 Slide-over / Sheet

Desktop width:
- normal: 420px
- detail: 520px
- large: 640px

Mobile:
- 100% minus safe margin or full viewport

Use:
- fixed right edge
- border-left
- 16–18px radius on exposed left corners when not full-height flush

---

# 18. ALERTS & FEEDBACK

Alert anatomy:

```text
[icon] [title]
       [description]
       [optional action]
```

Padding: 12–16px  
Radius: 10–12px  
Border: 1px solid semantic border

Keep alert backgrounds very light.

Success:
- BG `#F1FBF6`
- text `#087A4B`

Warning:
- BG `#FFF9E9`
- text `#956000`

Danger:
- BG `#FFF2F2`
- text `#B4232A`

Info:
- BG `#F1F5FF`
- text `#3456BA`

---

# 19. TOASTS

Use top-right desktop, bottom-safe mobile.

```text
width: 340–380px
radius: 12px
padding: 12–14px
```

Surface:
- white
- 1px border
- dropdown shadow

Duration:
- success/info: 3–4 sec
- warning: 5 sec
- destructive: persistent or 6–8 sec depending on severity

Do not stack more than 4 visible toasts.

---

# 20. AVATARS

Sizes:

```text
xs: 24px
sm: 30px
md: 36px
lg: 44px
xl: 56px
```

Shape:
- circle

Fallback:
- brand-soft / neutral background
- initials 10–13px / 600

Avatar groups:
- overlap 6–8px
- 2px white ring

---

# 21. ICONOGRAPHY

Flux uses **Lucide Icons**.

Default icon sizing:
- inline: 16px
- nav: 18px
- button: 16px
- KPI: 18–20px
- empty-state: 28–36px

Stroke width:
- 1.75–2px

Avoid mixing filled icon libraries with Lucide outline icons.

---

# 22. CHARTS & DATA VISUALIZATION

Flux uses Recharts and has chart semantic tokens (`--chart-1` … `--chart-5`).

Recommended palette:

```css
--chart-1: #6D45F9;
--chart-2: #0090DF;
--chart-3: #16A66A;
--chart-4: #F0A332;
--chart-5: #E15C98;
```

### Chart Grid

```css
stroke: #ECECF0;
stroke-dasharray: 3 3;
```

### Axis Labels

- 11px
- `#85858F`

### Tooltip

```css
background: rgba(17,17,26,.96);
color: white;
border-radius: 10px;
padding: 9px 11px;
box-shadow: 0 10px 28px rgba(9,9,18,.20);
```

### Area Chart Fill

Primary:

```css
linear-gradient(
  180deg,
  rgba(109,69,249,.28) 0%,
  rgba(109,69,249,.03) 100%
)
```

Avoid more than 5 simultaneous chart colors.

---

# 23. FROSTED GLASS / FLUX SIGNATURE EFFECTS

Officially documented signature classes:
- `flux-shadow`
- `flux-glow`
- `flux-frosted`
- `flux-gradient-text`
- `flux-dot-grid`

## Frosted

```css
background: rgba(255,255,255,.74);
border: 1px solid rgba(255,255,255,.58);
backdrop-filter: blur(16px) saturate(145%);
-webkit-backdrop-filter: blur(16px) saturate(145%);
```

Use for:
- top header
- floating filter
- overlay stat block
- premium/marketing widget

Do not apply frosted glass behind dense tables.

---

## Glow

```css
box-shadow:
  0 10px 28px rgba(109,69,249,.20),
  0 4px 14px rgba(0,144,223,.11);
```

Hover:
- increase shadow alpha slightly
- translateY(-1px)
- 140–180ms

---

## Dot Grid

```css
background-image:
  radial-gradient(rgba(109,69,249,.16) 1px, transparent 1px);
background-size: 18px 18px;
```

Use decoratively in:
- empty states
- onboarding panels
- auth marketing pane
- premium card
- product/feature intro

---

# 24. DATA DENSITY MODES

Flux officially supports Compact, Default, Comfortable density.

Use CSS variables to implement globally.

```css
:root {
  --density-control-h: 38px;
  --density-row-h: 54px;
  --density-card-pad: 20px;
  --density-gap: 16px;
}

[data-density="compact"] {
  --density-control-h: 32px;
  --density-row-h: 44px;
  --density-card-pad: 16px;
  --density-gap: 12px;
}

[data-density="comfortable"] {
  --density-control-h: 44px;
  --density-row-h: 62px;
  --density-card-pad: 24px;
  --density-gap: 20px;
}
```

For CRM / ERP default to **Default**.
For very large lead tables allow user-controlled **Compact**.

---

# 25. DARK MODE

Flux officially supports light, dark, and system modes.

Recommended dark reproduction:

```css
.dark {
  --bg-page: #0B0B12;
  --bg-surface: #12121B;
  --bg-subtle: #171721;
  --bg-muted: #1C1C27;

  --text-primary: #F5F5F7;
  --text-secondary: #C5C5CE;
  --text-muted: #8E8E9C;
  --text-disabled: #626270;

  --border-default: #292934;
  --border-subtle: #23232D;
  --border-strong: #353542;

  --brand-primary: #8D73FF;
  --brand-accent: #39B7FF;

  --focus-ring: rgba(141,115,255,.34);
}
```

Dark mode rules:
- avoid pure black
- preserve border visibility
- reduce white opacity for secondary text
- keep primary accent slightly brighter than light mode
- gradients may use stronger saturation but lower overall luminance

---

# 26. RESPONSIVE BREAKPOINTS

Use Tailwind defaults unless product requirements demand otherwise:

```text
sm: 640px
md: 768px
lg: 1024px
xl: 1280px
2xl: 1536px
```

### Behavioral Rules

Under `lg`:
- collapse fixed sidebar
- use Sheet/mobile nav
- reduce page padding
- simplify header search into icon / command trigger

Under `md`:
- KPI 1–2 columns
- hide secondary table columns
- stack page-header actions
- modal actions can stretch full width

Under `sm`:
- page padding 16px
- cards 16px padding
- control min-height 40px for touch
- convert toolbars to wrapping layout
- reduce H1 to 24–26px

---

# 27. ACCESSIBILITY

Flux components are based on Radix/shadcn-style accessible primitives.

Mandatory:
- visible keyboard focus
- semantic HTML
- labels for form controls
- `aria-label` for icon-only actions
- minimum touch target 40×40px on mobile
- never encode status by color alone
- retain text labels for destructive actions
- tables require headers and proper scope
- modal focus trap
- Escape closes non-critical dialogs
- support reduced motion

Focus ring:

```css
outline: none;
box-shadow:
  0 0 0 2px #FFFFFF,
  0 0 0 5px rgba(109,69,249,.28);
```

---

# 28. MOTION

Flux uses Framer Motion for transitions.

### Recommended Timing

```text
micro hover:      120–160ms
button elevation: 140–180ms
dropdown:         140–180ms
popover:          160–200ms
sheet:            220–280ms
modal:            180–240ms
page transition:  220–320ms
```

### Easing

```css
cubic-bezier(0.22, 1, 0.36, 1)
```

Rules:
- animations should feel quick, not playful
- no large bounce
- cards can fade + translate 4–8px
- modal can fade + scale from 0.98 to 1
- respect `prefers-reduced-motion`

---

# 29. CRM-SPECIFIC PATTERNS

## 29.1 Lead List

Required columns:
- checkbox
- lead/person
- company
- source
- stage
- owner
- last activity
- next follow-up
- value
- actions

Recommended:
- person cell with avatar + phone/email
- stage badge
- overdue follow-up uses danger
- hot lead uses compact brand or warning indicator
- amount right aligned

---

## 29.2 Lead Detail

Desktop:
```text
8-column main timeline/details
4-column sticky profile/action panel
```

Main:
- activity timeline
- notes
- messages
- tasks
- attachments
- appointments

Right:
- contact
- status/stage
- owner
- value
- tags
- next follow-up
- quick actions

---

## 29.3 Pipeline / Kanban

Column:
- width: 280–320px
- gap: 16px
- background: subtle
- radius: 14px

Lead card:
- white
- border
- 12px radius
- 12–14px padding
- value / next-action bottom row

Use violet/blue only as stage accents, not full-column fills.

---

# 30. ERP-SPECIFIC PATTERNS

## 30.1 Record Forms

Large ERP forms should use:
- 2-column layout desktop
- section cards
- sticky bottom action bar for long forms
- tabbed sections only when there are >3 meaningful categories

Do not create a single giant white card with 30 inputs.

---

## 30.2 Invoice / Quotation Tables

Use:
- mono or tabular values for quantities/prices
- right-align numeric data
- total section separated with stronger border
- grand total 18–22px / 700
- status badge near document number

---

# 31. EMPTY, LOADING & ERROR STATES

## Empty State

```text
icon / illustration
title
1–2 line explanation
primary action
optional secondary action
```

Width:
- 360–480px centered

Use dot-grid or soft brand wash behind icon, not full-screen illustration overload.

## Skeleton

Background:
- `#EFEFF3`
- animated shimmer/pulse at low contrast

Mirror the final layout to reduce shift.

---

# 32. CSS TOKEN STARTER

```css
:root {
  /* Core */
  --background: #FAFAFB;
  --foreground: #090912;
  --card: #FFFFFF;
  --muted: #F1F1F5;
  --muted-foreground: #72727C;
  --border: #E5E5E8;

  /* Brand */
  --primary: #6D45F9;
  --primary-hover: #5E37EC;
  --primary-active: #512DD8;
  --primary-foreground: #FAFAFB;
  --accent: #0090DF;

  /* Status */
  --success: #16A66A;
  --warning: #D89012;
  --destructive: #E5484D;
  --info: #4D72E8;

  /* Geometry */
  --radius-sm: 8px;
  --radius-md: 10px;
  --radius-lg: 14px;
  --radius-xl: 18px;

  /* Shadow */
  --shadow-card:
    0 1px 2px rgba(9,9,18,.04),
    0 4px 12px rgba(9,9,18,.035);

  --shadow-floating:
    0 12px 30px rgba(9,9,18,.12),
    0 3px 8px rgba(9,9,18,.06);

  --shadow-flux:
    0 16px 40px rgba(109,69,249,.12),
    0 6px 18px rgba(0,144,223,.08);
}
```

---

# 33. TAILWIND IMPLEMENTATION MAPPING

Recommended semantic classes:

```text
bg-background
text-foreground
bg-card
text-card-foreground
bg-muted
text-muted-foreground
border-border

bg-primary
text-primary-foreground
ring-primary

bg-destructive
text-destructive-foreground
```

Use:
- `rounded-[14px]` for signature cards if radius token is unavailable.
- `rounded-lg` / `rounded-xl` only after mapping Tailwind theme radius to this system.
- logical `ms-*`, `me-*`, `ps-*`, `pe-*` for RTL safety.

---

# 34. REACT COMPONENT CONTRACT

Every reusable component should support:

```ts
type CommonUIProps = {
  className?: string;
  disabled?: boolean;
  "aria-label"?: string;
};
```

Interactive components:
- keyboard accessible
- visible focus
- disabled state
- loading state where relevant
- destructive state when relevant

Buttons:
- icon-left
- icon-right
- icon-only
- loading spinner
- disabled

Inputs:
- label
- description
- prefix/suffix icon
- error
- disabled
- read-only

Cards:
- header
- title
- description
- action
- content
- footer

---

# 35. AI AGENT REPRODUCTION INSTRUCTIONS

When an AI coding agent receives this skill:

1. Preserve the Flux visual hierarchy.
2. Use semantic tokens; do not scatter hard-coded hex values across components.
3. Build light theme first, then dark.
4. Default card radius is 14px.
5. Use Inter for UI and JetBrains Mono for technical data.
6. Keep canvas `#FAFAFB`, cards `#FFFFFF`.
7. Use violet `#6D45F9` as the main primary.
8. Use electric blue `#0090DF` as secondary accent / gradient endpoint.
9. Keep borders subtle: `#E5E5E8`.
10. Prefer clean white rows instead of zebra tables.
11. Use badges for status; never large solid status blocks.
12. Use 4px spacing grid.
13. Default page spacing: 24px.
14. Default card padding: 20px.
15. Default input height: 40px.
16. Default button height: 38px.
17. Default data row: ~54px.
18. Use frosted header and colored glow only where appropriate.
19. Keep sidebar dark and active item violet-tinted.
20. Use Lucide icons consistently.
21. Build mobile table-card fallback.
22. Support Compact / Default / Comfortable density.
23. Support fluid and boxed content modes.
24. Support LTR and RTL using logical spacing.
25. Never overuse gradients.
26. Never use heavy Bootstrap-style borders or large gray shadows.
27. Avoid excessive 1px separators inside every card.
28. Keep dashboard data primary; decoration must remain secondary.
29. Use subtle micro-motion.
30. Ensure accessibility and keyboard navigation.

---

# 36. VISUAL QA CHECKLIST

Before considering a page Flux-compatible, verify:

- [ ] Main canvas is cool off-white, not pure white.
- [ ] Primary text is near-black with high contrast.
- [ ] Cards are white with subtle border and 14px radius.
- [ ] Page padding follows 16 / 20 / 24+ responsive rhythm.
- [ ] Header has a frosted/translucent feel where applicable.
- [ ] Sidebar is visually distinct and dark.
- [ ] Active nav uses violet emphasis.
- [ ] Primary CTA uses violet.
- [ ] Blue appears as accent/gradient, not as competing primary.
- [ ] Inputs use 10px radius and 40px default height.
- [ ] Button sizes are consistent.
- [ ] Table rows are around 52–58px default.
- [ ] Table header is subtle and compact.
- [ ] Statuses use chips/badges.
- [ ] Icons are Lucide-like outline icons.
- [ ] Technical strings use JetBrains Mono.
- [ ] Metrics use tabular numerals.
- [ ] Shadows are soft and slightly colored.
- [ ] Modals have 16px-ish corners.
- [ ] Dropdowns have stronger elevation than cards.
- [ ] Hover states are visible but restrained.
- [ ] Focus rings are visible.
- [ ] Mobile views reduce columns and preserve actions.
- [ ] No decorative effect harms readability.
- [ ] Dark mode retains semantic hierarchy.
- [ ] RTL does not break spacing/alignment.

---

# 37. DO / DON'T

## DO

- Use tokenized colors.
- Use violet/blue gradient for strategic brand moments.
- Keep large forms organized into sections.
- Use cards to group meaningful concepts.
- Use consistent 14px main-surface radius.
- Use soft borders.
- Use one main CTA per local context.
- Use compact but legible tables.
- Use skeletons during data loading.
- Use tabs and segmented controls for clear state switching.

## DON'T

- Do not use random colors for every module.
- Do not use saturated gradients as page backgrounds behind tables.
- Do not make every card glow.
- Do not put shadows on every nested element.
- Do not use >3 levels of nested navigation.
- Do not make all text 14px with no hierarchy.
- Do not use giant 48px+ dashboard headings.
- Do not force horizontal-scroll tables on mobile unless absolutely required.
- Do not use pure black `#000` as the standard dark surface.
- Do not use Bootstrap-like 4px radii.
- Do not combine heavy borders + heavy shadow + gradient on the same component.
- Do not rely on color alone for status.

---

# 38. SOURCE / PROVENANCE NOTES

Verified from the official Flux public materials:

- Flux is built with Next.js 16, React 19, TypeScript, Tailwind CSS v4, Radix/shadcn-style primitives, TanStack Table, Recharts, Lucide icons, and Framer Motion.
- Official design language includes vibrant gradients, frosted glass surfaces, colored layered shadows, dot-grid backgrounds, and a 0.875rem radius.
- Official typography uses Inter + JetBrains Mono.
- Official core light-theme tokens expose primary, accent, foreground, background, card, muted, muted-foreground, and border in OKLCh.
- Official component set includes Button, Badge, Card, Input, Select, Textarea, Checkbox, Switch, Dialog, Sheet, Table, DataTable, Tabs, Popover, Dropdown Menu, Alert, Command Palette, etc.
- Official DataTable behavior includes sorting, filtering, pagination, CSV export, and mobile-responsive card views.
- Official customization includes color presets, Compact/Default/Comfortable density, sidebar or horizontal navigation, fluid/boxed containers, LTR/RTL, and light/dark/system themes.

All token values labeled **REPRODUCTION** are implementation recommendations designed to reproduce the visual style when the exact vendor-internal constant is not publicly exposed.
