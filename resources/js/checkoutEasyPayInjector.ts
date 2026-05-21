const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__kginicisCheckoutEasyPayInjectorInstalled';
const CONTAINER_ID = 'kginicis-checkout-easy-pay';
const LISTENER_FLAG = '__kginiClearListenerAttached';
const CHECKOUT_RE = /^\/shop\/checkout\/?$/;

const EASY_PAYS = [
    { key: 'SAMSUNG',  method: 'kginicis_samsung_pay', label: '삼성페이',   cls: 'bg-blue-500 text-white',        mobileOnly: true  },
    { key: 'LPAY',     method: 'kginicis_lpay',        label: 'L.pay',      cls: 'bg-red-600 text-white',         mobileOnly: false },
    { key: 'KAKAOPAY', method: 'kginicis_kakaopay',    label: '카카오페이',  cls: 'bg-yellow-400 text-gray-900',   mobileOnly: false },
] as const;

let cachedEnabledPays: string[] | null = null;

async function fetchEnabledPays(): Promise<string[]> {
    if (cachedEnabledPays !== null) return cachedEnabledPays;
    try {
        const token = localStorage.getItem('auth_token');
        const res = await fetch('/api/modules/sirsoft-ecommerce/payments/client-config/kginicis', {
            headers: { Authorization: token ? `Bearer ${token}` : '', Accept: 'application/json' },
        });
        if (!res.ok) {
            cachedEnabledPays = [];
            return [];
        }
        const json = (await res.json()) as { data?: { enabled_easy_pays?: string[] } };
        cachedEnabledPays = json.data?.enabled_easy_pays ?? [];
    } catch {
        cachedEnabledPays = [];
    }
    return cachedEnabledPays;
}

function findPaymentContainer(): Element | null {
    const h2 = Array.from(document.querySelectorAll<HTMLElement>('h2')).find(
        el => el.textContent?.includes('결제'),
    );
    if (!h2) return null;

    let el: Element | null = h2.parentElement;
    while (el && el !== document.body) {
        if (el.tagName === 'DIV' && el.className?.includes('rounded-lg') && el.className?.includes('border')) {
            return el;
        }
        el = el.parentElement;
    }
    return null;
}

function updateButtonStyles(selectedMethod: string | null): void {
    const container = document.getElementById(CONTAINER_ID);
    if (!container) return;
    container.querySelectorAll<HTMLButtonElement>('button[data-kginicis-method]').forEach(btn => {
        btn.style.boxShadow =
            btn.dataset.kginicisMethod === selectedMethod
                ? '0 0 0 2px #ffffff, 0 0 0 5px rgba(0,0,0,0.55)'
                : '';
    });
}

function setEasyPayMethod(method: string): void {
    const g7 = (window as unknown as Record<string, unknown>).G7Core as Record<string, unknown> | undefined;
    (g7?.state as Record<string, unknown> | undefined)?.setLocal?.({
        paymentMethod: method,
        serverPaymentMethod: 'card',
    });
    updateButtonStyles(method);
}

function attachClearListener(payContainer: Element): void {
    const el = payContainer as Element & Record<string, unknown>;
    if (el[LISTENER_FLAG]) return;
    el[LISTENER_FLAG] = true;
    payContainer.addEventListener('click', e => {
        const target = e.target as Element;
        const easySection = document.getElementById(CONTAINER_ID);
        if (easySection && !easySection.contains(target)) {
            updateButtonStyles(null);
        }
    });
}

function isMobileUA(): boolean {
    if (typeof navigator === 'undefined') return false;
    return /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
}

function buildEasyPaySection(enabledPays: string[]): HTMLElement | null {
    const btns: HTMLElement[] = [];
    const mobile = isMobileUA();

    for (const { key, method, label, cls, mobileOnly } of EASY_PAYS) {
        if (!enabledPays.includes(key)) continue;
        if (mobileOnly && !mobile) continue;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.dataset.kginicisMethod = method;
        btn.className = `px-4 py-2 rounded-lg text-sm font-bold ${cls} hover:opacity-90`;
        btn.textContent = label;
        btn.addEventListener('click', () => setEasyPayMethod(method));
        btns.push(btn);
    }

    if (btns.length === 0) return null;

    const wrap = document.createElement('div');
    wrap.id = CONTAINER_ID;
    wrap.className = 'mt-4 pt-4 mb-4 border-t border-gray-200 dark:border-gray-700';

    const title = document.createElement('p');
    title.className = 'text-sm font-medium text-gray-700 dark:text-gray-300 mb-3';
    title.textContent = 'KG이니시스 간편결제';

    const btnWrap = document.createElement('div');
    btnWrap.className = 'kginicis-easy-pay-btns flex flex-wrap gap-2';
    btns.forEach(b => btnWrap.appendChild(b));

    wrap.appendChild(title);
    wrap.appendChild(btnWrap);
    return wrap;
}

let isInjecting = false;
let pollingId: ReturnType<typeof setInterval> | null = null;

async function tryInject(): Promise<boolean> {
    if (document.getElementById(CONTAINER_ID)) return true;
    if (isInjecting) return false;

    const payContainer = findPaymentContainer();
    if (!payContainer) return false;

    isInjecting = true;
    try {
        const enabledPays = await fetchEnabledPays();

        if (document.getElementById(CONTAINER_ID)) return true;
        if (enabledPays.length === 0) return true;

        const section = buildEasyPaySection(enabledPays);
        if (!section) return true;

        payContainer.appendChild(section);
        attachClearListener(payContainer);
        console.info(`[${PLUGIN_ID}] checkout easy pay injected`);
        return true;
    } finally {
        isInjecting = false;
    }
}

function startPolling(): void {
    if (pollingId !== null) {
        clearInterval(pollingId);
        pollingId = null;
    }
    cachedEnabledPays = null;
    void fetchEnabledPays();

    let attempts = 0;
    pollingId = setInterval(() => {
        attempts++;
        void tryInject().then(done => {
            if (done || attempts >= 50) {
                if (pollingId !== null) clearInterval(pollingId);
                pollingId = null;
            }
        });
    }, 200);
}

function onRouteChange(): void {
    if (CHECKOUT_RE.test(location.pathname)) startPolling();
}

export function installCheckoutEasyPayInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as unknown as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] checkout easy pay injector installed`);

    if (CHECKOUT_RE.test(location.pathname)) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => startPolling());
        } else {
            startPolling();
        }
    }

    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        setTimeout(onRouteChange, 200);
    };
    window.addEventListener('popstate', () => setTimeout(onRouteChange, 200));
}
