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
        cbt_checkout_token: string;
        cbt_hash_data: string;
        cbt_callback: string;
        cbt_auth_url: string;
        mobile_signature: string;
        mobile_callback: string;
        mobile_vbank_notify: string;
    };
    japan_enabled: boolean;
    japan_configured?: boolean;
    use_escrow: boolean;
    japan_mid: string;
    cbt_extra_data?: CbtExtraData;
    enabled_easy_pays: string[];
    use_credit_point: boolean;
}

interface CbtExtraData {
    paymentUI?: Record<string, string>;
    payment?: {
        paymethod?: string[];
        isMobile?: string;
        card?: Record<string, unknown>;
    };
    gmoPayment?: Record<string, string>;
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

interface CbtCheckoutTokenResponse {
    checkout_token: string;
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

/**
 * PC 결제수단 → INIStdPay gopaymethod 매핑.
 *
 * 일반 결제수단은 자기 자신을, 간편결제 변종은 KG 이니시스 매뉴얼이 지정한
 * "only{프로바이더}" 표기를 사용한다 (LPAY 가 아니라 'onlylpay').
 *
 * gnuboard5 shop/inicis/lpay_order.script.php 참조:
 *   gopaymethod = (inicis_settle_case === 'inicis_kakaopay') ? 'onlykakaopay' : 'onlylpay'
 */
export const GOPAYMETHOD_MAP: Record<string, string> = {
    card:               'Card',
    vbank:              'VBank',
    bank:               'DirectBank',
    phone:              'HPP',
    kginicis_lpay:      'onlylpay',
    kginicis_kakaopay:  'onlykakaopay',
};

/**
 * 모바일 결제수단 → P_INI_PAYMENT 매핑 (manual.inicis.com/pay/stdpay_m.html#popup_7).
 *
 * 일반 결제수단만 P_INI_PAYMENT 로 분기 가능. 간편결제 (Samsung Pay / L.pay /
 * 카카오페이) 는 모바일에서 *완전히 다른 엔드포인트* 를 사용하며 P_INI_PAYMENT
 * 가 아닌 P_RESERVED 의 'd_samsungpay=Y' / 'd_lpay=Y' / 'd_kakaopay=Y' 힌트로
 * 식별된다. 따라서 본 맵은 일반 결제수단만 보유한다.
 *
 * 휴대폰결제 코드는 PC 의 'HPP' 가 아닌 'MOBILE' — 잘못된 P_INI_PAYMENT 응답 회귀 차단.
 */
export const MOBILE_PAYMETHOD_MAP: Record<string, string> = {
    card:  'CARD',
    vbank: 'VBANK',
    bank:  'BANK',
    phone: 'MOBILE',
};

/**
 * 모바일 간편결제 → P_RESERVED 에 추가할 hint 토큰 매핑.
 *
 * gnuboard5 mobile/shop/samsungpay/order.script.php 참조:
 *   d_samsungpay=Y / d_lpay=Y / d_kakaopay=Y
 *
 * 모바일 간편결제는 https://mobile.inicis.com/smart/wcard/ 로 폼 제출하며
 * P_INI_PAYMENT 를 보내지 않는다.
 */
export const MOBILE_EASY_PAY_RESERVED_HINT: Record<string, string> = {
    kginicis_samsung_pay: 'd_samsungpay=Y',
    kginicis_lpay:        'd_lpay=Y',
    kginicis_kakaopay:    'd_kakaopay=Y',
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

    const easyPayHint = MOBILE_EASY_PAY_RESERVED_HINT[paymentMethod];
    const isEasyPay = easyPayHint !== undefined;

    // 모바일 간편결제 (Samsung Pay / L.pay / 카카오페이) 는 별도 엔드포인트 사용.
    // gnuboard5 mobile/shop/samsungpay/order.script.php 참조:
    //   form.action = 'https://mobile.inicis.com/smart/wcard/'
    // 백엔드가 반환한 mobilePaymentUrl (/smart/payment/) 의 마지막 path segment 를
    // 'wcard' 로 치환하여 호스트/스킴은 보존하면서 엔드포인트만 전환한다.
    const submitUrl = isEasyPay
        ? mobilePaymentUrl.replace(/\/smart\/[^/]+\/?$/, '/smart/wcard/')
        : mobilePaymentUrl;

    const iniPayment = MOBILE_PAYMETHOD_MAP[paymentMethod] ?? 'CARD';

    // 간편결제는 P_RESERVED 에 hint 추가 + P_SKIP_TERMS=Y. 일반 결제는 기존 옵션 유지.
    const baseReserved = config.use_escrow
        ? 'below1000=Y&vbank_receipt=Y&useescrow=Y&centerCd=Y&amt_hash=Y'
        : 'below1000=Y&vbank_receipt=Y&centerCd=Y&amt_hash=Y';
    const reserved = isEasyPay
        ? baseReserved.replace('&useescrow=Y', '') + '&' + easyPayHint
        : baseReserved;

    // 휴대폰결제(MOBILE) 는 P_HPP_METHOD 필수 — '1'=콘텐츠 / '2'=실물상품
    // (manual.inicis.com/pay/stdpay_m.html). 누락 시 PG 가 MX1006 으로 반려.
    //
    // 간편결제는 P_INI_PAYMENT 를 보내지 않는다 — gnuboard5 samsungpay/orderform.1.php
    // 에는 P_INI_PAYMENT 필드 자체가 없으며 결제 종류는 P_RESERVED 의 d_*pay=Y
    // hint 와 form action (/smart/wcard/) 으로 식별된다.
    const fields: Record<string, string> = {
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
        P_RESERVED:    reserved,
    };

    if (! isEasyPay) {
        fields.P_INI_PAYMENT = iniPayment;
    } else {
        // 간편결제는 약관 동의 화면 skip (samsungpay/orderform.1.php 와 동일)
        fields.P_SKIP_TERMS = 'Y';
    }

    if (iniPayment === 'MOBILE' && ! isEasyPay) {
        fields.P_HPP_METHOD = '2';
    }

    // 가상계좌 결제 시 P_NOTI_URL 필수 (manual.inicis.com/pay/stdpay_m.html).
    // PC 가상계좌는 KG 이니시스 가맹점 어드민의 등록 URL 로 통보되지만, 모바일은
    // 요청에 P_NOTI_URL 을 명시해야 입금통보를 받을 수 있다.
    if (iniPayment === 'VBANK' && ! isEasyPay) {
        fields.P_NOTI_URL =
            window.location.origin + config.callback_urls.mobile_vbank_notify;
    }

    submitForm(submitUrl, fields, 'euc-kr');
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
    // closeUrl 비우기 — KG 이니시스 결제창 X / 취소 클릭 시 SPA 페이지가
    // location.href = closeUrl 로 전체 새로고침되어 체크아웃 폼 입력 상태
    // (배송지/연락처/옵션 선택) 가 모두 휘발하는 UX 회귀 회피.
    // 빈 문자열일 때 INIStdPay 는 자체 fallback 으로 팝업만 닫고 부모
    // 페이지는 유지하는 동작을 기대 (PG 매뉴얼 상 옵션 필드).
    // 미동작 시 옵션 A 로 현재 URL 을 closeUrl 로 두는 fallback 가능.
    const orderCloseUrl = '';
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
            // 기본 acceptmethod 토큰 (일반 결제수단 옵션)
            const escrow = config.use_escrow ? 'useescrow:' : '';
            const creditPoint = config.use_credit_point ? 'CREDITCARD(Y):' : '';
            const base = paymentMethod === 'phone'
                ? `HPP(1):${escrow}${creditPoint}centerCd(Y)`
                : `${escrow}${creditPoint}centerCd(Y)`;

            // 간편결제 (LPAY / 카카오페이) 는 base 뒤에 ':cardonly' 를 append.
            // gnuboard5 orderform.4.php 의
            //   f.acceptmethod.value = f.acceptmethod.value + ":cardonly"
            // 와 일치 — 에스크로 옵션 등 base 를 보존하고 cardonly 만 추가.
            if (paymentMethod === 'kginicis_lpay' || paymentMethod === 'kginicis_kakaopay') {
                return base ? `${base}:cardonly` : 'cardonly';
            }
            return base;
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

function buildCbtExtraData(config: ClientConfig): CbtExtraData {
    const base: CbtExtraData = config.cbt_extra_data ?? {};

    return {
        ...base,
        paymentUI: {
            language: 'JP',
            ...(base.paymentUI ?? {}),
        },
        payment: {
            paymethod: ['CARD'],
            ...(base.payment ?? {}),
            isMobile: isMobileUserAgent() ? 'true' : 'false',
        },
    };
}

async function requestCbtPayment(
    G7Core: any,
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
): Promise<void> {
    const japanMid = config.japan_mid;
    const timestamp = formatCbtTimestamp();

    const buyerEmail = pgPaymentData.customer_email ?? '';
    const buyerPhone = pgPaymentData.customer_phone ?? '';
    const tokenResponse: { data: CbtCheckoutTokenResponse } = await G7Core.api.post(
        config.callback_urls.cbt_checkout_token,
        {
            oid: pgPaymentData.order_number,
            price: pgPaymentData.amount,
            buyer_email: buyerEmail,
            buyer_phone: buyerPhone,
        },
    );

    const { checkout_token: checkoutToken } = tokenResponse.data;

    const hashResponse: { data: CbtHashDataResponse } = await G7Core.api.post(
        config.callback_urls.cbt_hash_data,
        {
            oid: pgPaymentData.order_number,
            price: pgPaymentData.amount,
            timestamp,
            buyer_email: buyerEmail,
            buyer_phone: buyerPhone,
            checkout_token: checkoutToken,
        },
    );

    const { hash_data: hashData } = hashResponse.data;

    const returnUrl =
        window.location.origin +
        config.callback_urls.cbt_callback +
        `?oid=${encodeURIComponent(pgPaymentData.order_number)}`;

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
        extraData:   JSON.stringify(buildCbtExtraData(config)),
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
        const isJpy = currency === 'JPY';
        const isJapanConfigured =
            config.japan_enabled &&
            !!config.japan_mid &&
            config.japan_configured !== false;

        if (isJpy && !isJapanConfigured) {
            throw new Error('KG Inicis Japan CBT payment is not configured.');
        }

        if (isJpy) {
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
