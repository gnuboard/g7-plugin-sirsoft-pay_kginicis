import {
    clearMobilePaymentReturnPending,
    hasMobilePaymentReturnPending,
    markStandardPaySdkForReload,
    removeKoreanPaymentForms,
} from './paymentDomCleanup';

const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';
const CLOSE_MESSAGE_SOURCE = PLUGIN_IDENTIFIER;
const CLOSE_MESSAGE_TYPE = 'payment-window-closed';
const LISTENER_INSTALLED_KEY = '__sirsoftKginicisPaymentCloseListenerInstalled';
const RESET_RETRY_LIMIT = 20;
const RESET_RETRY_INTERVAL_MS = 100;

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

interface PaymentCloseMessage {
    source?: string;
    type?: string;
    reason?: string;
}

function isPaymentCloseMessage(data: unknown): data is PaymentCloseMessage {
    if (!data || typeof data !== 'object') {
        return false;
    }

    const message = data as PaymentCloseMessage;
    return message.source === CLOSE_MESSAGE_SOURCE && message.type === CLOSE_MESSAGE_TYPE;
}

function isCheckoutPage(): boolean {
    return /\/shop\/checkout\/?$/.test(window.location.pathname);
}

export function resetCheckoutSubmittingState(
    reason = 'payment-window-closed',
    warnOnMissingCore = true,
): boolean {
    if (!isCheckoutPage()) {
        return false;
    }

    const g7Core = (window as any).G7Core;
    const setLocal = g7Core?.state?.setLocal;

    if (typeof setLocal !== 'function') {
        if (warnOnMissingCore) {
            logger.warn('G7Core.state.setLocal not available while resetting payment submit state');
        }
        return false;
    }

    removeKoreanPaymentForms();
    markStandardPaySdkForReload();
    setLocal({ isSubmittingOrder: false });
    logger.info('checkout submit state reset after KG payment close', { reason });
    return true;
}

function scheduleCheckoutSubmittingStateReset(reason: string, clearPendingOnSuccess = false): void {
    let attempts = 0;

    const tryReset = (): void => {
        attempts++;

        if (clearPendingOnSuccess && !hasMobilePaymentReturnPending()) {
            return;
        }

        if (resetCheckoutSubmittingState(reason, attempts >= RESET_RETRY_LIMIT)) {
            if (clearPendingOnSuccess) {
                clearMobilePaymentReturnPending();
            }
            return;
        }

        if (!isCheckoutPage() || attempts >= RESET_RETRY_LIMIT) {
            return;
        }

        window.setTimeout(tryReset, RESET_RETRY_INTERVAL_MS);
    };

    tryReset();
}

function resetAfterMobilePaymentReturn(reason: string): void {
    if (!hasMobilePaymentReturnPending()) {
        return;
    }

    scheduleCheckoutSubmittingStateReset(reason, true);
}

export function installPaymentCloseMessageListener(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const w = window as unknown as Record<string, unknown>;
    if (w[LISTENER_INSTALLED_KEY]) {
        return;
    }

    window.addEventListener('message', (event: MessageEvent) => {
        if (event.origin !== window.location.origin) {
            return;
        }

        if (!isPaymentCloseMessage(event.data)) {
            return;
        }

        resetCheckoutSubmittingState(event.data.reason);
    });

    window.addEventListener('pageshow', (event: PageTransitionEvent) => {
        resetAfterMobilePaymentReturn(event.persisted
            ? 'mobile-payment-bfcache-return'
            : 'mobile-payment-page-show');
    });

    window.addEventListener('focus', () => {
        resetAfterMobilePaymentReturn('mobile-payment-window-focus');
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        resetAfterMobilePaymentReturn('mobile-payment-visibility-return');
    });

    w[LISTENER_INSTALLED_KEY] = true;
    resetAfterMobilePaymentReturn('mobile-payment-listener-installed');
}
