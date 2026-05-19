/**
 * 주문 생성 API 응답 인터셉터
 *
 * 체크아웃 템플릿(_checkout_summary.json)에는 'sirsoft-tosspayments' 분기만
 * 정의되어 있어서, 'sirsoft-kginicis' PG는 navigate 기본 분기로 떨어져
 * /shop/orders/{order_number}/complete 로 이동해버림 (결제창 미노출).
 *
 * 코어/템플릿 수정 없이 이 문제를 우회하기 위해 plugin loading 시점에
 * window.fetch 를 래핑해 다음을 수행:
 *
 *   1. POST /api/modules/sirsoft-ecommerce/user/orders 응답을 가로챈다
 *   2. data.pg_provider === 'sirsoft-kginicis' 이면 requestPayment 핸들러를 직접 호출하여 결제창 띄움
 *   3. data.redirect_url 을 현재 URL로 교체하고 requires_pg_payment를 false로 변경
 *      → 템플릿 fallback 분기의 navigate 가 navigate-to-self 가 되어 실질적 이동 없음
 *
 * 결과: 체크아웃 페이지에 머문 채 PG 팝업이 뜨고, PG 콜백이 정식 complete 페이지로 redirect.
 */

import { requestPaymentHandler } from './handlers/requestPayment';

const ORDER_CREATE_PATH = '/api/modules/sirsoft-ecommerce/user/orders';
const TARGET_PG_PROVIDER = 'sirsoft-kginicis';
const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

interface OrderCreateResponseBody {
    success?: boolean;
    message?: string;
    data?: {
        order?: { order_number?: string };
        redirect_url?: string;
        requires_pg_payment?: boolean;
        pg_provider?: string;
        pg_payment_data?: Record<string, unknown>;
    };
}

function extractPaymentMethodFromBody(body: string): string | undefined {
    try {
        const parsed = JSON.parse(body) as Record<string, unknown>;
        return parsed['payment_method'] as string | undefined;
    } catch {
        return undefined;
    }
}

/**
 * KG 이니시스 간편결제 (LPAY/카카오페이/삼성페이) 선택 시 _local.paymentMethod 가
 * 'kginicis_lpay' 등의 PG 식별자로 설정된다. 그러나 코어 PaymentMethodEnum 은
 * card/vbank/bank/phone 등 일반 결제수단만 허용하므로 백엔드 validation 에서
 * 'kginicis_*' 가 거부된다.
 *
 * 본 헬퍼는 요청 body 의 payment_method 가 'kginicis_*' 형태이면 'card' 로
 * 치환한 새 body 를 반환한다 — UI/local state 는 PG 식별자를 그대로 유지해
 * requestPayment 핸들러의 gopaymethod 매핑이 정상 동작한다.
 *
 * gnuboard5 shop/inicis/lpay_form.1.php 의 P_INI_PAYMENT/gopaymethod 패턴과
 * 동일한 의미: '결제수단 = 신용카드, 간편결제 변종 = gopaymethod=LPAY'.
 */
function rewriteEasyPayInBody(body: string): { rewritten: string; originalMethod: string | undefined } {
    try {
        const parsed = JSON.parse(body) as Record<string, unknown>;
        const pm = parsed['payment_method'];
        if (typeof pm === 'string' && pm.startsWith('kginicis_')) {
            const original = pm;
            parsed['payment_method'] = 'card';
            return { rewritten: JSON.stringify(parsed), originalMethod: original };
        }
        return { rewritten: body, originalMethod: typeof pm === 'string' ? pm : undefined };
    } catch {
        return { rewritten: body, originalMethod: undefined };
    }
}

function extractUrl(input: RequestInfo | URL): string {
    if (typeof input === 'string') return input;
    if (input instanceof URL) return input.toString();
    if (typeof Request !== 'undefined' && input instanceof Request) return input.url;
    return String(input);
}

function extractMethod(input: RequestInfo | URL, init?: RequestInit): string {
    if (init?.method) return init.method.toUpperCase();
    if (typeof Request !== 'undefined' && input instanceof Request) return input.method.toUpperCase();
    return 'GET';
}

function isTargetEndpoint(url: string, method: string): boolean {
    if (method !== 'POST') return false;
    // 쿼리스트링/해시 제거 후 경로만 비교
    const path = url.split('?')[0].split('#')[0];
    return path === ORDER_CREATE_PATH || path.endsWith(ORDER_CREATE_PATH);
}

