<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;

// ============================================================
// WEBHOOK — Nhận callback từ cổng thanh toán
// Không cần CSRF (đã exclude trong VerifyCsrfToken.php)
// ============================================================

// Webhooks have been moved to routes/web.php