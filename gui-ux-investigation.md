# GUI UX Investigation: Tabbed/Multi-Page Navigation

**Branch**: `eiou-docker-v0.1.10-alpha`
**Date**: 2026-04-04
**Status**: Investigation / Proposal (not committed)

---

## 1. Current State

The wallet GUI is a **single long page** (`wallet.html`, 55 lines) that vertically stacks 9 sections rendered from PHP sub-templates:

| # | Section | File | Lines | Purpose |
|---|---------|------|-------|---------|
| 1 | Banner | `banner.html` | small | Optional promo banners |
| 2 | Header | `header.html` | 12 | Logo, wallet name, logout |
| 3 | Notifications | `notifications.html` | dynamic | Toasts, alerts |
| 4 | Quick Actions | `quickActions.html` | 43 | 6 nav cards (horizontal scroll) |
| 5 | Wallet Info | `walletInformation.html` | 118 | Balances, earnings, credit, addresses, pubkey |
| 6 | Send eIOU | `eiouForm.html` | 135 | Transaction form |
| 7 | Add Contact | `contactForm.html` | 59 | Contact add form |
| 8 | Contacts | `contactSection.html` | 785 | Contact cards, search, modals |
| 9 | Transactions | `transactionHistory.html` | 443 | In-progress + history table |
| 10 | DLQ | `dlqSection.html` | 206 | Failed message queue |
| 11 | Settings | `settingsSection.html` | 716 | Default currency/fee/credit, 8 advanced categories |
| 12 | Debug | `settingsSection.html` | 339 | App/EIOU/PHP/nginx logs, system info, debug report |

**Total rendered HTML**: ~2,800+ lines of template content on a single page, plus 3,873 lines of CSS and 4,356 lines of JS.

### Navigation today

- **Quick Actions cards** link via `#anchor` to section IDs (e.g., `#send-form`, `#contacts`, `#settings`)
- **Back-to-top** floating button appears after 300px scroll
- **Manual refresh** floating button (Tor-friendly)
- No persistent navigation bar, no tab system at page level, no URL routing
- User must scroll or click a quick-action to reach any section

### Mobile experience today

- 7 media breakpoints (768px, 640px, 600px, 576px, 480px)
- Quick actions grid scrolls horizontally on small screens
- Tables convert to stacked cards on mobile (DLQ, transactions)
- `prefers-reduced-motion` respected for Tor Browser accessibility
- **Problem**: Even with responsive layouts, all content loads on one page. On mobile over Tor (slow connection), the user downloads everything at once and must scroll through ~2,800 lines of rendered HTML to find what they need.

### Tor Browser compatibility today

- Vanilla JS (no frameworks), no WebSocket, no WebRTC, no Service Workers
- `sessionStorage`/`localStorage` tested with try-catch fallback to URL params
- CSP nonce injection for inline scripts/styles
- `.onion` address detection built in
- `backdrop-filter: blur()` on loading overlay (degrades gracefully)
- Font Awesome loaded as a CSS file (no external CDN calls)
- All assets served from the same origin

---

## 2. The Problem

### 2.1 Mobile usability

On a phone (especially over Tor, where page loads take 5-15 seconds):
- **Everything loads at once** - all settings, debug logs, contacts, transactions - even if the user only wants to send a payment
- **No visual orientation** - once past the quick actions, there is no persistent indication of "where am I" on the page
- **Scrolling fatigue** - Settings alone is 1,055 lines of template; Debug has 5 tabbed sub-sections plus a report form. A user at the bottom of the page has no quick way back to Send eIOU except the back-to-top button
- **Quick Actions scroll off-screen** - the navigation cards disappear after a few hundred pixels of scrolling, leaving only the back-to-top and refresh floating buttons

### 2.2 Cognitive load

All functionality is visible simultaneously. A user who just wants to check their balance is confronted with DLQ retry buttons, rate limiting settings, and PHP error logs on the same page.

### 2.3 Performance on Tor

Full page render requires the server to query debug logs, system info, all transactions, all contacts, and all settings on every single page load. Over Tor, this compounds into long wait times even when the user only needs one section.

---

## 3. Recommendation: Client-Side Tab Navigation

### Why tabs over multi-page (server-side routing)

| Approach | Pros | Cons |
|----------|------|------|
| **Server-side pages** (separate PHP endpoints per section) | Lighter per-page payload, cleaner URLs | Requires refactoring PHP router, breaking the single `index.html` entry point, duplicating header/auth logic, each page navigation is a full round-trip over Tor (5-15s per click) |
| **Client-side tabs** (show/hide sections with JS + hash) | **No server changes**, instant switching after initial load, works offline after load, Tor-friendly (one load, then zero latency), builds on existing `#anchor` pattern | Full payload on first load (same as today), JS required (already required today) |
| **Hybrid** (lazy-load sections via AJAX) | Lighter first load, load-on-demand | Requires new AJAX endpoints per section, more complex JS, each AJAX call over Tor is slow, increased complexity |

