/**
 * requestPayment 핸들러 테스트
 *
 * KG 이니시스 표준결제창 호출 핸들러의 입력 검증 및 에러 경로 동작을 검증합니다.
 * SDK 로드/INIStdPay.pay 호출/모바일 redirect 등 외부 부수효과 의존 흐름은
 * tests/scenarios 매니페스트에서 다루며, 본 단위 테스트는 초기 가드 위주.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { requestPaymentHandler } from '../../handlers/requestPayment';

const PG_PAYMENT = {
    order_number: 'ORD-001',
    order_name: 'Test Order',
    amount: 10000,
};

describe('requestPaymentHandler', () => {
    let apiGet: ReturnType<typeof vi.fn>;
    let setLocalSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        apiGet = vi.fn();
        setLocalSpy = vi.fn();
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet },
            state: { setLocal: setLocalSpy },
            toast: { error: vi.fn() },
        };
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
        vi.restoreAllMocks();
    });

    it('pgPaymentData가 없으면 console.error 후 조기 반환', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        await requestPaymentHandler({ params: {} });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('pgPaymentData is required')
        );
        expect(apiGet).not.toHaveBeenCalled();
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    it('client config 응답에 data 가 없으면 console.error 후 조기 반환', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockResolvedValue({}); // data 누락

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('Failed to fetch client config'),
            expect.anything()
        );
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    it('client config API 자체가 throw 하면 catch 블록에서 setLocal 복구', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('Network error'));

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        // catch 블록은 setLocal로 결제 진행 상태를 복구
        expect(setLocalSpy).toHaveBeenCalledWith(
            expect.objectContaining({ isSubmittingOrder: false })
        );
    });
});

/**
 * CBT (일본 엔 결제, JPPG) 흐름 회귀 테스트
 *
 * KG 이니시스 CBT 매뉴얼(https://manual.inicis.com/jppay/cbtauth.html)이 요구하는
 * 파라미터 형식을 준수하는지 검증한다.
 *
 * - cbtType: 'JPPG' 고정 (4 bytes)
 * - timestamp: yyyyMMddHHmmss (14 bytes, epoch ms 사용 금지)
 * - buyerTel: 선택이지만 customer_phone 이 있으면 전송
 * - extraData: JSON String (빈 객체 허용)
 * - hashData plainText 순서는 백엔드 책임 (INIAPIKey+mid+timestamp+amount+orderId)
 */
