# GAds Toolkit — SEO content prototype

Vị trí chèn: sau section `Plugin WordPress chặn click ảo Google Ads hiệu quả nhất` và trước CTA cuối hiện tại.

## Quy đổi sang Bricks / GenerateBlocks
- `.gseo-section` → Section / Container full width
- `.gseo-inner` → Inner Container (max-width 1180px)
- `.gseo-grid` → Grid / Container display grid
- Heading dùng H2/H3 thật để giữ semantic SEO
- FAQ có thể dùng native Details hoặc Accordion element
- CSS đã namespace `gseo-` để giảm xung đột theme/plugin
- JS chỉ dùng IntersectionObserver cho reveal; có thể bỏ hoàn toàn nếu muốn tối giản

## Assets
6 ảnh WebP đã tối ưu nằm trong `/assets`.
