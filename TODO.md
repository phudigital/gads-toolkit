# 📝 Project TODOs

> **Context:** "Fraud Prevention for Google Ads" (gads-toolkit) WordPress Plugin.
> **Status:** Active Development (v2.9.1)

## 🚀 Priority: High (Current Sprint)
- [x] **Core Refactoring:** Consolidate modules (Completed in v2.9.0)
- [x] **OAuth Improvement:** Central OAuth Redirect Handler (Completed in v2.9.1)
- [ ] **Automated Testing Setup:** (From AGENTS.md recommendations)
  - [ ] Setup PHPUnit for WordPress.
  - [ ] Create basic `WP_UnitTestCase` for `core-engine.php`.
- [ ] **Internationalization (i18n):**
  - [ ] Add `.pot` file generation.
  - [ ] Ensure all strings are wrapped in `__()` or `esc_html__()`.

## 🛠️ Maintenance & Optimization
- [ ] **Performance:** Review database queries on `wp_gads_toolkit_stats` for large datasets.
- [ ] **UI/UX:** Verify Chart.js v4.4.0 responsiveness on mobile.
- [ ] **Docs:** Update `AGENTS.md` if new architectural changes occur.

## 🧊 Backlog (Future Ideas)
- [ ] **Fingerprinting:** Add browser fingerprinting for better cross-IP detection.
- [ ] **Export Data:** Allow exporting blocked IPs to CSV/Excel.
- [ ] **White-labeling:** Option to hide plugin branding for agencies.