**Verdict: Client-side tabs.** This is the best fit because:
1. **Tor penalty is on navigation, not initial load.** Today the page already loads everything; tabs make that one load useful instead of overwhelming. Server-side routing would add a 5-15s Tor round-trip on every tab switch.
2. **Zero PHP changes needed** for the core mechanism. The HTML sub-parts are already cleanly separated into files.
3. **Existing precedent** in the codebase: the Debug section already uses a tab pattern (`debug-tab` buttons switching `debug-content` panels). We generalize this to the page level.
4. **Hash-based navigation already exists** (`#send-form`, `#contacts`, `#settings`) and would continue working.

---

## 4. Proposed Tab Structure

### 4.1 Tab grouping

Group the 9+ sections into **5 logical tabs** based on usage frequency and cognitive grouping:

| Tab | Icon | Contains | Why |
|-----|------|----------|-----|
| **Dashboard** | `fa-home` | Banner + Wallet Info + Quick Actions | Landing page, at-a-glance overview. Quick Actions become in-tab navigation to other tabs. |
| **Send & Contacts** | `fa-paper-plane` | Send eIOU + Add Contact + Contacts list | Core workflow: send money, manage who you send to. These are tightly coupled (send form uses contact list). |
| **Activity** | `fa-history` | Transaction History + DLQ (Failed Messages) | All transaction-related monitoring in one place. DLQ is directly related to transaction delivery. |
| **Settings** | `fa-cog` | Wallet Settings (basic + advanced categories) | Configuration only. Already has its own internal category selector. |
| **Debug** | `fa-bug` | Debug Information (logs + system info + report) | Already has its own internal tab system. Separated from Settings so non-technical users never see it. |

### 4.2 Tab bar design

**Sticky bottom bar on mobile, sticky top bar on desktop.**

```
Desktop (>768px):
┌──────────────────────────────────────────────────────────┐
│ ₳ Wallet of Alice                              [Logout] │
├──────────────────────────────────────────────────────────┤
│ [Dashboard]  [Send & Contacts]  [Activity]  [Settings]  [Debug] │
├──────────────────────────────────────────────────────────┤
│                                                          │
│  (active tab content here)                               │
│                                                          │
└──────────────────────────────────────────────────────────┘

Mobile (<768px):
┌──────────────────────────┐
│ ₳ Wallet of Alice [Out]  │
│                          │
│  (active tab content)    │
│                          │
│                          │
├──────────────────────────┤
│ 🏠  ✈️  📊  ⚙️  🐛      │  ← sticky bottom bar (icons only)
└──────────────────────────┘
```

### 4.3 Navigation behavior

- **URL hash**: `#dashboard`, `#send`, `#activity`, `#settings`, `#debug`
- **Deep links**: `#send-form` maps to the Send & Contacts tab, `#transactions` maps to Activity, etc. (backwards compatible with existing quick-action links)
- **Default tab**: Dashboard (or last visited, stored in `sessionStorage` with Tor fallback)
- **Tab switch**: Pure CSS class toggle (`display: none` / `display: block`), no animation needed (Tor-friendly, `prefers-reduced-motion` compliant)
- **Quick Actions cards** on Dashboard change behavior: instead of scrolling to an anchor, they switch to the relevant tab

### 4.4 Tor Browser specifics

| Concern | Mitigation |
|---------|------------|
| `sessionStorage` may be blocked | Already handled: existing `safeStorageSet`/`safeStorageGet` with URL hash fallback |
| No CSS animations in strict mode | Tab switching uses `display` toggle, not transitions |
| No JavaScript? | Tor Browser has JS enabled by default (Standard security level). At Safer/Safest levels, JS is disabled on non-HTTPS. Since the GUI runs on the user's own node (localhost or `.onion`), JS is available. Fallback: without JS, the page degrades to today's scroll-based layout (all sections visible). |
| Large initial payload | Same as today (no regression). Future optimization: could defer Debug section content with lazy AJAX loading as a separate enhancement. |
| `backdrop-filter` | Not used in tab bar; already handled for loading overlay |
| CSP compliance | Tab JS uses the same CSP nonce pattern as existing code |

---

## 5. Implementation Plan

### 5.1 Files to modify

