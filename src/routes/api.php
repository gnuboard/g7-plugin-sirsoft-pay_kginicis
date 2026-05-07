<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminCashReceiptController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminEscrowDeliveryController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminEscrowDenyConfirmController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminTransactionController;
use Plugins\Sirsoft\PayKginicis\Controllers\CbtHashDataController;
use Plugins\Sirsoft\PayKginicis\Controllers\MobileSignatureController;
use Plugins\Sirsoft\PayKginicis\Controllers\PaymentSignatureController;
use Plugins\Sirsoft\PayKginicis\Controllers\UserReceiptController;

/*
|--------------------------------------------------------------------------
| KG Inicis Plugin API Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /api/plugins/sirsoft-pay_kginicis (PluginRouteServiceProvider 자동 적용)
| 미들웨어: api (PluginRouteServiceProvider 자동 적용)
|
*/

// 결제창 서명 생성 — 인증 불필요, 프론트엔드에서 직접 호출
Route::post('/payment/signature', [PaymentSignatureController::class, 'generate'])
    ->name('payment.signature');

// CBT 해시 데이터 생성 — 인증 불필요, 프론트엔드에서 직접 호출
Route::post('/payment/cbt/hash-data', [CbtHashDataController::class, 'generate'])
    ->name('payment.cbt.hash-data');

// 모바일 위변조 방지 해시(P_CHKFAKE) 생성 — 인증 불필요, 프론트엔드에서 직접 호출
Route::post('/payment/mobile/signature', [MobileSignatureController::class, 'generate'])
    ->name('payment.mobile.signature');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user/orders/{orderNumber}/receipt', [UserReceiptController::class, 'show'])
        ->name('user.orders.receipt');
});

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 가상계좌 입금통보 URL 조회 (관리자 설정 페이지 표시용)
    Route::get('/vbank-notify-url', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'url' => url('/plugins/sirsoft-pay_kginicis/payment/vbank-notify'),
                'mobile_url' => url('/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify'),
                'escrow_url' => url('/plugins/sirsoft-pay_kginicis/payment/escrow-notify'),
            ],
        ]);
    })->name('vbank.notify.url');

    // 거래 조회 — TID 직접 조회
    Route::post('/transaction/query', [AdminTransactionController::class, 'query'])
        ->name('transaction.query');

    // 주문번호로 거래 상태 조회 (레이아웃 확장 자동 로드용)
    Route::get('/orders/{orderNumber}/transaction-status', [AdminTransactionController::class, 'queryByOrder'])
        ->name('orders.transaction-status');

    // 현금영수증 별도발행
    Route::post('/orders/{orderNumber}/cash-receipt', [AdminCashReceiptController::class, 'issue'])
        ->name('orders.cash-receipt.issue');

    // 에스크로 배송등록
    Route::get('/orders/{orderNumber}/escrow-delivery', [AdminEscrowDeliveryController::class, 'formData'])
        ->name('orders.escrow-delivery.form');
    Route::post('/orders/{orderNumber}/escrow-delivery', [AdminEscrowDeliveryController::class, 'register'])
        ->name('orders.escrow-delivery.register');

    // 에스크로 구매거절확인
    Route::post('/orders/{orderNumber}/escrow-deny-confirm', [AdminEscrowDenyConfirmController::class, 'confirm'])
        ->name('orders.escrow-deny-confirm');
});