describe('requestPaymentHandler — CBT (JPPG) 분기', () => {
    const CBT_PG_PAYMENT = {
        order_number: 'JP-ORD-001',
        order_name: 'JP Test Order',
        amount: 2,
        currency: 'JPY' as const,
        customer_name: 'Yamada Taro',
        customer_phone: '09012345678',
        customer_email: 'yamada@example.jp',
    };

    const CLIENT_CONFIG = {
        data: {
            mid: 'INIpayTest',
            japan_enabled: true,
            japan_mid: 'CBTTEST001',
            callback_urls: {
                cbt_hash_data: '/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data',
                cbt_callback: '/api/plugins/sirsoft-pay_kginicis/payment/cbt/callback',
                cbt_auth_url: 'https://devcbt.inicis.com/cbtauth',
            },
        },
    };

    let apiGet: ReturnType<typeof vi.fn>;
    let apiPost: ReturnType<typeof vi.fn>;
    let submitSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        apiGet = vi.fn().mockResolvedValue(CLIENT_CONFIG);
        apiPost = vi.fn().mockResolvedValue({ data: { hash_data: 'sha512hashstub' } });
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet, post: apiPost },
            state: { setLocal: vi.fn() },
            toast: { error: vi.fn() },
        };
        // form.submit 이 jsdom 에서 navigation 을 일으키지 않게 mock
        submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
        // 테스트 사이에 form 잔재 제거
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    /**
     * 가장 최근에 submit 된 form 의 hidden field 값 맵 추출
     */
    function getLastSubmittedFormFields(): Record<string, string> {
        const forms = document.body.querySelectorAll('form');
        const form = forms[forms.length - 1];
        if (!form) throw new Error('No form was submitted');
        const fields: Record<string, string> = {};
        form.querySelectorAll('input[type="hidden"]').forEach((el) => {
            const input = el as HTMLInputElement;
            fields[input.name] = input.value;
        });
        return fields;
    }

    it('currency=JPY + japan_enabled + japan_mid 면 CBT 분기로 진입해 cbtauth 로 폼 전송', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: CBT_PG_PAYMENT } });

        expect(apiPost).toHaveBeenCalledWith(
            CLIENT_CONFIG.data.callback_urls.cbt_hash_data,
            expect.objectContaining({
                oid: CBT_PG_PAYMENT.order_number,
                price: CBT_PG_PAYMENT.amount,
            }),
        );
        expect(submitSpy).toHaveBeenCalledTimes(1);
        const fields = getLastSubmittedFormFields();
        const form = document.body.querySelector('form')!;
        expect(form.action).toBe(CLIENT_CONFIG.data.callback_urls.cbt_auth_url);
        expect(form.method.toLowerCase()).toBe('post');
        expect(fields.cbtType).toBe('JPPG');
        expect(fields.mid).toBe(CLIENT_CONFIG.data.japan_mid);
        expect(fields.orderId).toBe(CBT_PG_PAYMENT.order_number);
        expect(fields.amount).toBe(String(CBT_PG_PAYMENT.amount));
        expect(fields.goodName).toBe(CBT_PG_PAYMENT.order_name);
        expect(fields.hashData).toBe('sha512hashstub');
        expect(fields.extraData).toBe('{}');
    });

    it('timestamp 가 yyyyMMddHHmmss 형식 (14자 숫자, epoch ms 아님)', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: CBT_PG_PAYMENT } });

        const fields = getLastSubmittedFormFields();
        // 정규식: YYYY(2026 이상) MM(01-12) DD(01-31) HH(00-23) mm(00-59) ss(00-59)
        expect(fields.timestamp).toMatch(
            /^(20\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])([01]\d|2[0-3])([0-5]\d)([0-5]\d)$/,
        );
        expect(fields.timestamp).toHaveLength(14);
        // epoch ms (13자) 가 우연히 같은 정규식을 통과하지 않게 길이도 명시
        expect(fields.timestamp).not.toMatch(/^\d{13}$/);
        // hash-data 호출 시에도 같은 timestamp 가 전달되어야 함 (백엔드 hash 일관성)
        const hashCallArgs = apiPost.mock.calls[0][1] as { timestamp: string };
        expect(hashCallArgs.timestamp).toBe(fields.timestamp);
    });

    it('buyerTel 은 customer_phone 값으로 전송 (매뉴얼 선택 파라미터)', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: CBT_PG_PAYMENT } });

        const fields = getLastSubmittedFormFields();
        expect(fields.buyerName).toBe(CBT_PG_PAYMENT.customer_name);
        expect(fields.buyerTel).toBe(CBT_PG_PAYMENT.customer_phone);
        expect(fields.buyerEmail).toBe(CBT_PG_PAYMENT.customer_email);
    });

    it('customer_phone 누락 시 buyerTel 은 빈 문자열로 안전 처리', async () => {
        const noPhone = { ...CBT_PG_PAYMENT, customer_phone: undefined };

        await requestPaymentHandler({ params: { pgPaymentData: noPhone } });

        const fields = getLastSubmittedFormFields();
        expect(fields.buyerTel).toBe('');
    });

    it('returnUrl 은 현재 origin + cbt_callback + oid/amount 쿼리', async () => {
        await requestPaymentHandler({ params: { pgPaymentData: CBT_PG_PAYMENT } });

        const fields = getLastSubmittedFormFields();
        expect(fields.returnUrl).toBe(
            `${window.location.origin}${CLIENT_CONFIG.data.callback_urls.cbt_callback}` +
                `?oid=${encodeURIComponent(CBT_PG_PAYMENT.order_number)}` +
                `&amount=${CBT_PG_PAYMENT.amount}`,
        );
    });

    it('japan_mid 누락 시 CBT 분기 진입 안 함 (cbt hash-data API 미호출)', async () => {
        // japan_enabled=true 라도 japan_mid 가 빈 문자열이면 isJapan 조건이 false
        // → 한국 분기로 빠지며 cbt_hash_data 가 아닌 signature API 호출됨
        apiGet.mockResolvedValue({
            data: { ...CLIENT_CONFIG.data, japan_mid: '' },
        });
        // signature API mock (KRW 분기 진입을 막지 않기 위해)
        apiPost.mockResolvedValue({ data: { signature: 's', verification: 'v', mKey: 'k' } });
        // INIStdPay SDK 로드 분기를 건너뛰도록 미리 mock
        (window as Record<string, unknown>).INIStdPay = { pay: vi.fn() };

        await requestPaymentHandler({ params: { pgPaymentData: CBT_PG_PAYMENT } });

        // CBT hash-data endpoint 가 호출되지 않았어야 함
        const cbtCall = apiPost.mock.calls.find(
            ([url]) => url === CLIENT_CONFIG.data.callback_urls.cbt_hash_data,
        );
        expect(cbtCall).toBeUndefined();

        delete (window as Record<string, unknown>).INIStdPay;
    });
});