| File | Change |
|------|--------|
| `wallet.html` | Wrap sections in tab content divs, add tab bar HTML |
| `page.css` | Add tab bar styles (~100-150 lines), sticky positioning, mobile bottom bar, active tab states |
| `script.js` | Add `switchTab()` function (~50-80 lines), hash-based routing, integrate with existing `reloadWithHash` |
| `quickActions.html` | Change `href="#section"` to `data-action="switchTab" data-tab="tabname"` (or keep anchors and intercept) |
| `floatingButtons.html` | Hide back-to-top when tabs are active (sections are shorter, less scrolling) |
| `settingsSection.html` | Split the debug section out as a sibling div (currently nested after the settings `</form>`) |

### 5.2 Changes NOT needed

- **No PHP changes** - `index.html` router, controllers, `Functions.php` all remain as-is
- **No new endpoints** - all content still rendered server-side in one pass
- **No build tools** - vanilla CSS/JS, same as today
- **No new dependencies** - no libraries, no frameworks

### 5.3 Estimated scope

| Component | New lines | Modified lines |
|-----------|-----------|----------------|
| CSS (tab bar + responsive) | ~120 | ~20 (hide `back-to-top` conditionally) |
| JS (tab logic + hash routing) | ~80 | ~30 (integrate with existing `reloadWithHash`, quick actions) |
| HTML (tab wrapper + bar) | ~40 | ~20 (section wrapper classes) |
| **Total** | **~240** | **~70** |

This is well under the 500-line PR limit.

### 5.4 Step-by-step

1. **Split Debug from Settings**: Move the `<div id="debug-section">` block out of `settingsSection.html` into its own `debugSection.html` file. Update `wallet.html` to `require_once` it separately.

2. **Add tab bar HTML** in `wallet.html`:
   - After header+notifications, before content sections
   - Desktop: horizontal bar with text + icons
   - Mobile: fixed bottom bar with icons only
   - Active tab gets `.tab-active` class

3. **Wrap sections in tab panels** in `wallet.html`:
   ```html
   <div class="tab-panel" id="tab-dashboard"> ... banner, quick actions, wallet info ... </div>
   <div class="tab-panel" id="tab-send" style="display:none"> ... send form, contact form, contacts ... </div>
   <div class="tab-panel" id="tab-activity" style="display:none"> ... transactions, DLQ ... </div>
   <div class="tab-panel" id="tab-settings" style="display:none"> ... settings form ... </div>
   <div class="tab-panel" id="tab-debug" style="display:none"> ... debug section ... </div>
   ```

4. **Add CSS**:
   - `.tab-bar` with `position: sticky; top: 0; z-index: 100`
   - `.tab-bar-mobile` with `position: fixed; bottom: 0` for `max-width: 768px`
   - `.tab-btn` with `.tab-active` state
   - `.tab-panel` as block/none toggle
   - Ensure no `scroll-behavior: smooth` (Tor preference)

5. **Add JS `switchTab()` function**:
   - Show requested panel, hide others
   - Update tab bar active state
   - Update `location.hash` (without reload)
   - Store last tab in `safeStorageSet` for return visits
   - Map legacy hashes: `#send-form` -> `tab-send`, `#contacts` -> `tab-send`, `#transactions` -> `tab-activity`, `#settings` -> `tab-settings`, `#dlq` -> `tab-activity`
   - On DOMContentLoaded: check hash, restore last tab, or default to dashboard

6. **Update Quick Actions**: intercept click on `.action-card` links, call `switchTab()` instead of relying on scroll-to-anchor.

7. **Graceful degradation**: If JS is disabled, all `tab-panel` divs need `display:block` in a `<noscript><style>` block, giving the same scroll-based experience as today.

---

## 6. Mobile-Specific Improvements (with tabs)

### 6.1 Bottom tab bar

- 5 icons, evenly spaced, ~56px tall (iOS/Android standard)
- Active tab icon gets accent color + label text
- Inactive tabs show icon only (saves horizontal space)
- Safe-area padding for devices with home bars (`env(safe-area-inset-bottom)`)

### 6.2 Section headers

Each tab panel gets a clear H2 title at the top so the user always knows where they are, even without the tab bar visible during scroll.

### 6.3 Reduced scroll depth

| Tab | Current scroll distance | With tabs |
|-----|------------------------|-----------|
| Dashboard | 0-500px (top of page) | 0-500px (same, but nothing below) |
| Send & Contacts | 500-2500px | 0-1000px |
| Activity | 2500-3500px | 0-700px |
| Settings | 3500-4500px | 0-1100px |
| Debug | 4500-5500px+ | 0-800px |

Each tab becomes a self-contained scroll context. Maximum scroll depth drops from ~5,500px to ~1,100px.

### 6.4 Touch targets

Tab bar buttons should be minimum 44x44px (WCAG 2.5.5) and have sufficient spacing to prevent mis-taps.

---

## 7. Tor Browser Compatibility Checklist

