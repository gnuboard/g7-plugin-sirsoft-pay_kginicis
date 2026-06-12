import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    installCheckoutNaverpayBrandButton,
    patchRenderedNaverpayBrandButton,
    resetCheckoutNaverpayBrandButtonForTests,
} from '../checkoutNaverpayBrandButton';

function renderPaymentButtons(): void {
    document.body.innerHTML = `
        <button type="button">
            <div class="flex items-center gap-2">
                <i class="fas fa-wallet" data-original-icon="true" role="img"></i>
                <div>
                    <p>네이버페이 (KG이니시스)</p>
                    <p>네이버페이로 결제</p>
                </div>
            </div>
        </button>
        <button type="button">
            <div class="flex items-center gap-3">
                <svg data-original-icon="true"></svg>
                <div>
                    <p>신용카드</p>
                    <p>카드로 결제</p>
                </div>
            </div>
        </button>
    `;
}

describe('checkoutNaverpayBrandButton', () => {
    beforeEach(() => {
        document.documentElement.lang = 'ko';
        window.history.pushState({}, '', '/shop/checkout');
        vi.spyOn(console, 'info').mockImplementation(() => {});
    });

    afterEach(() => {
        resetCheckoutNaverpayBrandButtonForTests();
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('네이버페이 버튼에 title 과 브랜드 마크를 적용한다', () => {
        renderPaymentButtons();

        expect(patchRenderedNaverpayBrandButton()).toBe(true);

        const naverpayButton = document.querySelector<HTMLButtonElement>('button[data-kginicis-naverpay-brand-button="true"]');
        expect(naverpayButton).not.toBeNull();
        expect(naverpayButton?.title).toBe('네이버페이로 결제 (kg이니시스)');
        expect(naverpayButton?.querySelector('[data-kginicis-naverpay-mark="true"]')).not.toBeNull();
        expect(naverpayButton?.querySelector('[data-original-icon="true"]')).toBeNull();
        const heading = naverpayButton?.querySelector<HTMLElement>('p');
        expect(heading?.getAttribute('aria-label')).toBe('네이버페이');
        expect(heading?.textContent).toBe('네이버페이');
        expect(heading?.querySelector('span')).toBeNull();
        expect(heading?.style.fontSize).toBe('');
        expect(heading?.style.whiteSpace).toBe('normal');
        expect(heading?.style.wordBreak).toBe('keep-all');
        expect(heading?.style.overflowWrap).toBe('anywhere');
        const description = naverpayButton?.querySelectorAll<HTMLElement>('p')[1];
        expect(description?.textContent).toBe('네이버페이로 결제 (kg이니시스)');
        expect(description?.style.fontSize).toBe('12px');
        expect(description?.style.lineHeight).toBe('1rem');
        const row = naverpayButton?.querySelector<HTMLElement>('.flex.items-center');
        expect(row?.style.width).toBe('100%');
        expect(row?.style.minWidth).toBe('0');
        expect(row?.style.maxWidth).toBe('188px');
        const textWrapper = heading?.parentElement;
        expect(textWrapper?.style.flex).toBe('1 1 0px');
        expect(textWrapper?.style.minWidth).toBe('0');
        expect(textWrapper?.style.maxWidth).toBe('100%');
        expect(naverpayButton?.querySelector<HTMLElement>('[data-kginicis-naverpay-mark="true"]')?.style.width).toBe('32px');

        const cardButton = Array.from(document.querySelectorAll<HTMLButtonElement>('button'))
            .find((button) => button.textContent?.includes('신용카드'));
        expect(cardButton?.querySelector('[data-original-icon="true"]')).not.toBeNull();
    });

    it('클라이언트 설정이 비활성화면 버튼을 건드리지 않는다', async () => {
        renderPaymentButtons();

        const fetchSpy = vi.fn(async () => new Response(JSON.stringify({
            data: { easy_pay_naverpay_brand_button: false },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } }));

        installCheckoutNaverpayBrandButton(fetchSpy as unknown as typeof fetch);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.querySelector('[data-kginicis-naverpay-brand-button="true"]')).toBeNull();
        expect(document.querySelector('[data-kginicis-naverpay-mark="true"]')).toBeNull();
    });

    it('클라이언트 설정이 활성화면 체크아웃에서 자동 적용한다', async () => {
        renderPaymentButtons();

        const fetchSpy = vi.fn(async () => new Response(JSON.stringify({
            data: { easy_pay_naverpay_brand_button: true },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } }));

        installCheckoutNaverpayBrandButton(fetchSpy as unknown as typeof fetch);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.querySelector('[data-kginicis-naverpay-brand-button="true"]')).not.toBeNull();
        expect(document.querySelector('[data-kginicis-naverpay-mark="true"]')).not.toBeNull();
    });

    it('결제수단 텍스트가 늦게 채워져도 자동 적용한다', async () => {
        document.body.innerHTML = `
            <button>
                <div class="flex items-center gap-3">
                    <i class="fas fa-wallet" data-original-icon="true" role="img"></i>
                    <div>
                        <p id="late-heading"></p>
                        <p id="late-description"></p>
                    </div>
                </div>
            </button>
        `;

        const heading = document.getElementById('late-heading');
        const description = document.getElementById('late-description');
        heading?.appendChild(document.createTextNode(''));
        description?.appendChild(document.createTextNode(''));

        const fetchSpy = vi.fn(async () => new Response(JSON.stringify({
            data: { easy_pay_naverpay_brand_button: true },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } }));

        installCheckoutNaverpayBrandButton(fetchSpy as unknown as typeof fetch);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.querySelector('[data-kginicis-naverpay-brand-button="true"]')).toBeNull();

        if (heading?.firstChild) heading.firstChild.nodeValue = '네이버페이 (KG이니시스)';
        if (description?.firstChild) description.firstChild.nodeValue = '네이버페이로 결제';
        await new Promise((resolve) => setTimeout(resolve, 0));

        const naverpayButton = document.querySelector<HTMLButtonElement>('button[data-kginicis-naverpay-brand-button="true"]');
        expect(naverpayButton).not.toBeNull();
        expect(naverpayButton?.title).toBe('네이버페이로 결제 (kg이니시스)');
        expect(naverpayButton?.querySelector('[data-kginicis-naverpay-mark="true"]')).not.toBeNull();
        expect(naverpayButton?.querySelector<HTMLElement>('p')?.textContent).toBe('네이버페이');
        expect(naverpayButton?.querySelectorAll<HTMLElement>('p')[1]?.textContent).toBe('네이버페이로 결제 (kg이니시스)');
    });
});
