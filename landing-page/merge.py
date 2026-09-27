import re

with open('../prototype/dashboard-redesign.html', 'r', encoding='utf-8') as f:
    dashboard_html = f.read()

with open('../prototype/settings-redesign.html', 'r', encoding='utf-8') as f:
    settings_html = f.read()

# Extract content within body tag, or specifically the .wp-wrap
def extract_wp_wrap(html):
    match = re.search(r'<div class="wp-wrap[^>]*>([\s\S]*?)</div>\s*<script', html)
    if match:
        return f'<div class="wp-wrap space-y-6">{match.group(1)}</div>'
    match = re.search(r'<div class="wp-wrap[^>]*>([\s\S]*?)</body>', html)
    if match:
        return f'<div class="wp-wrap space-y-6">{match.group(1)}'
    return ""

def extract_script(html):
    match = re.search(r'<script>([\s\S]*?)</script>', html)
    if match:
        return match.group(0)
    return ""

dash_content = extract_wp_wrap(dashboard_html)
dash_script = extract_script(dashboard_html)
sett_content = extract_wp_wrap(settings_html)
sett_script = extract_script(settings_html)

# Wrap them in display toggle divs
embedded_html = f"""
            <div id="demo-content" class="bg-gray-100 p-4 md:p-8 rounded-b-xl max-h-[800px] overflow-y-auto">
                <!-- Toggle CSS -->
                <style>
                    .toggle-checkbox:checked {{ right: 0; border-color: #ef4444; }}
                    .toggle-checkbox:checked + .toggle-label {{ background-color: #ef4444; }}
                    .toggle-checkbox:not(:checked) {{ border-color: #10b981; }}
                    .toggle-checkbox:not(:checked) + .toggle-label {{ background-color: #10b981; }}
                    
                    .settings-toggle:checked {{ right: 0; border-color: #3b82f6; }}
                    .settings-toggle:checked + .toggle-label {{ background-color: #3b82f6; }}
                    .settings-toggle:not(:checked) {{ border-color: #d1d5db; }}
                    .settings-toggle:not(:checked) + .toggle-label {{ background-color: #d1d5db; }}
                </style>
                <div id="demo-dashboard">
                    {dash_content}
                </div>
                <div id="demo-settings" style="display: none;">
                    {sett_content}
                </div>
            </div>
            {dash_script}
            {sett_script}
"""

with open('index.html', 'r', encoding='utf-8') as f:
    index_html = f.read()

# Replace the iframe wrapper block
pattern = r'<!-- Prototype Iframe Wrapper -->[\s\S]*?</div>\s*<div class="text-center mt-10">'
new_index = re.sub(pattern, f'<!-- Prototype Direct Embed Wrapper -->\n{embedded_html}\n            <div class="text-center mt-10">', index_html)

# Update buttons to use JS instead of changing iframe src
btn_pattern = r'<!-- Prototype Tabs -->\s*<div class="flex justify-center flex-wrap gap-4 mb-8">\s*<button onclick="[^"]+" id="btn-dashboard"[^>]+>[\s\S]*?</button>\s*<button onclick="[^"]+" id="btn-settings"[^>]+>[\s\S]*?</button>\s*</div>'

new_btns = """<!-- Prototype Tabs -->
            <div class="flex justify-center flex-wrap gap-4 mb-8">
                <button onclick="document.getElementById('demo-dashboard').style.display='block'; document.getElementById('demo-settings').style.display='none'; this.classList.add('bg-blue-600', 'text-white'); this.classList.remove('bg-white', 'text-gray-700'); document.getElementById('btn-settings').classList.remove('bg-blue-600', 'text-white'); document.getElementById('btn-settings').classList.add('bg-white', 'text-gray-700');" id="btn-dashboard" class="bg-blue-600 text-white px-6 py-2.5 rounded-full font-medium transition-all shadow-sm border border-gray-200">
                    <i class="fa-solid fa-chart-line mr-2"></i> Dashboard Thống Kê
                </button>
                <button onclick="document.getElementById('demo-dashboard').style.display='none'; document.getElementById('demo-settings').style.display='block'; this.classList.add('bg-blue-600', 'text-white'); this.classList.remove('bg-white', 'text-gray-700'); document.getElementById('btn-dashboard').classList.remove('bg-blue-600', 'text-white'); document.getElementById('btn-dashboard').classList.add('bg-white', 'text-gray-700');" id="btn-settings" class="bg-white text-gray-700 hover:bg-gray-50 px-6 py-2.5 rounded-full font-medium transition-all shadow-sm border border-gray-200">
                    <i class="fa-solid fa-gear mr-2"></i> Cài Đặt & Quy Tắc Chặn
                </button>
            </div>"""

new_index = re.sub(btn_pattern, new_btns, new_index)

with open('index.html', 'w', encoding='utf-8') as f:
    f.write(new_index)

print("Done extracting and embedding.")
