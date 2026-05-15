/* eslint-disable @typescript-eslint/no-explicit-any */

interface PgPaymentData {
    order_number: string;
    order_name: string;
    amount: number;
    currency?: string;
    customer_name?: string;
    customer_email?: string;
    customer_phone?: string;
}

interface RequestPaymentParams {
    pgPaymentData: PgPaymentData;
    paymentMethod?: string;
}

interface TemplateLocalState {
    paymentMethod?: string;
}

interface ClientConfig {
    mid: string;
    sdk_url: string;
    callback_urls: {
        signature: string;
        callback: string;
        cbt_hash_data: string;
        cbt_callback: string;
        cbt_auth_url: string;
        mobile_signature: string;
        mobile_callback: string;
        mobile_vbank_notify: string;
    };
    japan_enabled: boolean;
    use_escrow: boolean;
    japan_mid: string;
    enabled_easy_pays: string[];
    use_credit_point: boolean;
}

interface SignatureResponse {
    signature: string;
    verification: string;
    mKey: string;
}

interface MobileSignatureResponse {
    chkfake: string;
    mobile_payment_url: string;
}

interface CbtHashDataResponse {
    hash_data: string;
}

declare global {
    interface Window {
        INIStdPay: any;
        __templateApp?: {
            globalState?: {
                _local?: TemplateLocalState;
            };
        };
    }
}

function isMobileUserAgent(): boolean {
    return /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
}

