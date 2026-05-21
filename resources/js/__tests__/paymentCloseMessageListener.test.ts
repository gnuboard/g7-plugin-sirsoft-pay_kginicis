import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    clearMobilePaymentReturnPending,
    consumeStandardPaySdkReloadFlag,
    markMobilePaymentReturnPending,
} from '../paymentDomCleanup';
import { installPaymentCloseMessageListener, resetCheckoutSubmittingState } from '../paymentCloseMessageListener';

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

describe('paymentCloseMessageListener', () => {
    const setLocal = vi.fn();

    beforeEach(() => {
        window.history.pushState({}, '', '/shop/checkout');
        windowRecord().G7Core = {
            state: {
                setLocal,
            },
        };
        setLocal.mockClear();
        vi.spyOn(console, 'info').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        delete windowRecord().G7Core;
        delete windowRecord().__sirsoftKginicisPaymentCloseListenerInstalled;
        clearMobilePaymentReturnPending();
        vi.restoreAllMocks();
    });

    it('KG closeUrl 메시지를 받으면 체크아웃 제출 상태를 해제한다', () => {
        const staleForm = document.createElement('form');
        staleForm.id = 'kginicis_pay_form_stale';
        document.body.appendChild(staleForm);

        installPaymentCloseMessageListener();

        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: {
                source: 'sirsoft-pay_kginicis',
                type: 'payment-window-closed',
                reason: 'inicis-close-url',
            },
        }));

        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
        expect(document.getElementById('kginicis_pay_form_stale')).toBeNull();
        expect(consumeStandardPaySdkReloadFlag()).toBe(true);
    });

    it('다른 origin 메시지는 무시한다', () => {
        installPaymentCloseMessageListener();

        window.dispatchEvent(new MessageEvent('message', {
            origin: 'https://example.com',
            data: {
                source: 'sirsoft-pay_kginicis',
                type: 'payment-window-closed',
            },
        }));

        expect(setLocal).not.toHaveBeenCalled();
    });

    it('체크아웃 페이지가 아니면 상태를 변경하지 않는다', () => {
        window.history.pushState({}, '', '/shop/cart');

        resetCheckoutSubmittingState();

        expect(setLocal).not.toHaveBeenCalled();
    });

    it('모바일 결제 페이지에서 브라우저 뒤로 돌아오면 체크아웃 제출 상태를 해제한다', () => {
        markMobilePaymentReturnPending();
        installPaymentCloseMessageListener();

        window.dispatchEvent(new Event('pageshow'));

        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
    });

    it('모바일 결제 복귀 표시가 이미 있으면 listener 설치 시점에도 제출 상태를 해제한다', () => {
        markMobilePaymentReturnPending();

        installPaymentCloseMessageListener();

        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
    });

    it('모바일 결제 복귀 표시가 없으면 pageshow 에서 상태를 건드리지 않는다', () => {
        installPaymentCloseMessageListener();

        window.dispatchEvent(new Event('pageshow'));

        expect(setLocal).not.toHaveBeenCalled();
    });

    it('모바일 결제 복귀를 여러 번 반복해도 매번 표시를 소비하고 상태를 해제한다', () => {
        installPaymentCloseMessageListener();

        markMobilePaymentReturnPending();
        window.dispatchEvent(new Event('pageshow'));
        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });

        setLocal.mockClear();

        markMobilePaymentReturnPending();
        window.dispatchEvent(new Event('pageshow'));
        expect(setLocal).toHaveBeenCalledWith({ isSubmittingOrder: false });
    });
});
