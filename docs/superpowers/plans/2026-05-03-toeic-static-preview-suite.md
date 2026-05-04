# TOEIC Static Preview Suite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build static HTML previews for every public and student TOEIC page requested by the user before any production frontend PHP/CSS changes.

**Architecture:** Keep all preview work isolated under `docs/previews/`. Add one shared preview CSS file and page-specific static HTML files that preserve the current TOEIC content, routes, states, and user flows. Existing homepage and dashboard previews remain the visual anchors and receive preview-only fixes for the review findings.

**Tech Stack:** Static HTML, shared CSS, existing local assets, Playwright screenshot verification.

---

## File Structure

- Create `docs/previews/toeic-preview-system.css` for the shared preview-only visual system.
- Create `docs/previews/preview-suite.html` as the review index for all static previews.
- Create static preview HTML files for auth, student, payment, test/proctoring, result/learning, and action-state pages.
- Modify `docs/previews/homepage-redesign-preview.html` to add reduced-motion support and correct language metadata.
- Modify `docs/previews/user-dashboard-redesign-preview.html` to use `mode=prep` for practice CTAs and remove the crushed negative heading tracking.

## Tasks

### Task 1: Shared Preview System

**Files:**
- Create: `docs/previews/toeic-preview-system.css`

- [ ] Add the complete preview-only CSS foundation: TOEIC color tokens, typography, navs, cards, forms, metrics, payment states, test workbench, proctor panels, result charts, and responsive rules.
- [ ] Include `@media (prefers-reduced-motion: reduce)` so preview motion respects reduced-motion settings.
- [ ] Keep all letter spacing at `0`.

### Task 2: Preview Index

**Files:**
- Create: `docs/previews/preview-suite.html`

- [ ] Add a static gallery linking to existing homepage/dashboard previews and every new page preview.
- [ ] Group links by Public/Auth, Student Core, Payment, Test/Proctoring, Result/Learning, and Action States.

### Task 3: Public/Auth Previews

**Files:**
- Create: `docs/previews/login-redesign-preview.html`
- Create: `docs/previews/register-redesign-preview.html`
- Create: `docs/previews/action-states-redesign-preview.html`

- [ ] Preserve login form fields, invalid-credentials state, registration fields, starter TOEIC credit message, pending checkout handoff, checkout redirect, and logout redirect.

### Task 4: Student Core Previews

**Files:**
- Create: `docs/previews/profile-redesign-preview.html`
- Create: `docs/previews/analytics-redesign-preview.html`
- Create: `docs/previews/buy-exam-redesign-preview.html`

- [ ] Preserve profile stats/history/forms, analytics score trend and part weakness map, package price `Rp 175.000`, TOEIC Listening & Reading package features, active package state, and voucher redemption.

### Task 5: Payment Previews

**Files:**
- Create: `docs/previews/payment-redesign-preview.html`
- Create: `docs/previews/payment-pending-redesign-preview.html`

- [ ] Preserve QRIS and VA payment methods, order summary, payment gateway readiness, pending/success/failed/timeout status states, Tripay link, and polling timeline.

### Task 6: Test and Proctoring Previews

**Files:**
- Create: `docs/previews/test-instructions-redesign-preview.html`
- Create: `docs/previews/camera-setup-redesign-preview.html`
- Create: `docs/previews/test-toeic-redesign-preview.html`
- Create: `docs/previews/disqualified-redesign-preview.html`

- [ ] Preserve full vs practice simulation context, `mode=prep` practice route, TOEIC Listening/Reading section durations, proctoring requirements, camera/mic/face setup, secure test workbench, and disqualification recovery state.

### Task 7: Result and Learning Previews

**Files:**
- Create: `docs/previews/result-toeic-redesign-preview.html`
- Create: `docs/previews/ai-analysis-redesign-preview.html`
- Create: `docs/previews/learning-pathway-redesign-preview.html`
- Create: `docs/previews/syllabus-redesign-preview.html`

- [ ] Preserve TOEIC score report, practice report, AI analysis states, personalized curriculum modules, exercise panel, 4-week study plan, performance analysis, and TOEIC practice CTA.

### Task 8: Existing Preview Fixes

**Files:**
- Modify: `docs/previews/homepage-redesign-preview.html`
- Modify: `docs/previews/user-dashboard-redesign-preview.html`

- [ ] Change homepage preview language metadata to English.
- [ ] Add reduced-motion override to homepage preview.
- [ ] Change dashboard practice CTAs from `mode=practice` to `mode=prep`.
- [ ] Remove negative tracking from the dashboard section heading rule that crushes words.

### Task 9: Verification

**Files:**
- Inspect: `docs/previews/*.html`
- Inspect: `docs/previews/toeic-preview-system.css`

- [ ] Run a static search for `mode=practice`, negative `letter-spacing`, missing reduced-motion CSS, and production PHP/CSS changes.
- [ ] Render representative desktop and mobile screenshots for the preview index, auth, payment, test runner, and learning/result pages.
- [ ] Report preview links, screenshots, verification commands, known risks, and confirm production PHP/CSS remains untouched.
