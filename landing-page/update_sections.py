import re

with open('index.html', 'r', encoding='utf-8') as f:
    html = f.read()

# 1. New Features Section
new_features = """
    <!-- Features Section -->
    <section id="tinh-nang" class="py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="text-3xl md:text-4xl font-bold mb-4">Tính năng vượt trội</h2>
                <p class="text-gray-600 text-lg">Công nghệ tiên tiến, bảo vệ toàn diện</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="p-8 rounded-2xl bg-gray-50 border border-gray-100 hover:shadow-xl transition-shadow duration-300 group">
                    <div class="w-14 h-14 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">
                        🎯
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900">Đồng bộ tự động</h3>
                    <p class="text-gray-600 leading-relaxed">Tự động đẩy IP độc hại lên Google Ads, chặn ở cấp tài khoản. Không cần thao tác thủ công.</p>
                </div>

                <!-- Feature 2 -->
                <div class="p-8 rounded-2xl bg-gray-50 border border-gray-100 hover:shadow-xl transition-shadow duration-300 group">
                    <div class="w-14 h-14 bg-red-100 text-red-600 rounded-xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">
                        ⚡
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900">Chặn tức thì</h3>
                    <p class="text-gray-600 leading-relaxed">Phát hiện và chặn click ảo theo thời gian thực. Bot không có cơ hội lãng phí tiền của bạn.</p>
                </div>

                <!-- Feature 3 -->
                <div class="p-8 rounded-2xl bg-gray-50 border border-gray-100 hover:shadow-xl transition-shadow duration-300 group">
                    <div class="w-14 h-14 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">
                        🧠
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900">Smart Cross-IP</h3>
                    <p class="text-gray-600 leading-relaxed">Chặn thiết bị đã bị cấm ngay cả khi đối thủ đổi IP. Công nghệ cookie tracking thông minh.</p>
                </div>

                <!-- Feature 4 -->
                <div class="p-8 rounded-2xl bg-gray-50 border border-gray-100 hover:shadow-xl transition-shadow duration-300 group">
                    <div class="w-14 h-14 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">
                        📊
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900">Thống kê chi tiết</h3>
                    <p class="text-gray-600 leading-relaxed">Dashboard trực quan với biểu đồ phân tích traffic Ads vs Organic. Dễ dàng ra quyết định.</p>
                </div>

                <!-- Feature 5 -->
                <div class="p-8 rounded-2xl bg-gray-50 border border-gray-100 hover:shadow-xl transition-shadow duration-300 group">
                    <div class="w-14 h-14 bg-indigo-100 text-indigo-600 rounded-xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">
                        📱
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900">Thông báo Telegram</h3>
                    <p class="text-gray-600 leading-relaxed">Nhận cảnh báo tức thì khi phát hiện click ảo. Luôn kiểm soát mọi lúc mọi nơi.</p>
                </div>

                <!-- Feature 6 -->
                <div class="p-8 rounded-2xl bg-gray-50 border border-gray-100 hover:shadow-xl transition-shadow duration-300 group">
                    <div class="w-14 h-14 bg-gray-800 text-white rounded-xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">
                        🔒
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900">Bảo mật tuyệt đối</h3>
                    <p class="text-gray-600 leading-relaxed">Dữ liệu lưu trữ 100% trên server của bạn. Tuân thủ GDPR & CCPA.</p>
                </div>
            </div>
        </div>
    </section>
"""

