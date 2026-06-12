const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__sirsoftKginicisCheckoutNaverpayBrandButtonInstalled';
const CHECKOUT_RE = /^\/shop\/checkout\/?$/;
const CLIENT_CONFIG_PATH = '/api/modules/sirsoft-ecommerce/payments/client-config/kginicis';

let observer: MutationObserver | null = null;
let cachedEnabled: Promise<boolean> | null = null;
let retryTimer: number | null = null;

interface ClientConfigBody {
    data?: {
        easy_pay_naverpay_brand_button?: boolean;
    };
}

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_ID}]`, ...args),
};

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

function isCheckoutPage(): boolean {
    return CHECKOUT_RE.test(window.location.pathname);
}

function isKoreanPage(): boolean {
    const lang = document.documentElement.lang || navigator.language || '';
    return lang.toLowerCase().startsWith('ko');
}

function titleText(): string {
    return isKoreanPage()
        ? '네이버페이로 결제 (kg이니시스)'
        : 'Pay with Naver Pay (KG Inicis)';
}

function headingText(): string {
    return isKoreanPage()
        ? '네이버페이'
        : 'Naver Pay';
}

function descriptionText(): string {
    return isKoreanPage()
        ? '네이버페이로 결제 (kg이니시스)'
        : 'Pay with Naver Pay (KG Inicis)';
}

async function fetchEnabled(fetchImpl: typeof fetch): Promise<boolean> {
    if (cachedEnabled !== null) return cachedEnabled;

    cachedEnabled = (async () => {
        try {
            const response = await fetchImpl(CLIENT_CONFIG_PATH, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) return false;

            const body = (await response.json()) as ClientConfigBody;
            return body.data?.easy_pay_naverpay_brand_button === true;
        } catch {
            return false;
        }
    })();

    return cachedEnabled;
}

function isNaverpayButton(button: HTMLButtonElement): boolean {
    if (button.dataset.kginicisNaverpayBrandButton === 'true') return true;

    const text = (button.textContent ?? '').replace(/\s+/g, ' ').trim();

    return text.includes('네이버페이 (KG이니시스)')
        || (text.includes('네이버페이') && text.includes('KG이니시스'))
        || text.includes('Naver Pay (KG Inicis)');
}

function formatNaverpayText(button: HTMLButtonElement): void {
    const paragraphs = Array.from(button.querySelectorAll<HTMLParagraphElement>('p'));
    const heading = paragraphs.find((element) => {
        const text = (element.textContent ?? '').replace(/\s+/g, ' ').trim();

        return text.includes('네이버페이')
            || text.includes('Naver Pay');
    });

    if (!heading) return;

    const headingLabel = headingText();
    if (heading.textContent !== headingLabel) {
        heading.textContent = headingLabel;
    }
    if (heading.dataset.kginicisNaverpayHeading !== headingLabel) {
        heading.dataset.kginicisNaverpayHeading = headingLabel;
    }
    if (heading.getAttribute('aria-label') !== headingLabel) {
        heading.setAttribute('aria-label', headingLabel);
    }

    const description = paragraphs[paragraphs.indexOf(heading) + 1]
        ?? paragraphs.find((element) => element !== heading);
    if (!description) return;

    const descriptionLabel = descriptionText();
    if (description.textContent !== descriptionLabel) {
        description.textContent = descriptionLabel;
    }
    if (description.dataset.kginicisNaverpayDescription !== descriptionLabel) {
        description.dataset.kginicisNaverpayDescription = descriptionLabel;
    }
}

function applyCompactNaverpayLayout(button: HTMLButtonElement): void {
    button.style.paddingLeft = '12px';
    button.style.paddingRight = '12px';
    button.style.boxSizing = 'border-box';
    button.style.minWidth = '0';

    const row = button.querySelector<HTMLElement>('.flex.items-center.gap-2, .flex.items-center.gap-3')
        ?? button.querySelector<HTMLElement>('.flex.items-center');
    if (row) {
        row.style.gap = '8px';
        row.style.width = '100%';
        row.style.minWidth = '0';
        row.style.maxWidth = '188px';
        row.style.boxSizing = 'border-box';
    }

    const heading = Array.from(button.querySelectorAll<HTMLElement>('p')).find((element) => {
        const text = (element.textContent ?? '').replace(/\s+/g, ' ').trim();

        return text.includes('네이버페이')
            || text.includes('Naver Pay');
    });

    if (!heading) return;

    heading.style.whiteSpace = 'normal';
    heading.style.wordBreak = 'keep-all';
    heading.style.overflowWrap = 'anywhere';
    heading.style.removeProperty('font-size');
    heading.style.removeProperty('line-height');
    heading.style.maxWidth = '100%';

    const textWrapper = heading.parentElement;
    if (textWrapper instanceof HTMLElement) {
        textWrapper.style.flex = '1 1 0px';
        textWrapper.style.minWidth = '0';
        textWrapper.style.maxWidth = '100%';

        Array.from(textWrapper.querySelectorAll<HTMLElement>('p')).forEach((paragraph) => {
            paragraph.style.minWidth = '0';
            paragraph.style.maxWidth = '100%';
            paragraph.style.whiteSpace = 'normal';
            paragraph.style.wordBreak = 'keep-all';
            paragraph.style.overflowWrap = 'anywhere';
        });

        const description = Array.from(textWrapper.querySelectorAll<HTMLElement>('p')).find((paragraph) => {
            const text = (paragraph.textContent ?? '').replace(/\s+/g, ' ').trim();

            return text.includes('네이버페이로 결제')
                || text.includes('Pay with Naver Pay');
        });

        if (description) {
            description.style.fontSize = '12px';
            description.style.lineHeight = '1rem';
        }
    }
}

function createNaverpayMark(): HTMLSpanElement {
    const mark = document.createElement('span');
    mark.dataset.kginicisNaverpayMark = 'true';
    mark.setAttribute('aria-hidden', 'true');
    mark.style.display = 'inline-flex';
    mark.style.width = '32px';
    mark.style.height = '32px';
    mark.style.flex = '0 0 32px';
    mark.style.alignItems = 'center';
    mark.style.justifyContent = 'center';

    mark.innerHTML = [
        '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 40 40" role="img" aria-label="Naver Pay">',
        '<rect width="40" height="40" rx="8" fill="#03C75A"/>',
        '<text x="20" y="17" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="12" font-weight="700">N</text>',
        '<text x="20" y="29" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="9" font-weight="700">Pay</text>',
        '</svg>',
    ].join('');

    return mark;
}

function findPaymentIcon(button: HTMLButtonElement): Element | null {
    return button.querySelector('svg')
        ?? button.querySelector('i[class*="fa-"], i[role="img"], i');
}

export function patchRenderedNaverpayBrandButton(root: ParentNode = document): boolean {
    let patched = false;

    root.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
        if (!isNaverpayButton(button)) return;

        button.title = titleText();
        button.dataset.kginicisNaverpayBrandButton = 'true';
        formatNaverpayText(button);
        applyCompactNaverpayLayout(button);

        if (button.querySelector('[data-kginicis-naverpay-mark="true"]')) {
            return;
        }

        const icon = findPaymentIcon(button);
        if (!icon || !icon.parentElement) return;

        icon.replaceWith(createNaverpayMark());
        patched = true;
    });

    return patched;
}

function stopPatchRetries(): void {
    if (retryTimer === null) return;

    window.clearInterval(retryTimer);
    retryTimer = null;
}

function startPatchRetries(): void {
    stopPatchRetries();

    let attempts = 0;
    retryTimer = window.setInterval(() => {
        attempts += 1;
        patchRenderedNaverpayBrandButton();

        if (attempts >= 50) {
            stopPatchRetries();
        }
    }, 200);
}

async function startDomPatchLoop(fetchImpl: typeof fetch): Promise<void> {
    if (!isCheckoutPage()) return;
    if (!(await fetchEnabled(fetchImpl))) return;

    patchRenderedNaverpayBrandButton();
    startPatchRetries();

    if (observer === null) {
        observer = new MutationObserver(() => {
            patchRenderedNaverpayBrandButton();
        });
        observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    }
}

export function installCheckoutNaverpayBrandButton(fetchImpl: typeof fetch = fetch): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    if (windowRecord()[FLAG] === true) return;

    windowRecord()[FLAG] = true;

    void startDomPatchLoop(fetchImpl).then(() => {
        logger.info('checkout Naver Pay brand button patcher installed');
    });
}

export function resetCheckoutNaverpayBrandButtonForTests(): void {
    observer?.disconnect();
    observer = null;
    stopPatchRetries();
    cachedEnabled = null;
    delete windowRecord()[FLAG];
}
