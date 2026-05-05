# Duolingo — Style Reference
> Playground Starter Kit

**Theme:** light

The design feels like an energetic, gamified classroom with a calm academic palette. Its core is built on a trinity of exuberant choices: the plump, ultra-rounded 'Feather' headline font, layered blue brand fields, and warm yellow action colors lifted from the reference palette. Soft cream replaces pure white as the default canvas, while the lighter blue creates friendly highlighted states. A signature detail is the 3D-style button, which uses a solid bottom shadow to feel tactile and pressable, a stark contrast to the otherwise flat UI. The entire experience is crafted to feel fun, friendly, and encouraging, turning language learning from a chore into a game.

## Tokens — Colors

| Name | Value | Token | Role |
|------|-------|-------|------|
| Academy Blue | `#487fb5` | `--color-academy-blue` | Brand blocks, illustration fields, friendly hover states, and calm section accents. |
| Focus Blue | `#436cac` | `--color-focus-blue` | Headlines, navigation, logo marks, and core UI text on light surfaces. |
| Sunbeam Yellow | `#ffe77f` | `--color-sunbeam-yellow` | Primary CTAs, active progress, success highlights, and warm illustration details. |
| Study Cream | `#ffedcb` | `--color-study-cream` | Main page background — soft, bright, and classroom-friendly. |
| Yellow Shadow | `#d7bd58` | `--color-yellow-shadow` | Solid bottom shadow for tactile yellow buttons. |
| Paper White | `#ffffff` | `--color-paper-white` | Card surfaces, input surfaces, button text when placed on blue. |
| Cloud Line | `#d9e4ee` | `--color-cloud-line` | Borders for secondary buttons, cards, tables, and dividers. |
| Muted Slate | `#64748b` | `--color-muted-slate` | Placeholder text, disabled states, secondary info text. |
| Ink Blue | `#263f78` | `--color-ink-blue` | Primary body and UI text when Focus Blue needs deeper contrast. |

## Tokens — Typography

### feather — Used exclusively for large, impactful headlines (H1, H2). Its extremely rounded, heavy, and slightly condensed character gives the brand its signature playful and confident voice. · `--font-feather`
- **Substitute:** Fredoka One, Baloo 2
- **Weights:** 700
- **Sizes:** 48px, 64px
- **Line height:** 1.20
- **Letter spacing:** -0.02em
- **Role:** Used exclusively for large, impactful headlines (H1, H2). Its extremely rounded, heavy, and slightly condensed character gives the brand its signature playful and confident voice.

### din-round — The workhorse font for all UI text, body copy, and buttons. The noticeably wide letter-spacing (`0.053em`) is a key trait, creating a very open and readable texture. Weight 700 is used for buttons and emphasized text. · `--font-din-round`
- **Substitute:** Nunito Sans, Varela Round
- **Weights:** 500, 700
- **Sizes:** 13px, 14px, 15px, 17px, 19px, 32px
- **Line height:** 1.15-1.47
- **Letter spacing:** 0.053em
- **Role:** The workhorse font for all UI text, body copy, and buttons. The noticeably wide letter-spacing (`0.053em`) is a key trait, creating a very open and readable texture. Weight 700 is used for buttons and emphasized text.

### Type Scale

| Role | Size | Line Height | Letter Spacing | Token |
|------|------|-------------|----------------|-------|
| caption | 13px | 1.4 | 0.69px | `--text-caption` |
| body | 15px | 1.4 | 0.8px | `--text-body` |
| heading-sm | 19px | 1.2 | 1.01px | `--text-heading-sm` |
| heading | 32px | 1.2 | 1.7px | `--text-heading` |
| heading-lg | 48px | 1.2 | -0.96px | `--text-heading-lg` |
| display | 64px | 1.2 | -1.28px | `--text-display` |

## Tokens — Spacing & Shapes

**Base unit:** 4px

**Density:** comfortable

### Spacing Scale

| Name | Value | Token |
|------|-------|-------|
| 8 | 8px | `--spacing-8` |
| 12 | 12px | `--spacing-12` |
| 16 | 16px | `--spacing-16` |
| 24 | 24px | `--spacing-24` |
| 32 | 32px | `--spacing-32` |
| 40 | 40px | `--spacing-40` |
| 48 | 48px | `--spacing-48` |
| 64 | 64px | `--spacing-64` |
| 80 | 80px | `--spacing-80` |
| 96 | 96px | `--spacing-96` |

### Border Radius

| Element | Value |
|---------|-------|
| cards | 12px |
| inputs | 12px |
| buttons | 12px |

### Layout

- **Page max-width:** 1140px
- **Section gap:** 80-120px
- **Card padding:** 24px
- **Element gap:** 16px