# 2. Pricing Section
pricing_section = """
    <!-- Pricing Section -->
    <section id="bang-gia" class="py-24 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="text-3xl md:text-4xl font-bold mb-4">Bảng giá API Key</h2>
                <p class="text-gray-600 text-lg">Chọn gói phù hợp với nhu cầu của bạn</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-6xl mx-auto">
                <!-- Trial -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 flex flex-col relative overflow-hidden">
                    <div class="absolute top-0 right-0 bg-gray-100 text-gray-600 text-xs font-bold px-3 py-1 rounded-bl-lg">POPULAR</div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">Trial</h3>
                    <div class="flex items-baseline mb-6">
                        <span class="text-3xl font-extrabold text-gray-900">MIỄN PHÍ</span>
                        <span class="text-gray-500 ml-1">/10 ngày</span>
                    </div>
                    <p class="text-blue-600 font-medium mb-6 text-sm">🎁 Tặng key dùng thử tính năng Pro</p>
                    <ul class="space-y-4 mb-8 flex-1">
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Trải nghiệm full tính năng Pro</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Đồng bộ tự động Google Ads</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Smart Cross-IP Blocking</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Thông báo Telegram</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Dashboard thống kê</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Không cần thẻ tín dụng</span></li>
                    </ul>
                    <a href="mailto:phu@pdl.vn?subject=Đăng%20ký%20Trial%2010%20ngày%20-%20GAds%20Toolkit" class="block w-full py-3 px-4 bg-gray-100 hover:bg-gray-200 text-gray-800 text-center font-bold rounded-lg transition-colors">Đăng ký ngay</a>
                </div>

                <!-- Monthly -->
                <div class="bg-white rounded-2xl shadow-xl border-2 border-blue-500 p-8 flex flex-col relative transform md:-translate-y-4">
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">Monthly</h3>
                    <div class="flex items-baseline mb-2">
                        <span class="text-4xl font-extrabold text-gray-900">100K</span>
                        <span class="text-gray-500 ml-1">/tháng</span>
                    </div>
                    <p class="text-gray-400 text-xs italic mb-6">* Chưa bao gồm VAT</p>
                    <ul class="space-y-4 mb-8 flex-1">
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600 font-medium">Tất cả tính năng Pro</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Thanh toán linh hoạt</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Support ưu tiên</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Hỗ trợ cài đặt</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Cập nhật miễn phí</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Hoàn tiền nếu không hiệu quả</span></li>
                    </ul>
                    <a href="mailto:phu@pdl.vn?subject=Mua%20gói%20Monthly%20-%20GAds%20Toolkit" class="block w-full py-3 px-4 bg-blue-600 hover:bg-blue-700 text-white text-center font-bold rounded-lg transition-colors shadow-md">Mua ngay</a>
                </div>

                <!-- Yearly -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 flex flex-col relative overflow-hidden">
                    <div class="absolute top-0 right-0 bg-red-500 text-white text-xs font-bold px-3 py-1 rounded-bl-lg">TIẾT KIỆM 33%</div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">Yearly</h3>
                    <div class="flex items-baseline mb-2">
                        <span class="text-4xl font-extrabold text-gray-900">800K</span>
                        <span class="text-gray-500 ml-1">/năm</span>
                    </div>
                    <p class="text-gray-400 text-xs italic mb-6">* Chưa bao gồm VAT</p>
                    <ul class="space-y-4 mb-8 flex-1">
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600 font-medium">Tất cả tính năng Pro</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600 text-red-600 font-medium">Tiết kiệm 33%</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Smart Cross-IP Blocking</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Tư vấn setup chuyên sâu</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Hỗ trợ 24/7</span></li>
                        <li class="flex items-start"><i class="fa-solid fa-check text-green-500 mt-1 mr-3"></i><span class="text-gray-600">Hoàn tiền nếu không hiệu quả</span></li>
                    </ul>
                    <a href="mailto:phu@pdl.vn?subject=Mua%20gói%20Yearly%20-%20GAds%20Toolkit" class="block w-full py-3 px-4 bg-gray-800 hover:bg-gray-900 text-white text-center font-bold rounded-lg transition-colors">Mua ngay</a>
                </div>
            </div>
        </div>
    </section>
"""

