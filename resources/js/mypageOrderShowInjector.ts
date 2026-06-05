import {
    canOpenKginicisReceipt,
    fetchKginicisReceiptInfo,
    KginicisReceiptInfo,
    openKginicisReceipt,
    receiptButtonLabel,
    receiptRowLabel,
} from './receiptPopup';

const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__kginicisOrderShowInjectorInstalled';
const ROW_ID = 'kginicis-mp-receipt-row';

// 회원 마이페이지(/mypage/orders/{N}) + 비회원 주문 상세(/shop/guest/orders/{N}) 두 URL 매칭.
// 두 페이지가 동일 _payment.json partial 을 공유하므로 DOM 구조도 동일 — 같은 injector 가 양쪽 처리.
const ORDER_SHOW_RE = /^(?:\/mypage\/orders\/([^/]+)|\/shop\/guest\/orders\/([^/]+))$/;

const activePolls = new Map<string, number>();

interface Payment {
    pg_provider?: string;
    payment_status?: string;
    payment_method?: string;
    transaction_id?: string | null;
    [key: string]: unknown;
}

interface OrderData {
    order_number?: string;
    total_amount_formatted?: string;
    payment?: Payment;
}

function getOrderFromState(orderNumber: string): OrderData | null {
    try {
        const g7 = (window as Record<string, unknown>).G7Core as Record<string, unknown> | undefined;
        const getState = g7?.getState as (() => Record<string, unknown>) | undefined;
        const ctx = getState?.()?.currentDataContext as Record<string, unknown> | undefined;
        const order = ctx?.order as { data?: OrderData } | undefined;
        const data = order?.data;
        if (!data || data.order_number !== orderNumber) return null;
        return data;
    } catch {
        return null;
    }
}

function findPaymentContainer(): Element | null {
    const panel = document.getElementById('order_payment_info_panel');
    if (panel) {
        return Array.from(panel.children).find(el => el.className?.includes('space-y')) ?? panel;
    }

    const h3 = Array.from(document.querySelectorAll<HTMLElement>('h3')).find(
        el => {
            const text = el.textContent?.trim() ?? '';
            return text.includes('결제 정보') || /^Payment Information$/i.test(text);
        },
    );
    if (!h3) return null;

    const panelDiv = h3.parentElement?.parentElement;
    if (!panelDiv) return null;

    return Array.from(panelDiv.children).find(el => el.className?.includes('space-y')) ?? panelDiv;
}

export function patchMypagePaymentMethodDisplay(container: Element, displayLabel: string | null | undefined): boolean {
    if (!displayLabel) return false;

    const rows = Array.from(container.querySelectorAll<HTMLElement>('div'));
    for (const row of rows) {
        const spans = Array.from(row.children).filter(
            (child): child is HTMLElement => child instanceof HTMLElement && child.tagName === 'SPAN',
        );
        if (spans.length < 2) continue;

        const label = spans[0].textContent?.trim();
        if (label !== '결제 방법' && label !== '결제수단' && label !== 'Payment Method') continue;

        const value = spans[spans.length - 1];
        if (value.textContent?.trim() === displayLabel) {
            row.dataset.kginicisPaymentMethodRow = 'true';
            return true;
        }

        value.textContent = displayLabel;
        value.dataset.kginicisPaymentMethodPatched = 'true';
        row.dataset.kginicisPaymentMethodRow = 'true';
        return true;
    }

    return false;
}

function buildReceiptRow(orderNumber: string, receiptInfo: KginicisReceiptInfo): HTMLElement {
    const row = document.createElement('div');
    row.id = ROW_ID;
    row.className = 'flex items-center justify-between';
    row.dataset.kginicisReceiptRow = 'mypage-order';

    const label = document.createElement('span');
    label.className = 'text-gray-500 dark:text-gray-400 text-sm';
    label.textContent = receiptRowLabel(receiptInfo);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.dataset.kginicisReceiptButton = 'mypage-order';
    btn.className =
        'inline-flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-50';
    btn.textContent = receiptButtonLabel(receiptInfo);

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = '로딩 중...';
        const latestReceiptInfo = await fetchKginicisReceiptInfo(orderNumber);
        btn.disabled = false;
        btn.textContent = receiptButtonLabel(latestReceiptInfo ?? receiptInfo);
        if (canOpenKginicisReceipt(latestReceiptInfo)) {
            openKginicisReceipt(latestReceiptInfo);
        }
    });

    row.appendChild(label);
    row.appendChild(btn);
    return row;
}

async function tryInject(orderNumber: string): Promise<boolean> {
    const orderData = getOrderFromState(orderNumber);
    const payment = orderData?.payment;
    if (payment) {
        if (payment.pg_provider !== 'kginicis') return true;
        if (!payment.transaction_id) return true;
    }

    const container = findPaymentContainer();
    if (!container) return false;

    const paymentInfo = await fetchKginicisReceiptInfo(orderNumber);
    const isPaid = payment?.payment_status === 'paid';
    const isCbtConfirmation = paymentInfo?.receipt_type === 'cbt_confirmation';
    if (payment && !isPaid && !isCbtConfirmation) return true;
    if (!payment && !canOpenKginicisReceipt(paymentInfo)) return false;

    const patched = patchMypagePaymentMethodDisplay(
        container,
        paymentInfo?.payment_method_display_label,
    );

    if (!document.getElementById(ROW_ID) && canOpenKginicisReceipt(paymentInfo)) {
        container.appendChild(buildReceiptRow(orderNumber, paymentInfo));
        console.info(`[${PLUGIN_ID}] receipt button injected on mypage order show`);
    }

    if (patched) {
        console.info(`[${PLUGIN_ID}] payment method display patched on mypage order show`);
    }

    return Boolean(document.getElementById(ROW_ID) || patched);
}

function startPolling(orderNumber: string): void {
    if (activePolls.has(orderNumber)) return;

    let attempts = 0;
    const id = window.setInterval(() => {
        attempts++;
        void tryInject(orderNumber).then(done => {
            if (done || attempts >= 30) {
                window.clearInterval(id);
                activePolls.delete(orderNumber);
            }
        });
    }, 400);
    activePolls.set(orderNumber, id);
}

function onRouteChange(): void {
    const match = location.pathname.match(ORDER_SHOW_RE);
    if (match) {
        // 회원 그룹(match[1]) 또는 비회원 그룹(match[2]) 중 한 쪽이 채워진다.
        const orderNumber = match[1] ?? match[2];
        if (orderNumber) startPolling(orderNumber);
    }
}

export function installMypageOrderShowInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as unknown as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] mypage order show injector installed`);

    let pendingRouteCheck: number | null = null;
    const schedule = (delay = 1500) => {
        if (pendingRouteCheck !== null) {
            window.clearTimeout(pendingRouteCheck);
        }
        pendingRouteCheck = window.setTimeout(() => {
            pendingRouteCheck = null;
            onRouteChange();
        }, delay);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => schedule());
    } else {
        schedule();
    }

    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        schedule(600);
    };
    const origReplace = history.replaceState.bind(history);
    history.replaceState = (...args: Parameters<typeof history.replaceState>) => {
        origReplace(...args);
        schedule(600);
    };
    window.addEventListener('popstate', () => schedule(500));

    const observeTarget = document.getElementById('app') ?? document.body;
    const observer = new MutationObserver(() => {
        if (ORDER_SHOW_RE.test(location.pathname)) schedule(250);
    });
    observer.observe(observeTarget, { childList: true, subtree: true });
}