| Feature | Tor Standard | Tor Safer | Tor Safest | Notes |
|---------|-------------|-----------|------------|-------|
| CSS `display: none/block` toggle | Yes | Yes | Yes | Pure CSS, no restrictions |
| `position: sticky` | Yes | Yes | Yes | Supported in Tor Browser (Firefox ESR) |
| `position: fixed` (mobile bottom bar) | Yes | Yes | Yes | Standard CSS |
| `env(safe-area-inset-bottom)` | Yes | Yes | Yes | CSS env(), no JS required |
| Hash change (`location.hash = ...`) | Yes | Yes | No (JS off) | Graceful fallback: all tabs visible |
| `sessionStorage` | Yes | Maybe | No | Already handled by `safeStorageSet()` |
| `history.replaceState()` | Yes | Yes | No (JS off) | Only used for clean URL, not critical |
| Font Awesome CSS icons | Yes | Yes | Yes | CSS-only, no JS |
| `data-action` click delegation | Yes | Yes | No (JS off) | Existing pattern, fallback: full page |
| `@media` queries (responsive) | Yes | Yes | Yes | Pure CSS |
| CSS Grid/Flexbox (tab bar layout) | Yes | Yes | Yes | Supported in Firefox ESR |
| `backdrop-filter` | Depends | Depends | Depends | NOT used in tab bar (existing issue only on loading overlay) |
| Smooth scroll | Not recommended | N/A | N/A | Not used; tabs use instant display toggle |

**Summary**: Tab navigation works at all Tor security levels where JS is available (Standard and Safer on `.onion`/localhost). At Safest level (JS disabled), the page gracefully degrades to the current full-page scroll layout via `<noscript>` CSS.

---

## 8. Alternatives Considered

### 8.1 Accordion (collapsible sections)

- Fold each section behind a clickable header
- **Rejected**: Still a single scroll context. Closed accordions give no visual summary. Multiple open accordions recreate the current problem. Not a standard mobile pattern for app-like UIs.

### 8.2 Sidebar navigation (hamburger menu)

- Off-canvas sidebar with section links, common in admin panels
- **Rejected**: Adds a layer of indirection (open menu, find item, click). Tab bar is one-tap. Sidebar requires more JS for open/close animations. Mobile sidebars feel heavy.

### 8.3 Multi-page with AJAX loading

- Each tab loads content on demand via `fetch()`
- **Rejected for now**: Each AJAX call over Tor is an additional 3-10 second round-trip. Would require new PHP endpoints returning HTML fragments or JSON. Significantly more complex. Could be a Phase 2 optimization for the Debug tab specifically (heavy server queries for logs).

### 8.4 Single-page app framework (Vue, React, etc.)

- Full SPA with client-side routing
- **Rejected**: Contradicts the project's vanilla-JS philosophy, adds build tooling, increases bundle size, creates Tor compatibility risks, massive refactor for minimal UX gain over simple tabs.

---

## 9. Future Enhancements (out of scope for this PR)

1. **Lazy-load Debug tab**: Defer the debug log queries until the user actually switches to the Debug tab (AJAX load). This would noticeably speed up initial page load.

2. **Badge counts on tabs**: Show notification counts on Activity tab (in-progress transactions) and DLQ count on Activity tab badge (already computed server-side as `$dlqPendingCount`).

3. **Swipe navigation on mobile**: Horizontal swipe to switch tabs. Requires touch event JS. Low priority — standard tab bar taps are sufficient.

4. **Tab memory per session**: Remember which sub-tab within Debug or which advanced-settings category was last open.

5. **Loading skeleton**: Show a lightweight placeholder while the full content renders, reducing perceived load time on Tor.

---

## 10. Summary

| Metric | Before | After |
|--------|--------|-------|
| Sections visible at once | 9+ | 1 tab (2-3 sections max) |
| Max scroll depth (mobile) | ~5,500px | ~1,100px |
| Navigation taps to reach Settings | Scroll or 1 tap (quick action) + long scroll | 1 tap (tab bar, always visible) |
| Navigation taps to reach Debug | Scroll to bottom | 1 tap |
| Persistent navigation | None (quick actions scroll away) | Always-visible tab bar |
| Tor round-trips per tab switch | N/A (single page) | 0 (client-side toggle) |
| Initial load time | Same | Same (potential future improvement with lazy Debug) |
| JS required | Yes (already) | Yes (graceful `<noscript>` fallback) |
| PHP/backend changes | N/A | None |
| New dependencies | N/A | None |
| Estimated new code | N/A | ~310 lines (240 new + 70 modified) |

**Recommendation**: Implement client-side tabs as described. The change is low-risk (no backend changes, graceful degradation), moderate effort (~310 lines), and addresses the core mobile usability problems while maintaining full Tor Browser compatibility.