# 3. SEO Text Section
seo_section = """
    <!-- SEO Content Section -->
    <section class="py-20 bg-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-gray-700">
            <h2 class="text-3xl font-bold text-gray-900 mb-6">Plugin WordPress chặn click ảo Google Ads hiệu quả nhất</h2>
            <p class="mb-6 leading-relaxed">GAds Toolkit là <a href="https://phudigital.github.io/gads-toolkit/#features" class="text-blue-600 hover:underline">plugin WordPress chặn click ảo Google Ads</a> được tin dùng bởi hàng trăm doanh nghiệp tại Việt Nam. Với công nghệ AI và machine learning tiên tiến, plugin giúp bạn phát hiện và chặn click ảo tự động, bảo vệ ngân sách quảng cáo khỏi các cuộc tấn công từ bot và đối thủ cạnh tranh.</p>
            
            <h3 class="text-2xl font-bold text-gray-900 mt-10 mb-4">Tại sao GAds Toolkit là plugin WordPress chặn click ảo tốt nhất?</h3>
            <p class="mb-6 leading-relaxed">Khác với các <a href="https://phudigital.github.io/gads-toolkit/#pricing" class="text-blue-600 hover:underline">plugin WordPress chặn click ảo Google Ads</a> khác trên thị trường, GAds Toolkit không chỉ đơn thuần chặn IP mà còn tích hợp Smart Cross-IP Blocking - công nghệ độc quyền giúp nhận diện và chặn thiết bị đã bị cấm ngay cả khi chúng thay đổi địa chỉ IP.</p>
            
            <h4 class="text-xl font-bold text-gray-900 mt-8 mb-4">🎯 Lợi ích vượt trội của plugin WordPress chặn click ảo GAds Toolkit:</h4>
            <ul class="space-y-2 mb-8">
                <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> Đồng bộ tự động với Google Ads API - Không cần upload thủ công</li>
                <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> Chặn real-time - Phát hiện và chặn click ảo trong vòng 1 giây</li>
                <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> Hỗ trợ IPv6 - Bảo vệ toàn diện trên mọi loại kết nối</li>
                <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> Dashboard trực quan - Theo dõi và phân tích dễ dàng</li>
                <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> Giá cả hợp lý - Chỉ 100.000 VNĐ/tháng</li>
            </ul>

            <h3 class="text-2xl font-bold text-gray-900 mt-10 mb-4">Cách hoạt động của plugin WordPress chặn click ảo</h3>
            <p class="mb-4"><a href="https://phudigital.github.io/gads-toolkit/#screenshots" class="text-blue-600 hover:underline">Plugin WordPress chặn click ảo Google Ads</a> GAds Toolkit hoạt động theo 4 bước đơn giản:</p>
            <ol class="list-decimal list-inside space-y-3 mb-10 pl-2">
                <li><strong class="text-gray-900">Theo dõi:</strong> Plugin tự động ghi nhận mọi click vào quảng cáo Google Ads trên website của bạn</li>
                <li><strong class="text-gray-900">Phân tích:</strong> AI phân tích hành vi người dùng để phát hiện các dấu hiệu click ảo (click quá nhanh, cùng IP, không tương tác...)</li>
                <li><strong class="text-gray-900">Chặn:</strong> Tự động chặn IP nghi ngờ tại website và đồng bộ lên Google Ads</li>
                <li><strong class="text-gray-900">Thông báo:</strong> Gửi cảnh báo qua Telegram để bạn luôn nắm bắt tình hình</li>
            </ol>

            <h3 class="text-2xl font-bold text-gray-900 mt-10 mb-6">So sánh với các plugin WordPress chặn click ảo khác</h3>
            <div class="overflow-x-auto mb-10">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-100 text-gray-900">
                            <th class="p-4 border border-gray-200 font-bold">Tính năng</th>
                            <th class="p-4 border border-gray-200 font-bold text-blue-600 text-center">GAds Toolkit</th>
                            <th class="p-4 border border-gray-200 font-bold text-center">Plugin khác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="p-4 border border-gray-200 font-medium">Đồng bộ tự động Google Ads</td>
                            <td class="p-4 border border-gray-200 text-center"><i class="fa-solid fa-check text-green-500"></i></td>
                            <td class="p-4 border border-gray-200 text-center"><i class="fa-solid fa-xmark text-red-500"></i></td>
                        </tr>
                        <tr>
                            <td class="p-4 border border-gray-200 font-medium">Smart Cross-IP Blocking</td>
                            <td class="p-4 border border-gray-200 text-center"><i class="fa-solid fa-check text-green-500"></i></td>
                            <td class="p-4 border border-gray-200 text-center"><i class="fa-solid fa-xmark text-red-500"></i></td>
                        </tr>
                        <tr>
                            <td class="p-4 border border-gray-200 font-medium">Hỗ trợ IPv6</td>
                            <td class="p-4 border border-gray-200 text-center"><i class="fa-solid fa-check text-green-500"></i></td>
                            <td class="p-4 border border-gray-200 text-center">Một số</td>
                        </tr>
                        <tr>
                            <td class="p-4 border border-gray-200 font-medium">Thông báo Telegram</td>
                            <td class="p-4 border border-gray-200 text-center"><i class="fa-solid fa-check text-green-500"></i></td>
                            <td class="p-4 border border-gray-200 text-center"><i class="fa-solid fa-xmark text-red-500"></i></td>
                        </tr>
                        <tr class="bg-gray-50 font-bold">
                            <td class="p-4 border border-gray-200">Giá/tháng</td>
                            <td class="p-4 border border-gray-200 text-blue-600 text-center">100K/tháng</td>
                            <td class="p-4 border border-gray-200 text-gray-500 text-center">500K+ /tháng</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-lg leading-relaxed bg-blue-50 p-6 rounded-xl border border-blue-100">Với hơn 500+ website đang sử dụng, GAds Toolkit đã chứng minh là <a href="https://phudigital.github.io/gads-toolkit/#testimonials" class="text-blue-600 font-bold hover:underline">plugin WordPress chặn click ảo Google Ads</a> đáng tin cậy nhất tại Việt Nam. Đừng để ngân sách quảng cáo của bạn bị lãng phí - <a href="https://phudigital.github.io/gads-toolkit/#pricing" class="text-blue-600 font-bold hover:underline">bắt đầu sử dụng ngay hôm nay</a> với giá chỉ 100.000 VNĐ/tháng!</p>
        </div>
    </section>
"""

# Replace the old Features section with the new Features + Pricing + SEO Content
# The old features section starts with: <!-- Features Section -->
# and ends right before <!-- CTA Section -->

pattern = re.compile(r'<!-- Features Section -->[\s\S]*?(?=<!-- CTA Section -->)')
replacement = new_features + "\n" + pricing_section + "\n" + seo_section + "\n"

if pattern.search(html):
    html = pattern.sub(replacement, html)
    with open('index.html', 'w', encoding='utf-8') as f:
        f.write(html)
    print("Successfully replaced features and added pricing and SEO sections.")
else:
    print("Could not find the target sections.")