function loadScript(src: string): Promise<void> {
    return new Promise((resolve, reject) => {
        if (document.querySelector(`script[src="${src}"]`)) {
            resolve();
            return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
        document.head.appendChild(script);
    });
}

function submitForm(action: string, fields: Record<string, string>, charset = 'utf-8'): void {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = action;
    form.acceptCharset = charset;

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
}

// PC 결제수단 → INIStdPay gopaymethod 매핑
const GOPAYMETHOD_MAP: Record<string, string> = {
    card:               'Card',
    vbank:              'VBank',
    bank:               'DirectBank',
    phone:              'HPP',
    kginicis_lpay:      'LPAY',
    kginicis_kakaopay:  'KAKAOPAY',
};

// 모바일 결제수단 → P_INI_PAYMENT 매핑 (manual.inicis.com/pay/stdpay_m.html#popup_7)
// 휴대폰결제 코드는 PC 의 'HPP' 가 아닌 'MOBILE' — 잘못된 P_INI_PAYMENT 응답 회귀 차단.
const MOBILE_PAYMETHOD_MAP: Record<string, string> = {
    card:                 'CARD',
    vbank:                'VBANK',
    bank:                 'BANK',
    phone:                'MOBILE',
    kginicis_samsung_pay: 'SAMSUNG',
    kginicis_lpay:        'LPAY',
    kginicis_kakaopay:    'KAKAOPAY',
};

/**
 * KG 이니시스 한국 모바일 결제
 *
 * 페이지 이동 방식: https://mobile.inicis.com/smart/payment/ 로 폼 제출
 * → KG 이니시스 인증 → P_NEXT_URL(서버)로 GET 리다이렉트 → 서버 승인
 */
async function requestMobileKoreanPayment(
    G7Core: any,
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
    paymentMethod: string,
): Promise<void> {
    const timestamp = String(Math.floor(Date.now()));

    const sigJson: { data: MobileSignatureResponse } = await G7Core.api.post(
        config.callback_urls.mobile_signature,
        { oid: pgPaymentData.order_number, price: pgPaymentData.amount, timestamp },
    );

    const { chkfake, mobile_payment_url: mobilePaymentUrl } = sigJson.data;

    // 메뉴얼(STEP 2) 표준 응답에는 P_OID 가 없음 — 주문번호를 쿼리스트링으로 echo 받아 회수.
    const nextUrl =
        window.location.origin +
        config.callback_urls.mobile_callback +
        '?orderId=' + encodeURIComponent(pgPaymentData.order_number);

    const iniPayment = MOBILE_PAYMETHOD_MAP[paymentMethod] ?? 'CARD';

    // 휴대폰결제(MOBILE) 는 P_HPP_METHOD 필수 — '1'=콘텐츠 / '2'=실물상품
    // (manual.inicis.com/pay/stdpay_m.html). 누락 시 PG 가 MX1006 으로 반려.
    const fields: Record<string, string> = {
        P_INI_PAYMENT: iniPayment,
        P_MID:         config.mid,
        P_OID:         pgPaymentData.order_number,
        P_AMT:         String(pgPaymentData.amount),
        P_GOODS:       pgPaymentData.order_name,
        P_UNAME:       pgPaymentData.customer_name ?? '',
        P_MOBILE:      pgPaymentData.customer_phone ?? '',
        P_EMAIL:       pgPaymentData.customer_email ?? '',
        P_NEXT_URL:    nextUrl,
        P_CHARSET:     'utf8',
        P_TIMESTAMP:   timestamp,
        P_CHKFAKE:     chkfake,
        // centerCd=Y: 취소 버튼 활성화 / amt_hash=Y: 금액 위변조 검증 활성화 / useescrow=Y: 에스크로 결제
        P_RESERVED:    config.use_escrow
            ? 'below1000=Y&vbank_receipt=Y&useescrow=Y&centerCd=Y&amt_hash=Y'
            : 'below1000=Y&vbank_receipt=Y&centerCd=Y&amt_hash=Y',
    };

    if (iniPayment === 'MOBILE') {
        fields.P_HPP_METHOD = '2';
    }

    // 가상계좌 결제 시 P_NOTI_URL 필수 (manual.inicis.com/pay/stdpay_m.html).
    // PC 가상계좌는 KG 이니시스 가맹점 어드민의 등록 URL 로 통보되지만, 모바일은
    // 요청에 P_NOTI_URL 을 명시해야 입금통보를 받을 수 있다.
    if (iniPayment === 'VBANK') {
        fields.P_NOTI_URL =
            window.location.origin + config.callback_urls.mobile_vbank_notify;
    }

    submitForm(mobilePaymentUrl, fields, 'euc-kr');
}

/**
 * KG 이니시스 한국 표준결제 (INIStdPay 팝업, PC 전용)
 */
async function requestKoreanPayment(
    G7Core: any,
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
    paymentMethod: string,
): Promise<void> {
    const timestamp = String(Math.floor(Date.now()));

    const signatureJson: { data: SignatureResponse } = await G7Core.api.post(
        config.callback_urls.signature,
        { oid: pgPaymentData.order_number, price: pgPaymentData.amount, timestamp },
    );

    const { signature, verification, mKey } = signatureJson.data;

    if (!window.INIStdPay) {
        await loadScript(config.sdk_url);
    }

    if (!window.INIStdPay) {
        await new Promise<void>((resolve) => setTimeout(resolve, 100));
    }

    if (!window.INIStdPay) {
        throw new Error('INIStdPay SDK not available');
    }

    const callbackUrl = window.location.origin + config.callback_urls.callback;
    const shopBase = (window as any).G7Core?.state?.get?.('templateSettings')?.shopBase ?? '/shop';
    const orderCloseUrl = window.location.origin + shopBase + '/checkout';
    const formId = 'kginicis_pay_form_' + Date.now();

    const form = document.createElement('form');
    form.id = formId;
    form.method = 'POST';
    form.acceptCharset = 'euc-kr';

    const fields: Record<string, string> = {
        version:      '1.0',
        mid:          config.mid,
        oid:          pgPaymentData.order_number,
        goodname:     pgPaymentData.order_name,
        price:        String(pgPaymentData.amount),
        currency:     pgPaymentData.currency === 'KRW' ? 'WON' : (pgPaymentData.currency ?? 'WON'),
        buyername:    pgPaymentData.customer_name ?? '',
        buyeremail:   pgPaymentData.customer_email ?? '',
        buyertel:     pgPaymentData.customer_phone ?? '',
        timestamp,
        signature,
        verification,
        mKey,
        returnUrl:    callbackUrl,
        closeUrl:     orderCloseUrl,
        gopaymethod:  GOPAYMETHOD_MAP[paymentMethod] ?? 'Card',
        acceptmethod: (() => {
            const escrow = config.use_escrow ? 'useescrow:' : '';
            const creditPoint = config.use_credit_point ? 'CREDITCARD(Y):' : '';
            return paymentMethod === 'phone'
                ? `HPP(1):${escrow}${creditPoint}centerCd(Y)`
                : `${escrow}${creditPoint}centerCd(Y)`;
        })(),
        payViewType:  'overlay',
        use_chkfake:  'Y',
        charset:      'UTF-8',
    };

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);

    window.INIStdPay.pay(formId);
}

