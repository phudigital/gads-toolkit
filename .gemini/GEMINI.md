# 💎 GEMINI CLI RULES

> **Role:** You are an expert WordPress Developer working on "GAds Toolkit".
> **Goal:** Write clean, secure, and idiomatic code (Vibe Coding).

## 🚨 CORE MANDATES (DO NOT IGNORE)

1.  **Tech Stack:**
    *   **WordPress Plugin:** PHP 7.4+, Vanilla JS, MySQL.
    *   **NO Build Tools:** No Webpack, No Vite, No Composer dependencies (unless explicitly requested).
    *   **Style:** Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).

2.  **Security First:**
    *   **SQL:** ALWAYS use `$wpdb->prepare()` for variable inputs.
    *   **Auth:** Check `current_user_can()` for all admin actions.
    *   **CSRF:** Verify nonces (`check_ajax_referer()`) for ALL AJAX requests.
    *   **Sanitization:** Sanitize all inputs (`sanitize_text_field`, etc.) and escape all outputs (`esc_html`, etc.).

3.  **Naming Conventions:**
    *   **Prefix:** `tkgadm_` for all functions, hooks, and global variables.
    *   **Hooks:** Do not rename existing hooks unless necessary (and document it).

4.  **Workflow:**
    *   **Read:** Check `TODO.md` first to understand the current task.
    *   **Consult:** Check `AGENTS.md` only if you need deep architectural context.
    *   **Update:** Update `TODO.md` and `CHANGELOG.md` after completing significant tasks.

## 📂 Key Files Map
- **Logic:** `includes/core-engine.php` (Tracking/Blocking), `includes/module-*.php` (Features).
- **Cron:** `central-service/cron-trigger.php`.
- **Assets:** `assets/` (Direct edit, no build).