## Components

### Brand Headline
**Role:** Feature section titles like 'free. fun. effective.'

Uses the 'feather' font at 48px or 64px with '700' weight and tight letter-spacing (-0.02em). The color is 'Focus Blue' (`#436cac`) for trust, with 'Sunbeam Yellow' (`#ffe77f`) reserved for short accent words, icons, or progress marks.

### Character Illustration
**Role:** Visual anchors for every major page section.

Large, organic vector illustrations featuring diverse, playful characters. Built from 'Academy Blue', 'Focus Blue', 'Sunbeam Yellow', and 'Study Cream', with small Paper White highlights. They are flat but use simple layering and occasional gradients for depth.

### Inline Text Link
**Role:** Clickable text within a paragraph.

Text is colored 'Focus Blue' (`#436cac`) and often includes a subtle yellow underline on hover. It uses the standard 'din-round' body font.

### Language Flag Item
**Role:** Used in the language selector list.

A small rectangular flag icon followed by uppercase text (e.g., 'ENGLISH') in 'Graphite' ('#777777'). Uses the 'din-round' font. The whole item is a link.

## Do's and Don'ts

### Do
- Use 'Sunbeam Yellow' `#ffe77f` for primary CTAs, progress states, and active UI.
- Use 'Focus Blue' `#436cac` for brand-voice headlines, navigation, and important labels.
- Apply a 12px border-radius to all interactive UI components like buttons and inputs.
- Use the 'feather' font exclusively for large, impactful headlines (48px+).
- Create depth on primary buttons with a solid, darker yellow bottom 'shadow' (e.g., `box-shadow: 0 4px 0 #d7bd58`).
- Pair every major content section with a large, on-brand character illustration.
- Use 'Focus Blue' `#436cac` for secondary interactive elements like outline buttons and text links.
- Set body copy and UI text with 'din-round' and its distinctive `letter-spacing: 0.053em`.

### Don't
- Don't use sharp corners on any UI element.
- Don't introduce unrelated bright rainbow colors; keep the experience inside the blue, yellow, and cream palette.
- Don't use the 'feather' headline font for small text or body copy.
- Don't apply traditional `box-shadow` for elevation on panels or cards.
- Don't put low-contrast white text on Sunbeam Yellow; use Focus Blue or Ink Blue text for stronger contrast.
- Don't use system fonts; the custom 'feather' and 'din-round' styles are integral to the brand.
- Don't design a section without considering its accompanying illustration.

## Elevation

The system is intentionally flat, avoiding traditional shadows for elevation. Depth is created exclusively on primary buttons using a solid, darker-hue bottom border (emulated via `box-shadow`) that mimics a physical button pad. All other elements like cards and containers remain flat on the Study Cream page background.

## Imagery

The visual language is defined by a universe of custom vector illustrations. These are not decorative; they are central characters. The style is flat, friendly, and organic, featuring blobby shapes, simple features, and a diverse cast of people and mascots. The illustration palette extends the core colors with stacked fields of academy blue, focus blue, sunbeam yellow, and study cream, keeping the world playful without feeling noisy. These illustrations are large, often taking up half the screen width, and are always paired with a key message or feature, making the abstract concepts of learning feel tangible and fun.

## Layout

The site uses a centered, max-width layout (approx. 1140px) on an expansive Study Cream background. The hero section is asymmetric, with a large illustration on the left and a text block with CTAs on the right. Below the hero, the page flows in generous, vertically-spaced sections. Most sections are either single-column centered text blocks or two-column layouts that alternate between `illustration-left, text-right` and vice-versa. Colored blocks should be rare and deliberate: blue for major brand moments, yellow for action and progress, and cream for calm active states.

## Agent Prompt Guide

### Quick Color Reference
- **Page Background**: `#ffedcb` (Study Cream)
- **Primary Text**: `#263f78` (Ink Blue)
- **Brand / Headline**: `#436cac` (Focus Blue)
- **Primary CTA**: `#ffe77f` (Sunbeam Yellow)
- **Active Tint**: `#487fb5` (Academy Blue)
- **Borders**: `#d9e4ee` (Cloud Line)

### Example Component Prompts
1. **Primary Button**: "Create a button with 'GET STARTED' text. Background is '#ffe77f', text is '#263f78'. Use a 12px border-radius. Font is 'din-round' at 15px, weight 700. Padding is 16px 32px. Add a `box-shadow: 0 4px 0 #d7bd58`."
2. **Headline**: "Create a headline 'free. fun. effective.'. Font is 'feather' at 64px, weight 700. Color is '#436cac'. Set letter-spacing to -1.28px. Use '#ffe77f' as a short underline or highlight if needed."
3. **Outline Button**: "Create an outline button with 'I ALREADY HAVE AN ACCOUNT' text. Background is transparent. Text color is '#436cac'. Border is 2px solid '#d9e4ee'. Use a 12px border-radius. Font is 'din-round' at 15px, weight 700. Padding is 14px 24px."

