@extends('layouts.dashboard')

@section('content')
@php
    $rooms = \App\Models\Room::with('roomType')->orderByDesc('floor')->orderBy('room_number')->get();

    // Đồng bộ trạng thái soon_to_checkin
    \DB::update("
        UPDATE rooms SET status = 'available'
        WHERE status = 'soon_to_checkin'
          AND id NOT IN (
              SELECT br.room_id FROM booking_rooms br
              JOIN bookings b ON b.id = br.booking_id
              WHERE b.check_in = CURDATE()
                AND b.status = 'confirmed'
          )
    ");
    \DB::update("
        UPDATE rooms r
        JOIN booking_rooms br ON r.id = br.room_id
        JOIN bookings b ON b.id = br.booking_id
        SET r.status = 'soon_to_checkin'
        WHERE b.check_in = CURDATE()
          AND b.status = 'confirmed'
          AND r.status = 'available'
    ");

    // Reload sau khi sync
    $rooms = \App\Models\Room::with('roomType')->orderByDesc('floor')->orderBy('room_number')->get();

    $floors = [];
    $uniqueTypes = [];
    $uniqueCapacities = [];
    $counts = ['available' => 0, 'soon_to_checkin' => 0, 'occupied' => 0, 'soon_to_checkout' => 0, 'cleaning' => 0, 'maintenance' => 0];

    foreach ($rooms as $r) {
        $amenities = \DB::table('amenities')
            ->join('room_type_amenities', 'amenities.id', '=', 'room_type_amenities.amenity_id')
            ->where('room_type_amenities.room_type_id', $r->room_type_id)
            ->pluck('amenity_name')->toArray();
        $r->amenities_list = implode(', ', $amenities);
        $floors[$r->floor][] = $r;
        $uniqueTypes[$r->room_type_id] = $r->roomType->name ?? '';
        $uniqueCapacities[] = $r->roomType->max_guests ?? 0;
        if (isset($counts[$r->status])) $counts[$r->status]++;
    }
    krsort($floors);
    asort($uniqueTypes);
    $uniqueCapacities = array_unique($uniqueCapacities);
    sort($uniqueCapacities);
    $allFloors = array_keys($floors);
@endphp

<style>
    .status-available      { background: #e2f4e8; border: 1px solid #c3ebd2; }
    .status-available .room-icon { color: #2d9f58; }
    .status-soon_to_checkin { background: #fff4ce; border: 1px solid #ffe8a1; }
    .status-soon_to_checkin .room-icon { color: #d44b25; }
    .status-occupied       { background: #e6efff; border: 1px solid #cce0ff; }
    .status-occupied .room-icon { color: #2b6ff2; }
    .status-soon_to_checkout { background: #ffe5e5; border: 1px solid #ffcccc; }
    .status-soon_to_checkout .room-icon { color: #dc3545; }
    .status-cleaning       { background: #fff8e1; border: 1px solid #ffe082; }
    .status-cleaning .room-icon { color: #f59e0b; }
    .status-maintenance    { background: #fce4e4; border: 1px solid #f5c6c6; }
    .status-maintenance .room-icon { color: #dc3545; }

    .room-card { border-radius: 12px; padding: 18px 15px; position: relative; cursor: pointer; transition: all 0.25s; height: 108px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
    .room-card:hover { transform: translateY(-4px); box-shadow: 0 8px 16px rgba(0,0,0,0.06); }
    .room-card.selected { border: 2px solid #0d6efd !important; box-shadow: 0 0 0 4px rgba(13,110,253,0.15); }
    .room-number { font-size: 1.35rem; font-weight: 700; color: #1e293b; margin: 0; }
    .room-status-text { font-size: 0.8rem; font-weight: 600; margin: 2px 0 0 0; }
    .room-capacity { font-size: 0.75rem; color: #64748b; margin: 0; font-weight: 500; }
    .room-icon { position: absolute; top: 18px; right: 15px; font-size: 1.25rem; }

    .filter-card { background: white; padding: 16px 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 24px; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; align-items: flex-end; }
    .filter-group { display: flex; flex-direction: column; gap: 6px; }
    .filter-label { font-size: 0.775rem; color: #475569; font-weight: 600; text-transform: uppercase; letter-spacing: 0.025em; }
    .filter-card select { border-radius: 8px; border: 1px solid #cbd5e1; padding: 8px 12px; font-size: 0.875rem; color: #334155; background: #fff; }

    .legend-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 24px; }
    .legend-badge { padding: 10px 16px; border-radius: 30px; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s; border: 1px solid transparent; user-select: none; }
    .legend-badge:hover { transform: translateY(-1px); }
    .legend-badge.active-filter { border-color: #0d6efd !important; box-shadow: 0 0 0 3px rgba(13,110,253,0.12) !important; }
    .legend-badge .dot { width: 8px; height: 8px; border-radius: 50%; }

    .detail-img { width: 100%; height: 180px; object-fit: cover; border-radius: 12px; margin-bottom: 20px; background: #eee; }
    .detail-row { display: flex; margin-bottom: 12px; font-size: 0.9rem; }
    .detail-label { width: 110px; color: #64748b; display: flex; align-items: center; gap: 8px; font-weight: 500; }
    .detail-value { flex: 1; font-weight: 600; color: #1e293b; }
    .btn-action { border-radius: 8px; padding: 10px; font-weight: 600; width: 100%; margin-bottom: 10px; display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 0.875rem; }
    .empty-panel-flex { display: flex; align-items: center; justify-content: center; flex-direction: column; }
</style>

<!-- Main Content -->
<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold m-0 text-dark">Sơ đồ trạng thái phòng</h4>
            <p class="text-muted m-0" style="font-size:0.85rem;">Quản lý và cập nhật sơ đồ đặt phòng thời gian thực</p>
        </div>
        <div class="input-group shadow-sm" style="width:320px;border-radius:8px;overflow:hidden;">
            <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-search text-muted"></i></span>
            <input type="text" id="search-input" class="form-control border-start-0 py-2"
                   placeholder="Tìm số phòng, loại phòng..." oninput="filterRooms()">
        </div>
    </div>

    <!-- Legend -->
    <div class="legend-row">
        <div class="legend-badge status-available" data-status-val="available" onclick="toggleLegendFilter(this)">
            <span class="dot" style="background:#2d9f58"></span> Đang trống (<span id="count-available">{{ $counts['available'] }}</span>)
        </div>
        <div class="legend-badge status-soon_to_checkin" data-status-val="soon_to_checkin" onclick="toggleLegendFilter(this)">
            <span class="dot" style="background:#d44b25"></span> Sắp nhận (<span id="count-soon_to_checkin">{{ $counts['soon_to_checkin'] }}</span>)
        </div>
        <div class="legend-badge status-occupied" data-status-val="occupied" onclick="toggleLegendFilter(this)">
            <span class="dot" style="background:#2b6ff2"></span> Đang sử dụng (<span id="count-occupied">{{ $counts['occupied'] }}</span>)
        </div>
        <div class="legend-badge status-soon_to_checkout" data-status-val="soon_to_checkout" onclick="toggleLegendFilter(this)">
            <span class="dot" style="background:#dc3545"></span> Sắp trả (<span id="count-soon_to_checkout">{{ $counts['soon_to_checkout'] }}</span>)
        </div>
        <div class="legend-badge status-cleaning" data-status-val="cleaning" onclick="toggleLegendFilter(this)">
            <span class="dot" style="background:#f59e0b"></span> Đang dọn (<span id="count-cleaning">{{ $counts['cleaning'] }}</span>)
        </div>
    </div>

    <!-- Filter -->
    <div class="filter-card">
        <div class="filter-grid">
            <div class="filter-group">
                <span class="filter-label">Tầng</span>
                <select id="filter-floor" onchange="filterRooms()">
                    <option value="Tất cả">Tất cả tầng</option>
                    @foreach($allFloors as $fl)
                        <option value="{{ $fl }}">Tầng {{ $fl }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label">Loại phòng</span>
                <select id="filter-type" onchange="filterRooms()">
                    <option value="Tất cả">Tất cả loại phòng</option>
                    @foreach($uniqueTypes as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label">Sức chứa</span>
                <select id="filter-guests" onchange="filterRooms()">
                    <option value="Tất cả">Tất cả sức chứa</option>
                    @foreach($uniqueCapacities as $cap)
                        <option value="{{ $cap }}">{{ $cap }} người</option>
                    @endforeach
                    <option value="5+">5+ người</option>
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label">Trạng thái</span>
                <select id="filter-status" onchange="filterRooms()">
                    <option value="Tất cả">Tất cả trạng thái</option>
                    <option value="available">Đang trống</option>
                    <option value="soon_to_checkin">Sắp nhận</option>
                    <option value="occupied">Đang sử dụng</option>
                    <option value="soon_to_checkout">Sắp trả</option>
                    <option value="cleaning">Đang dọn</option>
                    <option value="maintenance">Bảo trì</option>
                </select>
            </div>
            <button class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center gap-1 py-2"
                    style="border-radius:8px;height:38px;" onclick="resetFilters()">
                <i class="fa-solid fa-rotate-right"></i> Làm mới
            </button>
        </div>
    </div>

    <!-- Room Grid -->
    <div id="room-grid-container">
        @foreach($floors as $floor => $roomList)
        <div class="floor-section mb-4" data-floor-num="{{ $floor }}">
            <h6 class="fw-bold text-dark mb-3 mt-2">
                <i class="fa-solid fa-layer-group text-primary me-2"></i> Tầng {{ $floor }}
            </h6>
            <div class="row g-3">
                @foreach($roomList as $room)
                @php
                    $icon = match($room->status) {
                        'soon_to_checkout' => 'fa-bell-concierge',
                        'soon_to_checkin'  => 'fa-calendar-day',
                        'occupied'         => 'fa-user-check',
                        'cleaning'         => 'fa-broom',
                        'maintenance'      => 'fa-wrench',
                        default            => 'fa-door-open',
                    };
                    $statusText = match($room->status) {
                        'soon_to_checkout' => 'Sắp trả',
                        'soon_to_checkin'  => 'Sắp nhận',
                        'occupied'         => 'Đang sử dụng',
                        'cleaning'         => 'Đang dọn',
                        'maintenance'      => 'Bảo trì',
                        default            => 'Đang trống',
                    };
                    $roomJson = json_encode([
                        'id'            => $room->id,
                        'room_number'   => $room->room_number,
                        'floor'         => $room->floor,
                        'status'        => $room->status,
                        'type_name'     => $room->roomType->name ?? '',
                        'room_type_id'  => $room->room_type_id,
                        'max_guests'    => $room->roomType->max_guests ?? 0,
                        'price'         => $room->roomType->price ?? 0,
                        'amenities_list'=> $room->amenities_list,
                    ]);
                @endphp
                <div class="col-md-4 col-sm-6 col-xl-2 room-card-wrapper">
                    <div class="room-card status-{{ $room->status }}"
                         data-floor="{{ $room->floor }}"
                         data-type="{{ $room->room_type_id }}"
                         data-guests="{{ $room->roomType->max_guests ?? 0 }}"
                         data-status="{{ $room->status }}"
                         data-search="{{ strtolower($room->room_number . ' ' . ($room->roomType->name ?? '')) }}"
                         id="room-card-{{ $room->id }}"
                         onclick="selectRoom(this, {{ $roomJson }})">
                        <i class="fa-solid {{ $icon }} room-icon"></i>
                        <div>
                            <h5 class="room-number">{{ $room->room_number }}</h5>
                            <p class="room-status-text">{{ $statusText }}</p>
                        </div>
                        <p class="room-capacity">{{ $room->roomType->max_guests ?? 0 }} người</p>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
    </div>
</main>

<!-- Right Panel: Detail -->
<aside class="right-panel shadow-sm" id="room-detail-panel" style="display:none;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold m-0" id="detail-title">Thông tin phòng</h5>
        <button type="button" class="btn-close" onclick="closeDetailPanel()"></button>
    </div>
    <img src="" class="detail-img" id="detail-img" alt="Room Image">
    <div class="detail-row">
        <div class="detail-label"><i class="fa-solid fa-bed text-muted"></i> Loại phòng</div>
        <div class="detail-value" id="detail-type">---</div>
    </div>
    <div class="detail-row">
        <div class="detail-label"><i class="fa-solid fa-users text-muted"></i> Sức chứa</div>
        <div class="detail-value" id="detail-capacity">---</div>
    </div>
    <div class="detail-row">
        <div class="detail-label"><i class="fa-solid fa-dollar-sign text-muted"></i> Giá phòng</div>
        <div class="detail-value text-primary" id="detail-price">---</div>
    </div>
    <div class="detail-row">
        <div class="detail-label"><i class="fa-solid fa-circle-info text-muted"></i> Trạng thái</div>
        <div class="detail-value" id="detail-status-badge"><span class="badge rounded-pill px-3">---</span></div>
    </div>
    <div class="detail-row mb-3">
        <div class="detail-label"><i class="fa-solid fa-wifi text-muted"></i> Tiện ích</div>
        <div class="detail-value" id="detail-amenities" style="font-size:0.825rem;color:#475569;">---</div>
    </div>

    <!-- Booking hiện tại -->
    <div id="booking-info" style="display:none;" class="mb-3">
        <hr class="my-2">
        <h6 class="fw-bold mb-2" style="font-size:0.85rem;text-transform:uppercase;">Booking hiện tại</h6>
        <div class="bg-light rounded p-3" style="font-size:0.85rem;">
            <div><strong>Khách:</strong> <span id="bk-customer"></span></div>
            <div><strong>Check-in:</strong> <span id="bk-checkin"></span></div>
            <div><strong>Check-out:</strong> <span id="bk-checkout"></span></div>
            <div><strong>Tổng tiền:</strong> <span id="bk-total" class="text-primary fw-bold"></span></div>
            <div><strong>Booking #:</strong> <span id="bk-id"></span></div>
        </div>
    </div>

    <hr class="my-3">
    <h6 class="fw-bold mb-3" style="font-size:0.9rem;text-transform:uppercase;">Nghiệp vụ phòng</h6>
    <div class="d-grid gap-2">
        <button class="btn btn-outline-success btn-action" id="btn-available" onclick="showCheckoutModal()">
            <i class="fa-solid fa-door-open"></i> Trả phòng
        </button>
        <button class="btn btn-outline-primary btn-action" id="btn-checkin" onclick="doCheckIn()">
            <i class="fa-solid fa-user-check"></i> Check-in (Đang sử dụng)
        </button>
        <button class="btn btn-outline-warning text-dark btn-action" id="btn-soon_to_checkin" onclick="updateRoomStatus('soon_to_checkin')">
            <i class="fa-solid fa-calendar-day"></i> Giữ chỗ phòng
        </button>
    </div>
</aside>

<!-- Right Panel: Empty -->
<div class="right-panel empty-panel-flex text-muted shadow-sm" id="empty-detail-panel">
    <div class="bg-light p-4 rounded-circle mb-3 d-flex align-items-center justify-content-center" style="width:80px;height:80px;">
        <i class="fa-solid fa-bed fs-1 text-secondary" style="opacity:0.5;"></i>
    </div>
    <h6 class="fw-bold text-dark">Chưa chọn phòng</h6>
    <p class="text-center px-4 text-muted" style="font-size:0.8rem;">Hãy chọn một phòng bất kỳ trên sơ đồ để xem thông tin chi tiết và thao tác nghiệp vụ.</p>
</div>

<!-- Modal Check-in Khách Vãng Lai -->
<div class="modal fade" id="walkinCheckinModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus me-2"></i>Check-in Khách Vãng Lai</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="walkinCheckinForm" onsubmit="submitWalkinCheckin(event)">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Số phòng</label>
                        <input type="text" class="form-control" id="wi-room-number" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Họ và tên khách hàng</label>
                        <input type="text" class="form-control" id="wi-name" required placeholder="Ví dụ: Nguyễn Văn A">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Email (Nhận link đánh giá phòng)</label>
                        <input type="email" class="form-control" id="wi-email" required placeholder="Ví dụ: customer@gmail.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Số điện thoại</label>
                        <input type="text" class="form-control" id="wi-phone" required placeholder="Ví dụ: 0987654321">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Người lớn</label>
                            <input type="number" class="form-control" id="wi-adults" min="1" value="1" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Trẻ em</label>
                            <input type="number" class="form-control" id="wi-children" min="0" value="0" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Ngày trả phòng dự kiến</label>
                        <input type="date" class="form-control" id="wi-checkout" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-check me-1"></i>Hoàn tất Check-in
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Checkout -->
<div class="modal fade" id="checkoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-receipt me-2"></i>Trả phòng & Thanh toán</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="bg-light rounded p-3 mb-3">
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Khách hàng</span><strong id="mo-customer"></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Phòng</span><strong id="mo-room"></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Check-in thực tế</span><strong id="mo-checkin"></strong></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Check-out</span><strong id="mo-checkout"></strong></div>
                    <hr>
                    <div class="d-flex justify-content-between mb-1"><span class="text-muted">Tổng tiền</span><strong class="text-primary fs-5" id="mo-total"></strong></div>
                    <div class="d-flex justify-content-between mb-1"><span class="text-muted">Đã đặt cọc</span><strong class="text-success" id="mo-paid"></strong></div>
                    <div class="d-flex justify-content-between"><span class="fw-bold">Còn lại</span><strong class="text-danger fs-5" id="mo-remaining"></strong></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Phương thức thanh toán</label>
                    @foreach([
                        ['cash',    '#28a745', 'Tiền mặt',           'Nhân viên thu trực tiếp'],
                        ['vietqr',  '#1565C0', 'Chuyển khoản VietQR','Quét mã QR · Hỗ trợ tất cả ngân hàng'],
                        ['momo',    '#ae2070', 'Ví MoMo',             'Thanh toán qua ứng dụng MoMo'],
                        ['zalopay', '#0068ff', 'ZaloPay',             'Thanh toán qua ví ZaloPay'],
                        ['vnpay',   '#e53935', 'VNPay',               'Thanh toán qua cổng VNPay'],
                    ] as [$val, $color, $name, $desc])
                    <label class="payment-option-staff d-flex align-items-center gap-3 p-3 mb-2 rounded"
                        style="border:2px solid #eee;cursor:pointer;transition:all .2s"
                        for="staff_method_{{ $val }}">
                        <input type="radio" name="staff_payment_method" id="staff_method_{{ $val }}"
                            value="{{ $val }}" onchange="selectStaffMethod(this)"
                            style="width:18px;height:18px;accent-color:{{ $color }}">
                        <div>
                            <div style="font-weight:700">{{ $name }}</div>
                            <div style="color:#888;font-size:12px">{{ $desc }}</div>
                        </div>
                    </label>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" onclick="doCheckOut()">
                    <i class="fa-solid fa-check me-1"></i>Xác nhận trả phòng
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080;">
    <div id="statusToast" class="toast align-items-center text-white border-0" role="alert" data-bs-delay="4000">
        <div class="d-flex">
            <div class="toast-body fw-500" id="toastMessage">Cập nhật thành công!</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
let selectedRoom = null;
let currentBooking = null;

const roomImages = {
    'Phòng Đơn Tiêu Chuẩn':  'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=600&q=80',
    'Phòng Đôi Tiêu Chuẩn':  'https://images.unsplash.com/photo-1631049552057-403cdb8f0658?w=600&q=80',
    'Phòng 3 Người (Triple)': 'https://images.unsplash.com/photo-1590490360182-c33d57733427?w=600&q=80',
    'Phòng Gia Đình':         'https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=600&q=80',
    'Phòng VIP Cao Cấp':      'https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=600&q=80',
};
const defaultImg = 'https://images.unsplash.com/photo-1618773928121-c32242e63f39?w=600&q=80';

function selectRoom(el, room) {
    selectedRoom = room;
    currentBooking = null;
    document.querySelectorAll('.room-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('empty-detail-panel').style.display = 'none';
    document.getElementById('room-detail-panel').style.display = 'flex';

    document.getElementById('detail-title').innerText = 'Phòng ' + room.room_number;
    document.getElementById('detail-type').innerText = room.type_name;
    document.getElementById('detail-capacity').innerText = room.max_guests + ' người';
    document.getElementById('detail-price').innerText = new Intl.NumberFormat('vi-VN').format(room.price) + ' đ / đêm';
    document.getElementById('detail-amenities').innerText = room.amenities_list || 'Không có';
    document.getElementById('detail-img').src = roomImages[room.type_name] || defaultImg;

    const badgeMap = {
        available:        ['bg-success',           'Đang trống'],
        soon_to_checkin:  ['bg-warning text-dark',  'Sắp nhận'],
        occupied:         ['bg-primary',            'Đang sử dụng'],
        checked_in:       ['bg-primary',            'Đang sử dụng'],  // ← thêm
        soon_to_checkout: ['bg-danger',             'Sắp trả'],
        cleaning:         ['bg-warning text-dark',  'Đang dọn'],
        maintenance:      ['bg-secondary',          'Bảo trì'],
    };
    const [cls, txt] = badgeMap[room.status] || ['bg-secondary', room.status];
    document.getElementById('detail-status-badge').innerHTML = `<span class="badge ${cls} rounded-pill px-3">${txt}</span>`;

    updateActionButtons(room.status);

    // Fetch booking nếu đang occupied
    if (room.status === 'occupied' || room.status === 'soon_to_checkout' || room.status === 'checked_in') {
        fetch(`/staff/room/${room.id}/current-booking`, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (data.booking) {
                currentBooking = data.booking;
                document.getElementById('bk-customer').innerText = data.booking.customer_name;
                document.getElementById('bk-checkin').innerText  = data.booking.actual_check_in || data.booking.check_in;
                document.getElementById('bk-checkout').innerText = data.booking.check_out;
                document.getElementById('bk-total').innerText    = new Intl.NumberFormat('vi-VN').format(data.booking.total_price) + ' đ';
                document.getElementById('bk-id').innerText       = '#' + data.booking.id;
                document.getElementById('booking-info').style.display = 'block';
            }
        }).catch(() => {});
    } else {
        document.getElementById('booking-info').style.display = 'none';
    }
}

function updateActionButtons(status) {
    document.getElementById('btn-available').disabled       = false; // Mở tạm nút Trả phòng để demo
    document.getElementById('btn-checkin').disabled         = false; // Mở tạm nút Check-in để demo
    document.getElementById('btn-soon_to_checkin').disabled = ['soon_to_checkin', 'occupied', 'checked_in', 'cleaning'].includes(status);
}

function closeDetailPanel() {
    document.getElementById('room-detail-panel').style.display = 'none';
    document.getElementById('empty-detail-panel').style.display = 'flex';
    document.querySelectorAll('.room-card').forEach(c => c.classList.remove('selected'));
    selectedRoom = null;
    currentBooking = null;
}

function doCheckIn() {
    if (!selectedRoom) return;

    // Nếu phòng trống, đây là khách vãng lai, mở form thông tin
    if (selectedRoom.status === 'available' || selectedRoom.status === 'cleaning' || selectedRoom.status === 'maintenance') {
        document.getElementById('wi-room-number').value = 'Phòng ' + selectedRoom.room_number;
        new bootstrap.Modal(document.getElementById('walkinCheckinModal')).show();
        return;
    }

    if (!confirm(`Check-in phòng ${selectedRoom.room_number}?`)) return;
    fetch(`/staff/room/${selectedRoom.id}/checkin`, {
        credentials: 'same-origin',
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            updateCardUI(selectedRoom.id, 'occupied');
            const oldStatus = selectedRoom.status;
            selectedRoom.status = 'occupied';
            updateActionButtons('occupied');
            updateStatusCounts(oldStatus, 'occupied');
            
            // Tải lại chi tiết phòng để nạp booking hiện tại lên bảng điều khiển bên phải
            selectRoom(document.getElementById(`room-card-${selectedRoom.id}`), selectedRoom);

            showToast(data.message, 'bg-success');
        } else { showToast(data.message, 'bg-danger'); }
    });
}

function showCheckoutModal() {
    if (!selectedRoom) return;
    if (!currentBooking) {
        if (confirm('Không tìm thấy thông tin đặt phòng hoạt động cho phòng này. Bạn có muốn chuyển trạng thái phòng này sang "Đang dọn dẹp" (Cleaning) không?')) {
            updateRoomStatus('cleaning');
        }
        return;
    }
    const paid      = currentBooking.deposit_amount ?? 0;
    const remaining = currentBooking.total_price - paid;
    document.getElementById('mo-customer').innerText = currentBooking.customer_name;
    document.getElementById('mo-room').innerText     = 'Phòng ' + selectedRoom.room_number;
    document.getElementById('mo-checkin').innerText  = currentBooking.actual_check_in || currentBooking.check_in;
    document.getElementById('mo-checkout').innerText = new Date().toLocaleDateString('vi-VN');
    document.getElementById('mo-total').innerText    = new Intl.NumberFormat('vi-VN').format(currentBooking.total_price) + ' đ';
    document.getElementById('mo-paid').innerText     = new Intl.NumberFormat('vi-VN').format(paid) + ' đ';
    document.getElementById('mo-remaining').innerText= new Intl.NumberFormat('vi-VN').format(remaining) + ' đ';
    new bootstrap.Modal(document.getElementById('checkoutModal')).show();
}

function doCheckOut() {
    if (!currentBooking) return;
    const selected = document.querySelector('input[name="staff_payment_method"]:checked');
    if (!selected) { showToast('Vui lòng chọn phương thức thanh toán.', 'bg-warning'); return; }

    fetch(`/staff/bookings/${currentBooking.id}/checkout-payment`, {
        credentials: 'same-origin',
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ payment_method: selected.value })
    })
    .then(r => r.json())
    .then(data => {
        bootstrap.Modal.getInstance(document.getElementById('checkoutModal')).hide();
        if (data.success) {
            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            updateCardUI(selectedRoom.id, 'cleaning');
            updateStatusCounts(selectedRoom.status, 'cleaning');
            selectedRoom.status = 'cleaning';
            updateActionButtons('cleaning');
            document.getElementById('booking-info').style.display = 'none';
            showToast(data.message, 'bg-success');
        } else { showToast(data.message, 'bg-danger'); }
    });
}
function updateRoomStatus(newStatus) {
    if (!selectedRoom) return;
    fetch(`/staff/room/${selectedRoom.id}/status`, {
        credentials: 'same-origin',
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ status: newStatus })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            updateStatusCounts(selectedRoom.status, newStatus);
            updateCardUI(selectedRoom.id, newStatus);
            selectedRoom.status = newStatus;
            updateActionButtons(newStatus);
            filterRooms();
            showToast(data.message, 'bg-success');
        } else { showToast(data.message, 'bg-danger'); }
    });
}

function updateCardUI(roomId, newStatus) {
    const card = document.getElementById(`room-card-${roomId}`);
    if (!card) return;
    card.className = card.className.replace(/status-\S+/, `status-${newStatus}`);
    card.setAttribute('data-status', newStatus);
    const statusMap = {
        available:        ['fa-door-open',     'Đang trống'],
        soon_to_checkin:  ['fa-calendar-day',  'Sắp nhận'],
        occupied:         ['fa-user-check',    'Đang sử dụng'],
        soon_to_checkout: ['fa-bell-concierge','Sắp trả'],
        cleaning:         ['fa-broom',         'Đang dọn'],
        maintenance:      ['fa-wrench',        'Bảo trì'],
    };
    const [icon, text] = statusMap[newStatus] || ['fa-door-open', newStatus];
    card.querySelector('.room-icon').className = `fa-solid ${icon} room-icon`;
    card.querySelector('.room-status-text').innerText = text;

    const badgeMap = {
        available:        ['bg-success',          'Đang trống'],
        soon_to_checkin:  ['bg-warning text-dark', 'Sắp nhận'],
        occupied:         ['bg-primary',           'Đang sử dụng'],
        soon_to_checkout: ['bg-danger',            'Sắp trả'],
        cleaning:         ['bg-warning text-dark', 'Đang dọn'],
        maintenance:      ['bg-secondary',         'Bảo trì'],
    };
    const [cls, txt] = badgeMap[newStatus] || ['bg-secondary', newStatus];
    document.getElementById('detail-status-badge').innerHTML = `<span class="badge ${cls} rounded-pill px-3">${txt}</span>`;
}

function updateStatusCounts(oldStatus, newStatus) {
    const elOld = document.getElementById(`count-${oldStatus}`);
    const elNew = document.getElementById(`count-${newStatus}`);
    if (elOld) { let v = parseInt(elOld.innerText); if (v > 0) elOld.innerText = v - 1; }
    if (elNew) { let v = parseInt(elNew.innerText); elNew.innerText = v + 1; }
}

function filterRooms() {
    const search  = document.getElementById('search-input').value.toLowerCase();
    const floor   = document.getElementById('filter-floor').value;
    const type    = document.getElementById('filter-type').value;
    const guests  = document.getElementById('filter-guests').value;
    const status  = document.getElementById('filter-status').value;

    document.querySelectorAll('.room-card-wrapper').forEach(wrapper => {
        const card = wrapper.querySelector('.room-card');
        let show = true;
        if (search && !card.dataset.search.includes(search)) show = false;
        if (floor  !== 'Tất cả' && card.dataset.floor  !== floor)  show = false;
        if (type   !== 'Tất cả' && card.dataset.type   !== type)   show = false;
        if (status !== 'Tất cả' && card.dataset.status !== status) show = false;
        if (guests !== 'Tất cả') {
            const g = parseInt(card.dataset.guests);
            if (guests === '5+') { if (g < 5) show = false; }
            else { if (g !== parseInt(guests)) show = false; }
        }
        wrapper.style.display = show ? 'block' : 'none';
    });

    document.querySelectorAll('.floor-section').forEach(section => {
        const visible = section.querySelectorAll('.room-card-wrapper[style="display: block;"]');
        section.style.display = visible.length === 0 ? 'none' : 'block';
    });
}

function toggleLegendFilter(el) {
    const val = el.dataset.statusVal;
    const select = document.getElementById('filter-status');
    if (el.classList.contains('active-filter')) {
        el.classList.remove('active-filter');
        select.value = 'Tất cả';
    } else {
        document.querySelectorAll('.legend-badge').forEach(b => b.classList.remove('active-filter'));
        el.classList.add('active-filter');
        select.value = val;
    }
    filterRooms();
}

function resetFilters() {
    document.getElementById('search-input').value = '';
    document.getElementById('filter-floor').value  = 'Tất cả';
    document.getElementById('filter-type').value   = 'Tất cả';
    document.getElementById('filter-guests').value = 'Tất cả';
    document.getElementById('filter-status').value = 'Tất cả';
    document.querySelectorAll('.legend-badge').forEach(b => b.classList.remove('active-filter'));
    filterRooms();
}

function showToast(message, bgClass = 'bg-success') {
    const toastEl = document.getElementById('statusToast');
    const toastMsg = document.getElementById('toastMessage');
    toastEl.className = `toast align-items-center text-white border-0 ${bgClass}`;
    toastMsg.innerText = message;
    const toast = bootstrap.Toast.getOrCreateInstance(toastEl);
    toast.show();
}
function selectStaffMethod(radio) {
    document.querySelectorAll('.payment-option-staff').forEach(el => {
        el.style.borderColor = '#eee';
        el.style.background  = '#fff';
    });
    radio.closest('label').style.borderColor = '#212529';
    radio.closest('label').style.background  = '#f8f9fa';
}

function submitWalkinCheckin(event) {
    event.preventDefault();
    if (!selectedRoom) return;

    const payload = {
        is_walkin: true,
        customer_name: document.getElementById('wi-name').value,
        customer_email: document.getElementById('wi-email').value,
        customer_phone: document.getElementById('wi-phone').value,
        adult_count: document.getElementById('wi-adults').value,
        child_count: document.getElementById('wi-children').value,
        check_out: document.getElementById('wi-checkout').value,
    };

    fetch(`/staff/room/${selectedRoom.id}/checkin`, {
        credentials: 'same-origin',
        method: 'POST',
        headers: { 
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest' 
        },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        bootstrap.Modal.getInstance(document.getElementById('walkinCheckinModal')).hide();
        if (data.success) {
            updateCardUI(selectedRoom.id, 'occupied');
            const oldStatus = selectedRoom.status;
            selectedRoom.status = 'occupied';
            updateActionButtons('occupied');
            updateStatusCounts(oldStatus, 'occupied');
            
            // Reload panel
            selectRoom(document.getElementById(`room-card-${selectedRoom.id}`), selectedRoom);
            
            showToast(data.message, 'bg-success');
        } else { showToast(data.message, 'bg-danger'); }
    });
}

window.addEventListener('DOMContentLoaded', resetFilters);
</script>
@endsection