/**
 * 모바일 P_INI_PAYMENT 매핑 회귀 테스트
 *
 * KG 이니시스 모바일 표준결제 매뉴얼(https://manual.inicis.com/pay/stdpay_m.html#popup_7) 의
 * P_INI_PAYMENT 코드:
 *   신용카드   → CARD
 *   계좌이체   → BANK
 *   가상계좌   → VBANK
 *   휴대폰     → MOBILE   ← (PC 의 'HPP' 와 다름)
 *   도서문화   → BCSH
 *   비인증카드 → NOAUTHCARD
 *
 * 회귀: 휴대폰 결제수단의 P_INI_PAYMENT 값이 'HPP' 로 설정되어 PG 가
 * "잘못된 P_INI_PAYMENT 입니다." 응답을 반환하던 문제를 차단한다.
 */
describe('requestPaymentHandler — 모바일 P_INI_PAYMENT 매핑', () => {
    const PG_PAYMENT = {
        order_number: 'ORD-MOBILE-001',
        order_name: 'Mobile Test',
        amount: 1000,
        customer_name: '홍길동',
        customer_phone: '01012345678',
        customer_email: 'test@test.com',
    };

    const CLIENT_CONFIG = {
        data: {
            mid: 'INIpayTest',
            japan_enabled: false,
            japan_mid: '',
            callback_urls: {
                mobile_signature: '/api/plugins/sirsoft-pay_kginicis/payment/mobile/signature',
                mobile_callback: '/plugins/sirsoft-pay_kginicis/payment/mobile/callback',
            },
        },
    };

    let apiGet: ReturnType<typeof vi.fn>;
    let apiPost: ReturnType<typeof vi.fn>;
    let submitSpy: ReturnType<typeof vi.spyOn>;
    let originalUserAgent: PropertyDescriptor | undefined;

    function getLastSubmittedFormFields(): Record<string, string> {
        const forms = document.body.querySelectorAll('form');
        const form = forms[forms.length - 1];
        if (!form) throw new Error('No form was submitted');
        const fields: Record<string, string> = {};
        form.querySelectorAll('input[type="hidden"]').forEach((el) => {
            const input = el as HTMLInputElement;
            fields[input.name] = input.value;
        });
        return fields;
    }

    beforeEach(() => {
        apiGet = vi.fn().mockResolvedValue(CLIENT_CONFIG);
        apiPost = vi.fn().mockResolvedValue({
            data: { chkfake: 'chkfakestub', mobile_payment_url: 'https://mobile.inicis.com/smart/payment/' },
        });
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet, post: apiPost },
            state: { setLocal: vi.fn() },
            toast: { error: vi.fn() },
        };
        submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});

        // 모바일 UA 강제 (isMobileUserAgent → true)
        originalUserAgent = Object.getOwnPropertyDescriptor(window.navigator, 'userAgent');
        Object.defineProperty(window.navigator, 'userAgent', {
            value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
            configurable: true,
        });
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
        document.body.innerHTML = '';
        if (originalUserAgent) {
            Object.defineProperty(window.navigator, 'userAgent', originalUserAgent);
        }
        vi.restoreAllMocks();
    });

    it("휴대폰 결제(phone) → P_INI_PAYMENT='MOBILE' (매뉴얼 표준)", async () => {
        await requestPaymentHandler({
            params: {
                pgPaymentData: PG_PAYMENT,
                paymentMethod: 'phone',
            },
        });

        expect(submitSpy).toHaveBeenCalledTimes(1);
        const fields = getLastSubmittedFormFields();
        expect(fields.P_INI_PAYMENT).toBe('MOBILE');
        // 회귀 차단: PC 의 HPP 값을 모바일에 잘못 매핑하지 않도록
        expect(fields.P_INI_PAYMENT).not.toBe('HPP');
    });

    it.each([
        ['card', 'CARD'],
        ['vbank', 'VBANK'],
        ['bank', 'BANK'],
    ])("결제수단 %s → P_INI_PAYMENT='%s' (매뉴얼 표준 유지)", async (paymentMethod, expected) => {
        await requestPaymentHandler({
            params: { pgPaymentData: PG_PAYMENT, paymentMethod },
        });
        const fields = getLastSubmittedFormFields();
        expect(fields.P_INI_PAYMENT).toBe(expected);
    });
});
