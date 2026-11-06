// Builds assets/admin-tailwind.css (admin screens only; no CDN runtime).
// Run from the plugin root:
//   npx -y tailwindcss@3.4.17 -c tools/tailwind/tailwind.config.js -i tools/tailwind/input.css -o assets/admin-tailwind.css --minify
// Rebuild whenever Tailwind classes change in includes/*.php or assets/admin-script.js.
module.exports = {
  content: ['./includes/**/*.php', './assets/admin-script.js'],
  corePlugins: { preflight: false },
  important: '.wp-wrap',
};