## Similar Brands

- **Headspace** — Nearly identical philosophy of using friendly, rounded illustrations and a soft, approachable UI to demystify a complex topic.
- **Kahoot!** — Shares the gamified learning aesthetic, with bold primary colors, simple UI, and a focus on fun.
- **Mailchimp** — Pioneered the use of quirky, brand-defining illustrations and a single-color identity (yellow) in a similar way Duolingo uses green.
- **Discord** — Employs a custom super-rounded font ('gg sans') and mascot-driven illustrations to create a similarly playful and community-focused atmosphere.

## Quick Start

### CSS Custom Properties

```css
:root {
  /* Colors */
  --color-academy-blue: #487fb5;
  --color-focus-blue: #436cac;
  --color-sunbeam-yellow: #ffe77f;
  --color-study-cream: #ffedcb;
  --color-yellow-shadow: #d7bd58;
  --color-paper-white: #ffffff;
  --color-cloud-line: #d9e4ee;
  --color-muted-slate: #64748b;
  --color-ink-blue: #263f78;

  /* Typography — Font Families */
  --font-feather: 'feather', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  --font-din-round: 'din-round', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

  /* Typography — Scale */
  --text-caption: 13px;
  --leading-caption: 1.4;
  --tracking-caption: 0.69px;
  --text-body: 15px;
  --leading-body: 1.4;
  --tracking-body: 0.8px;
  --text-heading-sm: 19px;
  --leading-heading-sm: 1.2;
  --tracking-heading-sm: 1.01px;
  --text-heading: 32px;
  --leading-heading: 1.2;
  --tracking-heading: 1.7px;
  --text-heading-lg: 48px;
  --leading-heading-lg: 1.2;
  --tracking-heading-lg: -0.96px;
  --text-display: 64px;
  --leading-display: 1.2;
  --tracking-display: -1.28px;

  /* Typography — Weights */
  --font-weight-medium: 500;
  --font-weight-bold: 700;

  /* Spacing */
  --spacing-unit: 4px;
  --spacing-8: 8px;
  --spacing-12: 12px;
  --spacing-16: 16px;
  --spacing-24: 24px;
  --spacing-32: 32px;
  --spacing-40: 40px;
  --spacing-48: 48px;
  --spacing-64: 64px;
  --spacing-80: 80px;
  --spacing-96: 96px;

  /* Layout */
  --page-max-width: 1140px;
  --section-gap: 80-120px;
  --card-padding: 24px;
  --element-gap: 16px;

  /* Border Radius */
  --radius-xl: 12px;

  /* Named Radii */
  --radius-cards: 12px;
  --radius-inputs: 12px;
  --radius-buttons: 12px;
}
```

### Tailwind v4

```css
@theme {
  /* Colors */
  --color-academy-blue: #487fb5;
  --color-focus-blue: #436cac;
  --color-sunbeam-yellow: #ffe77f;
  --color-study-cream: #ffedcb;
  --color-yellow-shadow: #d7bd58;
  --color-paper-white: #ffffff;
  --color-cloud-line: #d9e4ee;
  --color-muted-slate: #64748b;
  --color-ink-blue: #263f78;

  /* Typography */
  --font-feather: 'feather', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  --font-din-round: 'din-round', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

  /* Typography — Scale */
  --text-caption: 13px;
  --leading-caption: 1.4;
  --tracking-caption: 0.69px;
  --text-body: 15px;
  --leading-body: 1.4;
  --tracking-body: 0.8px;
  --text-heading-sm: 19px;
  --leading-heading-sm: 1.2;
  --tracking-heading-sm: 1.01px;
  --text-heading: 32px;
  --leading-heading: 1.2;
  --tracking-heading: 1.7px;
  --text-heading-lg: 48px;
  --leading-heading-lg: 1.2;
  --tracking-heading-lg: -0.96px;
  --text-display: 64px;
  --leading-display: 1.2;
  --tracking-display: -1.28px;

  /* Spacing */
  --spacing-8: 8px;
  --spacing-12: 12px;
  --spacing-16: 16px;
  --spacing-24: 24px;
  --spacing-32: 32px;
  --spacing-40: 40px;
  --spacing-48: 48px;
  --spacing-64: 64px;
  --spacing-80: 80px;
  --spacing-96: 96px;

  /* Border Radius */
  --radius-xl: 12px;
}
```
