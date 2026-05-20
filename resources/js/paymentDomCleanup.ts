export const KOREAN_PAYMENT_FORM_ID_PREFIX = 'kginicis_pay_form_';
const RELOAD_STANDARD_PAY_SDK_KEY = '__sirsoftKginicisReloadStandardPaySdk';

export function removeKoreanPaymentForms(): number {
    if (typeof document === 'undefined') {
        return 0;
    }

    const forms = document.querySelectorAll<HTMLFormElement>(
        `form[id^="${KOREAN_PAYMENT_FORM_ID_PREFIX}"]`,
    );

    forms.forEach((form) => form.remove());

    return forms.length;
}

export function markStandardPaySdkForReload(): void {
    if (typeof window === 'undefined') {
        return;
    }

    (window as unknown as Record<string, unknown>)[RELOAD_STANDARD_PAY_SDK_KEY] = true;
}

export function consumeStandardPaySdkReloadFlag(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    const w = window as unknown as Record<string, unknown>;
    const shouldReload = w[RELOAD_STANDARD_PAY_SDK_KEY] === true;
    delete w[RELOAD_STANDARD_PAY_SDK_KEY];

    return shouldReload;
}

export function resetStandardPaySdk(sdkUrl: string): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    (window as any).INIStdPay = undefined;

    document
        .querySelectorAll<HTMLScriptElement>(
            `script[src="${sdkUrl}"], script[src*="/INIStdPay_third-party.js"]`,
        )
        .forEach((script) => script.remove());
}