/**
 * KG 이니시스 CBT (일본 엔 결제) 처리
 *
 * 페이지 전환 방식: /cbtauth 로 POST 폼 전송 → KG 이니시스 인증 → returnUrl 로 리다이렉트 → 서버 승인
 */
/**
 * KG 이니시스 CBT 가 요구하는 timestamp 형식: yyyyMMddHHmmss (14 bytes)
 * 매뉴얼: https://manual.inicis.com/jppay/cbtauth.html
 */
function formatCbtTimestamp(date: Date = new Date()): string {
    const pad = (n: number): string => String(n).padStart(2, '0');
    return (
        date.getFullYear().toString() +
        pad(date.getMonth() + 1) +
        pad(date.getDate()) +
        pad(date.getHours()) +
        pad(date.getMinutes()) +
        pad(date.getSeconds())
    );
}

async function requestCbtPayment(
    G7Core: any,
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
): Promise<void> {
    const japanMid = config.japan_mid;
    const timestamp = formatCbtTimestamp();

    const hashResponse: { data: CbtHashDataResponse } = await G7Core.api.post(
        config.callback_urls.cbt_hash_data,
        { oid: pgPaymentData.order_number, price: pgPaymentData.amount, timestamp },
    );

    const { hash_data: hashData } = hashResponse.data;

    const returnUrl =
        window.location.origin +
        config.callback_urls.cbt_callback +
        `?oid=${encodeURIComponent(pgPaymentData.order_number)}&amount=${pgPaymentData.amount}`;

    submitForm(config.callback_urls.cbt_auth_url, {
        cbtType:     'JPPG',
        mid:         japanMid,
        timestamp,
        returnUrl,
        buyerName:   pgPaymentData.customer_name ?? '',
        buyerTel:    pgPaymentData.customer_phone ?? '',
        buyerEmail:  pgPaymentData.customer_email ?? '',
        goodName:    pgPaymentData.order_name,
        amount:      String(pgPaymentData.amount),
        orderId:     pgPaymentData.order_number,
        hashData,
        extraData:   JSON.stringify({}),
    });
}

/**
 * KG 이니시스 결제창 호출 핸들러
 *
 * 결제 흐름:
 *   - JPY (japan_enabled): CBT 페이지 전환 결제
 *   - KRW + 모바일 UA: 모바일 결제 (페이지 이동)
 *   - KRW + PC: INIStdPay 팝업 (표준결제)
 */
export async function requestPaymentHandler(action: any, _context?: any): Promise<void> {
    const { pgPaymentData, paymentMethod: paramPaymentMethod } = (action.params || {}) as RequestPaymentParams;

    if (!pgPaymentData) {
        return;
    }

    const localState = window.__templateApp?.globalState?._local;
    const paymentMethod = paramPaymentMethod ?? localState?.paymentMethod ?? 'card';

    const G7Core = (window as any).G7Core;

    try {
        const configJson = await G7Core.api.get('/modules/sirsoft-ecommerce/payments/client-config/kginicis');

        if (!configJson.data) {
            throw new Error('Failed to fetch KG Inicis client config');
        }

        const config: ClientConfig = configJson.data;
        const currency = pgPaymentData.currency ?? 'WON';
        const isJapan = currency === 'JPY' && config.japan_enabled && !!config.japan_mid;

        if (isJapan) {
            await requestCbtPayment(G7Core, config, pgPaymentData);
        } else if (isMobileUserAgent()) {
            await requestMobileKoreanPayment(G7Core, config, pgPaymentData, paymentMethod);
        } else {
            await requestKoreanPayment(G7Core, config, pgPaymentData, paymentMethod);
        }

    } catch (error: unknown) {
        const errorMessage = error instanceof Error ? error.message : 'Unknown error';
        G7Core?.state?.setLocal?.({ paymentErrorMessage: errorMessage, isSubmittingOrder: false, paymentMethod });
        G7Core?.modal?.open?.('kginicis_payment_error_modal');
    }
}
