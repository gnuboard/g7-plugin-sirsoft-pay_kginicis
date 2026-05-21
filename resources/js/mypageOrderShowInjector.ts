const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__kginicisOrderShowInjectorInstalled';
const ROW_ID = 'kginicis-mp-receipt-row';

const ORDER_SHOW_RE = /^\/mypage\/orders\/([^/]+)$/;

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

interface KginicisOrderPaymentInfo {
    receipt_url?: string | null;
    payment_method_display_label?: string | null;
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

function getToken(): string | null {
    return localStorage.getItem('auth_token');
}

async function fetchOrderPaymentInfo(orderNumber: string): Promise<KginicisOrderPaymentInfo | null> {
    const token = getToken();
    if (!token) return null;
    try {
        const res = await fetch(`/api/plugins/${PLUGIN_ID}/user/orders/${orderNumber}/receipt`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        });
        if (!res.ok) return null;
        return (await res.json()) as KginicisOrderPaymentInfo;
    } catch {
        return null;
    }
}

async function fetchReceiptUrl(orderNumber: string): Promise<string | null> {
    const data = await fetchOrderPaymentInfo(orderNumber);
    return data?.receipt_url ?? null;
}

function findPaymentContainer(): Element | null {
    const panel = document.getElementById('order_payment_info_panel');
    if (panel) {
        return Array.from(panel.children).find(el => el.className?.includes('space-y')) ?? panel;
    }

    const h3 = Array.from(document.querySelectorAll<HTMLElement>('h3')).find(
        el => el.textContent?.includes('결제 정보'),
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
        if (label !== '결제 방법' && label !== '결제수단') continue;

        const value = spans[spans.length - 1];
        if (value.textContent?.trim() === displayLabel) {
            return true;
        }

        value.textContent = displayLabel;
        value.dataset.kginicisPaymentMethodPatched = 'true';
        return true;
    }

    return false;
}

function buildReceiptRow(orderNumber: string): HTMLElement {
    const row = document.createElement('div');
    row.id = ROW_ID;
    row.className = 'flex items-center justify-between';

    const label = document.createElement('span');
    label.className = 'text-gray-500 dark:text-gray-400 text-sm';
    label.textContent = '영수증';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className =
        'inline-flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-50';
    btn.textContent = '영수증 조회';

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = '로딩 중...';
        const url = await fetchReceiptUrl(orderNumber);
        btn.disabled = false;
        btn.textContent = '영수증 조회';
        if (url) {
            window.open(url, 'kginicis_receipt', 'width=800,height=600,scrollbars=yes,resizable=yes');
        }
    });

    row.appendChild(label);
    row.appendChild(btn);
    return row;
}

async function tryInject(orderNumber: string): Promise<boolean> {
    const orderData = getOrderFromState(orderNumber);
    if (!orderData) return false;

    const { payment } = orderData;
    if (!payment || payment.pg_provider !== 'kginicis') return true;
    if (payment.payment_status !== 'paid') return true;
    if (!payment.transaction_id) return true;

    const container = findPaymentContainer();
    if (!container) return false;

    const paymentInfo = await fetchOrderPaymentInfo(orderNumber);
    const patched = patchMypagePaymentMethodDisplay(
        container,
        paymentInfo?.payment_method_display_label,
    );

    if (!document.getElementById(ROW_ID) && paymentInfo?.receipt_url) {
        container.appendChild(buildReceiptRow(orderNumber));
        console.info(`[${PLUGIN_ID}] receipt button injected on mypage order show`);
    }

    if (patched) {
        console.info(`[${PLUGIN_ID}] payment method display patched on mypage order show`);
    }

    return Boolean(document.getElementById(ROW_ID) || patched);
}

function startPolling(orderNumber: string): void {
    let attempts = 0;
    const id = setInterval(() => {
        attempts++;
        void tryInject(orderNumber).then(done => {
            if (done || attempts >= 30) clearInterval(id);
        });
    }, 400);
}

function onRouteChange(): void {
    const match = location.pathname.match(ORDER_SHOW_RE);
    if (match) startPolling(match[1]);
}

export function installMypageOrderShowInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as unknown as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] mypage order show injector installed`);

    const schedule = (delay = 1500) => setTimeout(onRouteChange, delay);

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
    window.addEventListener('popstate', () => schedule(500));
}
