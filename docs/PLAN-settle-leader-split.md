# PLAN-settle-leader-split.md - Chốt sổ & Phân phối lợi nhuận cho Leader

Tài liệu này mô tả kế hoạch nâng cấp tính năng "Settle & Generate Payment" để hỗ trợ chia sẻ phần trăm (split profit) giữa Nhân viên (Staff) và Quản lý (Leader).

## 1. Phân tích hiện trạng
- **Chức năng**: Bulk Action `generate_payment` trong `PayoutLogResource`.
- **Luồng hiện tại**: Admin chọn danh sách giao dịch -> Nhập Rate và % -> Hệ thống tạo **01** bản ghi `UserPayment` cho Nhân viên.
- **Vấn đề**: Chưa có cơ chế để thanh toán cho Leader cùng lúc. Hiện tại phải làm thủ công hoặc chưa có chỗ nhập thông tin Leader.

## 2. Giải pháp đề xuất

### 2.1. Cập nhật Form nhập liệu (Popup)
Thêm các trường sau vào Form của Bulk Action `generate_payment`:
- **Staff Split**:
    - `payout_percentage`: % cho Nhân viên (Mặc định 35%).
    - `payout_rate`: Tỷ giá cho Nhân viên (Mặc định 21000).
- **Leader Split**:
    - `leader_id`: Chọn Leader (Danh sách User).
    - `leader_percentage`: % cho Leader (Mặc định 65%).
    - `leader_rate`: Tỷ giá cho Leader (Mặc định 21000).

### 2.2. Cập nhật Logic xử lý (Action)
Trong vòng lặp xử lý từng nhóm giao dịch (`groupedLogs`):
1. Tính toán `total_usd` của nhóm.
2. **Tạo Payment cho Nhân viên**:
    - Tính `net_usd_staff = total_usd * (payout_percentage / 100)`.
    - Tính `total_vnd_staff = net_usd_staff * payout_rate`.
    - Lưu vào bảng `UserPayment` cho `user_id` (Nhân viên).
3. **Tạo Payment cho Leader (nếu có chọn Leader)**:
    - Tính `net_usd_leader = total_usd * (leader_percentage / 100)`.
    - Tính `total_vnd_leader = net_usd_leader * leader_rate`.
    - Lưu vào bảng `UserPayment` cho `leader_id`.
4. Liên kết các `PayoutLog` liên quan đến cả 2 `UserPayment` (Cần xem xét cách lưu trữ liên kết N-N hoặc dùng Batch ID chung).

## 3. Các bước thực hiện

### Giai đoạn 1: Chuẩn bị & UI
- [ ] Mở rộng Form của Bulk Action trong `app/Filament/Resources/PayoutLogResource.php`.
- [ ] Thêm logic hiển thị/ẩn các trường Leader nếu cần.
- [ ] Thêm validation để đảm bảo tổng % không vượt quá 100% (hoặc cảnh báo).

### Giai đoạn 2: Xử lý Logic
- [ ] Sửa hàm `action` của `generate_payment`.
- [ ] Implement việc tạo 2 bản ghi `UserPayment`.
- [ ] Đảm bảo `batch_id` được gán giống nhau cho cả 2 bản ghi để dễ dàng quản lý theo lô.
- [ ] Gán `user_payment_id` trong `PayoutLog` (Lưu ý: Một `PayoutLog` hiện tại chỉ có 1 cột `user_payment_id`. Nếu chia cho 2 người, cần giải pháp: hoặc lưu ID của Payment chính, hoặc bổ sung bảng pivot).

## 4. Câu hỏi Socratic (Cần User xác nhận)

> [!IMPORTANT]
> 1. **Liên kết Dữ liệu**: Một giao dịch (`PayoutLog`) hiện chỉ có 1 cột `user_payment_id`. Nếu chia tiền cho 2 người (2 bản ghi `UserPayment`), bạn muốn cột này lưu ID của ai? (Thường là Nhân viên, còn Leader sẽ được truy vấn qua Batch ID).
> 2. **Danh sách Leader**: Leader là bất kỳ User nào có role `admin`/`finance` hay có một danh sách riêng?
> 3. **Tự động hóa**: Bạn có muốn khi nhập 35% cho NV thì hệ thống tự nhảy 65% cho Leader không?
> 4. **Tỷ giá**: Tỷ giá cho Leader và Staff thường luôn giống nhau hay có khi nào khác nhau không?

## 5. Kế hoạch xác minh (Verification)
- [ ] Kiểm tra việc chọn 10 đơn hàng, nhập 35% Staff - 65% Leader.
- [ ] Kiểm tra bảng `user_payments` có xuất hiện 2 dòng mới không.
- [ ] Kiểm tra số tiền VND tính toán có chính xác theo công thức không.
