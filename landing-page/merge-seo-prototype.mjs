import { readFile, writeFile } from 'node:fs/promises';

const landingPath = new URL('../landing-page/index.html', import.meta.url);
const protoPath = new URL('../gads-click-fraud-seo-prototype/index.html', import.meta.url);

const landingHtml = await readFile(landingPath, 'utf8');
const protoHtml = await readFile(protoPath, 'utf8');

// 1. Extract CSS from prototype (omit global resets * and body so they don't impact host page)
const styleMatch = protoHtml.match(/<style>([\s\S]*?)<\/style>/);
if (!styleMatch) throw new Error('Could not find style in prototype');
let protoCss = styleMatch[1].trim();

// Ensure reset rules inside protoCss are safely scoped to .gseo-block
protoCss = protoCss.replace(/\*\{box-sizing:border-box\}/, '.gseo-block *{box-sizing:border-box}');
protoCss = protoCss.replace(/body\{[^}]+\}/, '');
protoCss = protoCss.replace(/a\{color:var\(--gseo-blue\);text-underline-offset:3px\}/, '.gseo-block a{color:var(--gseo-blue);text-underline-offset:3px}');
protoCss = protoCss.replace(/img\{display:block;max-width:100%;height:auto\}/, '.gseo-block img{display:block;max-width:100%;height:auto}');
protoCss = protoCss.replace(/button,input,textarea,select\{font:inherit\}/, '.gseo-block button,.gseo-block input,.gseo-block textarea,.gseo-block select{font:inherit}');
// In .gseo-block add font family
protoCss = protoCss.replace(
  /\.gseo-block\{overflow:hidden;background:#fff\}/,
  '.gseo-block{overflow:hidden;background:#fff;color:var(--gseo-text);line-height:1.7;-webkit-font-smoothing:antialiased}'
);

// 2. Extract SEO content from prototype <main ...>...</main>
const mainMatch = protoHtml.match(/<main class="gseo-block" id="gads-seo-content">([\s\S]*?)<\/main>/);
if (!mainMatch) throw new Error('Could not find main in prototype');
let seoContent = mainMatch[1].trim();

// Remove the designer endnote ("Phần này được thiết kế để nối trực tiếp vào CTA cuối hiện có của trang, vì vậy prototype không tạo thêm một CTA lớn...")
seoContent = seoContent.replace(/<div class="gseo-endnote">[\s\S]*?<\/div>/, '');

const wrappedSeoBlock = `
    <!-- ========================================== -->
    <!-- SEO Deep-Dive Content (GAds Toolkit)      -->
    <!-- ========================================== -->
    <div class="gseo-block" id="gads-seo-content">
${seoContent}
    </div>
`;

// 3. Extract script from prototype
const scriptMatch = protoHtml.match(/<script>([\s\S]*?)<\/script>\s*<\/body>/);
if (!scriptMatch) throw new Error('Could not find reveal script in prototype');
const protoScript = scriptMatch[1].trim();

// 4. Update landing page HTML
let updatedLanding = landingHtml;

// Add js class helper to head if not present
if (!updatedLanding.includes("document.documentElement.classList.add('js')")) {
  updatedLanding = updatedLanding.replace(
    /<script src="https:\/\/cdn\.tailwindcss\.com"><\/script>/,
    `<script>document.documentElement.classList.add('js');</script>\n    <script src="https://cdn.tailwindcss.com"></script>`
  );
}

// Ensure favicon link is relative
updatedLanding = updatedLanding.replace(
  /<link rel="icon" type="image\/svg\+xml" href="\/favicon-landing\.svg">/,
  `<link rel="icon" type="image/svg+xml" href="favicon-landing.svg">`
);
updatedLanding = updatedLanding.replace(
  /<link rel="alternate icon" href="\/favicon-landing\.svg">/,
  `<link rel="alternate icon" href="favicon-landing.svg">`
);

// Insert CSS into existing <style>
if (!updatedLanding.includes('/* GADS SEO CONTENT BLOCK */')) {
  const cssToInsert = `\n        /* ==========================================================\n           GADS SEO CONTENT BLOCK — Scoped Prototype Styles\n           ========================================================== */\n        ${protoCss}\n    </style>`;
  updatedLanding = updatedLanding.replace('</style>', cssToInsert);
}

// Insert SEO content after "Plugin WordPress chặn click ảo Google Ads hiệu quả nhất" section and before <!-- CTA Section -->
const seoSectionEndTarget = `            <p class="text-lg leading-relaxed bg-blue-50 p-6 rounded-xl border border-blue-100">Với hơn 500+ website đang sử dụng, GAds Toolkit đã chứng minh là <a href="https://phudigital.github.io/gads-toolkit/#testimonials" class="text-blue-600 font-bold hover:underline">plugin WordPress chặn click ảo Google Ads</a> đáng tin cậy nhất tại Việt Nam. Đừng để ngân sách quảng cáo của bạn bị lãng phí - <a href="https://phudigital.github.io/gads-toolkit/#pricing" class="text-blue-600 font-bold hover:underline">bắt đầu sử dụng ngay hôm nay</a> với giá chỉ 100.000 VNĐ/tháng!</p>
        </div>
    </section>`;

if (!updatedLanding.includes(seoSectionEndTarget)) {
  throw new Error('Target section end not found in landing-page/index.html');
}

if (!updatedLanding.includes('id="gads-seo-content"')) {
  updatedLanding = updatedLanding.replace(
    seoSectionEndTarget,
    `${seoSectionEndTarget}\n${wrappedSeoBlock}`
  );
}

// Insert reveal script before </body>
if (!updatedLanding.includes('gseo-reveal')) {
  const scriptToInsert = `
    <!-- Script to handle reveal effect for SEO section -->
    <script>
${protoScript}
    </script>
</body>`;
  updatedLanding = updatedLanding.replace('</body>', scriptToInsert);
}

await writeFile(landingPath, updatedLanding, 'utf8');
console.log('Successfully updated landing-page/index.html');
