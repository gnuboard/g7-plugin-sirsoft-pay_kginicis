<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayKginicis\Controllers\CbtCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\EscrowNotifyController;
use Plugins\Sirsoft\PayKginicis\Controllers\MobileCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\PaymentCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\UserEscrowConfirmController;
use Plugins\Sirsoft\PayKginicis\Http\Middleware\InicisNotifyIpWhitelist;

Route::get('/payment/cbt/callback', [CbtCallbackController::class, 'handle'])
    ->name('payment.cbt.callback');

// 에스크로 구매결정: 사용자 인증 필요
Route::get('/payment/escrow-confirm/{orderNumber}', [UserEscrowConfirmController::class, 'show'])
    ->middleware('auth')
    ->name('payment.escrow-confirm.show');

// 팝업 닫기 (KG 이니시스 closeUrl — 인증 불필요)
Route::get('/payment/escrow-confirm/close', [UserEscrowConfirmController::class, 'close'])
    ->name('payment.escrow-confirm.close');

Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->group(function () {
    Route::post('/payment/callback', [PaymentCallbackController::class, 'authCallback'])
        ->name('payment.callback');

    // KG 이니시스 공식 발송 IP 만 허용 (위변조/재처리 방어)
    Route::post('/payment/vbank-notify', [PaymentCallbackController::class, 'vbankNotify'])
        ->middleware(InicisNotifyIpWhitelist::class)
        ->name('payment.vbank-notify');

    Route::post('/payment/mobile/vbank-notify', [PaymentCallbackController::class, 'mobileVbankNotify'])
        ->middleware(InicisNotifyIpWhitelist::class)
        ->name('payment.mobile.vbank-notify');

    Route::post('/payment/escrow-notify', [EscrowNotifyController::class, 'handle'])
        ->middleware(InicisNotifyIpWhitelist::class)
        ->name('payment.escrow-notify');

    // 모바일: KG 이니시스가 인증 후 GET 리다이렉트로 P_NEXT_URL 호출
    Route::get('/payment/mobile/callback', [MobileCallbackController::class, 'handle'])
        ->name('payment.mobile.callback');

    // 에스크로 구매결정 결과 수신 (KG 이니시스 → 사용자 브라우저 POST)
    Route::post('/payment/escrow-confirm/pc/return', [UserEscrowConfirmController::class, 'pcReturn'])
        ->name('payment.escrow-confirm.pc-return');

    Route::post('/payment/escrow-confirm/mobile/return', [UserEscrowConfirmController::class, 'mobileReturn'])
        ->name('payment.escrow-confirm.mobile-return');
});
