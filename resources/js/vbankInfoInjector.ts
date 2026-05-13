const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__kginicisVbankInfoInjectorInstalled';
const MP_SECTION_ID = 'kginicis-mp-vbank-info';

const ORDER_COMPLETE_RE = /^\/shop\/orders\/([^/]+)\/complete$/;
const ORDER_SHOW_RE = /^\/mypage\/orders\/([^/]+)$/;

// ISO 8601 날짜 패턴 (2026-06-11T23:59:59+00:00 등)
const ISO_DATE_RE = /\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}/;

interface Payment {
    pg_provider?: string;
    payment_status?: string;
    payment_method?: string;
    vbank_name?: string | null;
    vbank_number?: string | null;
    vbank_holder?: string | null;
    vbank_due_at?: string | null;
    [key: string]: unknown;
}

interface OrderData {
    order_number?: string;
    total_due_amount?: number;
    total_due_amount_formatted?: string;
    payment?: Payment;
}

function formatKoreanDate(isoString: string): string {
    try {
        const date = new Date(isoString);
        return date.toLocaleString('ko-KR', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            timeZone: 'Asia/Seoul',
        });
    } catch {
        return isoString;
    }
}

// ──────────────────────────────────────────────
// 주문완료 페이지: ISO 날짜 텍스트를 한국어로 포맷
// ──────────────────────────────────────────────
function fixOrderCompleteDates(): void {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const toFix: { node: Text; formatted: string }[] = [];

    let node = walker.nextNode();
    while (node) {
        const text = (node as Text).textContent ?? '';
        const match = text.match(ISO_DATE_RE);
        if (match) {
            toFix.push({ node: node as Text, formatted: text.replace(match[0], formatKoreanDate(match[0])) });
        }
        node = walker.nextNode();
    }

    for (const { node: n, formatted } of toFix) {
        n.textContent = formatted;
    }
}

// ──────────────────────────────────────────────
// 마이페이지: G7Core 상태에서 vbank 정보 주입
// ──────────────────────────────────────────────
function getOrderFromState(orderNumber: string): OrderData | null {
    try {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const g7 = (window as any).G7Core as Record<string, unknown> | undefined;
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
        el => el.textContent?.includes('결제 정보'),
    );
    if (!h3) return null;
    const panelDiv = h3.parentElement?.parentElement;
    if (!panelDiv) return null;
    return Array.from(panelDiv.children).find(el => el.className?.includes('space-y')) ?? panelDiv;
}

function buildInfoRow(label: string, value: string, highlight = false): HTMLElement {
    const row = document.createElement('div');
    row.className = 'flex items-center justify-between';

    const labelEl = document.createElement('span');
    labelEl.className = 'text-gray-500 dark:text-gray-400 text-sm';
    labelEl.textContent = label;

    const valueEl = document.createElement('span');
    valueEl.className = highlight
        ? 'text-sm font-medium text-blue-600 dark:text-blue-400'
        : 'text-sm text-gray-900 dark:text-gray-100';
    valueEl.textContent = value;

    row.appendChild(labelEl);
    row.appendChild(valueEl);
    return row;
}

function buildVbankSection(payment: Payment, dueDateStr: string): HTMLElement {
    const section = document.createElement('div');
    section.id = MP_SECTION_ID;
    section.className = 'mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 space-y-2';

    const title = document.createElement('p');
    title.className = 'text-sm font-semibold text-gray-700 dark:text-gray-300';
    title.textContent = '가상계좌 입금 안내';
    section.appendChild(title);

    if (payment.vbank_name) section.appendChild(buildInfoRow('은행', payment.vbank_name));
    if (payment.vbank_number) section.appendChild(buildInfoRow('계좌번호', payment.vbank_number));
    if (payment.vbank_holder) section.appendChild(buildInfoRow('예금주', payment.vbank_holder));
    if (dueDateStr) section.appendChild(buildInfoRow('입금 기한', dueDateStr, true));

    return section;
}

async function tryInjectMypage(orderNumber: string): Promise<boolean> {
    const orderData = getOrderFromState(orderNumber);
    if (!orderData) return false;

    const { payment } = orderData;
    if (!payment) return true;
    if (payment.pg_provider !== 'kginicis') return true;
    if (payment.payment_status !== 'waiting_deposit') return true;
    if (!payment.vbank_number) return true;

    if (document.getElementById(MP_SECTION_ID)) return true;

    const container = findPaymentContainer();
    if (!container) return false;

    const dueDateStr = payment.vbank_due_at ? formatKoreanDate(payment.vbank_due_at as string) : '';
    container.appendChild(buildVbankSection(payment, dueDateStr));

    console.info(`[${PLUGIN_ID}] vbank info injected on mypage`);
    return true;
}

function startMypagePolling(orderNumber: string): void {
    let attempts = 0;
    const id = setInterval(() => {
        attempts++;
        void tryInjectMypage(orderNumber).then(done => {
            if (done || attempts >= 30) clearInterval(id);
        });
    }, 400);
}

function onRouteChange(): void {
    const oc = location.pathname.match(ORDER_COMPLETE_RE);
    if (oc) {
        // 주문완료 페이지: ISO 날짜 텍스트 포맷
        setTimeout(fixOrderCompleteDates, 500);
        return;
    }

    const mp = location.pathname.match(ORDER_SHOW_RE);
    if (mp) {
        startMypagePolling(mp[1]);
    }
}

export function installVbankInfoInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as unknown as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] vbank info injector installed`);

    const schedule = (delay = 800) => setTimeout(onRouteChange, delay);

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
