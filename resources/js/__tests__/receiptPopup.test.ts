import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { fetchKginicisReceiptInfo } from '../receiptPopup';

describe('fetchKginicisReceiptInfo — 영수증 조회 헤더 분기', () => {
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
        localStorage.clear();
        sessionStorage.clear();
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        sessionStorage.clear();
        vi.restoreAllMocks();
    });

    it('회원 토큰만 있으면 Authorization Bearer 헤더로 호출한다', async () => {
        localStorage.setItem('auth_token', 'sanctum-member-token-xyz');

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ receipt_type: 'inicis_receipt', receipt_url: 'https://example.test/receipt' }),
        } as Response);
        globalThis.fetch = fetchMock;

        await fetchKginicisReceiptInfo('ORD-MEMBER-100');

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/plugins/sirsoft-pay_kginicis/user/orders/ORD-MEMBER-100/receipt');
        expect(init.headers).toMatchObject({
            Authorization: 'Bearer sanctum-member-token-xyz',
            Accept: 'application/json',
        });
        expect(init.headers['X-Guest-Order-Token']).toBeUndefined();
    });

    it('비회원 토큰만 있으면 X-Guest-Order-Token 헤더로 호출한다 (회원 Authorization 없음)', async () => {
        sessionStorage.setItem('g7_guest_order_token', '1780627982|signature-hex');

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ receipt_type: 'inicis_receipt' }),
        } as Response);
        globalThis.fetch = fetchMock;

        await fetchKginicisReceiptInfo('ORD-GUEST-200');

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [, init] = fetchMock.mock.calls[0];
        expect(init.headers).toMatchObject({
            'X-Guest-Order-Token': '1780627982|signature-hex',
            Accept: 'application/json',
        });
        expect(init.headers.Authorization).toBeUndefined();
    });

    it('회원/비회원 토큰 모두 있으면 회원 토큰을 우선해 Authorization 만 보낸다', async () => {
        localStorage.setItem('auth_token', 'sanctum-member-token-xyz');
        sessionStorage.setItem('g7_guest_order_token', '1780627982|signature-hex');

        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) } as Response);
        globalThis.fetch = fetchMock;

        await fetchKginicisReceiptInfo('ORD-MIX-300');

        const [, init] = fetchMock.mock.calls[0];
        expect(init.headers.Authorization).toBe('Bearer sanctum-member-token-xyz');
        expect(init.headers['X-Guest-Order-Token']).toBeUndefined();
    });

    it('두 토큰 모두 없으면 fetch 호출 자체를 생략하고 null 을 반환한다', async () => {
        const fetchMock = vi.fn();
        globalThis.fetch = fetchMock;

        const result = await fetchKginicisReceiptInfo('ORD-ANON-400');

        expect(result).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