function buildNoOpRedirectUrl(): string {
    // 현재 페이지 URL — navigate-to-self 는 React Router에서 사실상 no-op
    return window.location.pathname + window.location.search + window.location.hash;
}

/**
 * 응답 본문을 mutate한 새 Response 객체 생성
 *
 * 원본 Response 의 status/headers 는 보존하고 본문만 재구성.
 */
function mutateResponse(originalResponse: Response, mutatedBody: OrderCreateResponseBody): Response {
    const json = JSON.stringify(mutatedBody);
    return new Response(json, {
        status: originalResponse.status,
        statusText: originalResponse.statusText,
        headers: originalResponse.headers,
    });
}

export function installOrderResponseInterceptor(): void {
    if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
        return;
    }

    // 중복 설치 방지 — HMR / 다중 IIFE 로드 시
    const flag = '__sirsoftKginicisInterceptorInstalled' as const;
    const w = window as unknown as Record<string, unknown>;
    if (w[flag]) {
        return;
    }
    w[flag] = true;

    // 최초 설치 플러그인이 원본 브라우저 fetch를 보존 (다른 PG 인터셉터가 쌓이기 전)
    const ORIGINAL_FETCH_KEY = '__sirsoftPgOriginalFetch';
    if (!w[ORIGINAL_FETCH_KEY]) {
        w[ORIGINAL_FETCH_KEY] = window.fetch.bind(window);
    }

    const originalFetch = window.fetch.bind(window);

    window.fetch = async function patchedFetch(
        input: RequestInfo | URL,
        init?: RequestInit
    ): Promise<Response> {
        const url = extractUrl(input);
        const method = extractMethod(input, init);

        if (!isTargetEndpoint(url, method)) {
            return originalFetch(input, init);
        }

        // 요청 body에서 payment_method 추출 (vbank, bank, phone 등 비카드 결제수단 감지)
        // 동시에 'kginicis_*' 간편결제 식별자는 'card' 로 치환하여 backend enum 검증을 통과시킨다.
        let paymentMethod: string | undefined;
        let mutatedInit: RequestInit | undefined = init;
        if (init?.body && typeof init.body === 'string') {
            const { rewritten, originalMethod } = rewriteEasyPayInBody(init.body);
            paymentMethod = originalMethod;
            if (rewritten !== init.body) {
                logger.info('rewrote request payment_method', { from: originalMethod, to: 'card' });
                mutatedInit = { ...init, body: rewritten };
            }
        }
        // G7Core 로컬 상태 fallback
        if (!paymentMethod) {
            try {
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                paymentMethod = ((window as any).G7Core)?.state?.getLocal?.()?.paymentMethod as string | undefined;
            } catch { /* ignore */ }
        }

        const response = await originalFetch(input, mutatedInit);

        // 본문은 한 번만 읽을 수 있으므로 클론
        let cloned: Response;
        try {
            cloned = response.clone();
        } catch {
            return response;
        }

        let body: OrderCreateResponseBody | null = null;
        try {
            body = (await cloned.json()) as OrderCreateResponseBody;
        } catch {
            // 비-JSON 응답이면 그대로 통과
            return response;
        }

        const data = body?.data;
        if (!data) return response;

        const requiresPg = data.requires_pg_payment === true;
        const isKginicis = data.pg_provider === TARGET_PG_PROVIDER;

        if (!requiresPg || !isKginicis) {
            return response;
        }

        const pgPaymentData = data.pg_payment_data;
        if (!pgPaymentData) {
            logger.warn('kginicis order detected but pg_payment_data missing');
            return response;
        }

        logger.info('intercepted order create response — opening PG popup');

        // 1) 결제창 호출 (비동기 — 팝업이 뜨도록 fire-and-forget)
        //    실패 시 requestPaymentHandler 내부에서 isSubmittingOrder=false 처리됨
        void requestPaymentHandler({
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            params: { pgPaymentData: pgPaymentData as any, paymentMethod },
        });

        // 2) 응답 mutate — 템플릿의 navigate fallback 을 무력화
        //    - requires_pg_payment: false  (혹시 다른 곳에서 참조해도 안전)
        //    - redirect_url: 현재 URL       (navigate-to-self → 실질적 이동 없음)
        const mutatedBody: OrderCreateResponseBody = {
            ...body,
            data: {
                ...data,
                requires_pg_payment: false,
                redirect_url: buildNoOpRedirectUrl(),
            },
        };

        return mutateResponse(response, mutatedBody);
    };

    logger.info('order response interceptor installed');
}
