import { markStandardPaySdkForReload, removeKoreanPaymentForms } from './paymentDomCleanup';

const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';
const CLOSE_MESSAGE_SOURCE = PLUGIN_IDENTIFIER;
const CLOSE_MESSAGE_TYPE = 'payment-window-closed';
const LISTENER_INSTALLED_KEY = '__sirsoftKginicisPaymentCloseListenerInstalled';

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

export function resetCheckoutSubmittingState(reason = 'payment-window-closed'): void {
    if (!isCheckoutPage()) {
        return;
    }

    const g7Core = (window as any).G7Core;
    const setLocal = g7Core?.state?.setLocal;

    if (typeof setLocal !== 'function') {
        logger.warn('G7Core.state.setLocal not available while resetting payment submit state');
        return;
    }

    removeKoreanPaymentForms();
    markStandardPaySdkForReload();
    setLocal({ isSubmittingOrder: false });
    logger.info('checkout submit state reset after KG payment close', { reason });
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

    w[LISTENER_INSTALLED_KEY] = true;
}
