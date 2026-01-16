=== Fraud Prevention for Google Ads ===
Contributors: phudigital
Tags: google ads, click fraud, ip blocker, adwords, fraud protection
Requires at least: 5.6
Tested up to: 6.7
Stable tag: 2.1.5
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Comprehensive solution to track and prevent click fraud from Google Ads with IP and Wildcard blocking.

== Description ==

**Fraud Prevention for Google Ads** is a powerful toolkit designed to help you monitor and block invalid clicks (Click Fraud) on your Google Ads campaigns.

The plugin automatically tracks visitors coming from Google Ads (identified by `gad_source` parameter), analyzes their behavior, and allows you to block suspicious IPs or entire IP ranges using smart Wildcard patterns.

**Key Features:**

*   **Smart Tracking:** Automatically logs IP, visit time, and landing URLs for ad traffic.
*   **Wildcard Blocking:** Block specific IPs (e.g., `192.168.1.1`) or entire ranges (e.g., `193.186.4.*` or `10.*.*.*`).
*   **Visual Dashboard:** Modern interface with Chart.js to visualize traffic trends.
*   **UTM Analysis:** Automatically extracts and displays `utm_term` for better keyword insight.
*   **One-click Blocking:** Toggle switch to instantly block/unblock IPs from the statistics table.
*   **Bulk Management:** Import/Export blocked IPs easily.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. A new menu item **GAds Toolkit** will appear in your admin dashboard.
4. The plugin will automatically create necessary database tables (`tkgad_moi` and `tkgad_moi_blocked_ips`).

== Screenshots ==

1. **Dashboard & Statistics:** Main interface showing visitor logs, charts, and date filters.
2. **IP Management:** Popup to add or import blocked IPs supporting wildcards.

== Changelog ==

= 2.1.5 =
* Updated branding to comply with WordPress.org guidelines.
* Improved dashboard UI.
* Added "Copy Blocked IPs" feature.

= 2.1.0 =
* Introduced Wildcard blocking.
* Added Chart.js visualization.
* Refactored codebase for better performance.
