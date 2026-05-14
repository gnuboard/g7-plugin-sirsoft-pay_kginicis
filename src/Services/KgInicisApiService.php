<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayKginicis\Exceptions\KgInicisApiException;

class KgInicisApiService
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    private const LIVE_MID_PREFIX = 'SIR';

    private const JS_URL_TEST = 'https://stgstdpay.inicis.com/stdjs/INIStdPay.js';

    private const JS_URL_LIVE = 'https://stdpay.inicis.com/stdjs/INIStdPay.js';

    private const API_BASE_URL_TEST = 'https://stginiapi.inicis.com';

    private const API_BASE_URL_LIVE = 'https://iniapi.inicis.com';

    /**
     * idc_name → PC 서버 승인 URL 화이트리스트 (SSRF 방어)
     * 출처: 이니시스 PC 일반결제 샘플 properties.php
     */
    private const IDC_AUTH_URLS = [
        'fc'  => 'https://fcstdpay.inicis.com/api/payAuth',
        'ks'  => 'https://ksstdpay.inicis.com/api/payAuth',
        'stg' => 'https://stgstdpay.inicis.com/api/payAuth',
    ];

    /**
     * idc_name → 모바일 서버 승인 URL 화이트리스트
     * 출처: 이니시스 모바일 결제 메뉴얼 IDC센터코드 표
     */
    private const IDC_MOBILE_AUTH_URLS = [
        'fc'  => 'https://fcmobile.inicis.com/smart/payReq.ini',
        'ks'  => 'https://ksmobile.inicis.com/smart/payReq.ini',
        'stg' => 'https://stgmobile.inicis.com/smart/payReq.ini',
    ];

    /** idc_name → PC 망취소 URL 화이트리스트 */
    private const IDC_NET_CANCEL_URLS = [
        'fc'  => 'https://fcstdpay.inicis.com/api/netCancel',
        'ks'  => 'https://ksstdpay.inicis.com/api/netCancel',
        'stg' => 'https://stgstdpay.inicis.com/api/netCancel',
    ];

    /** 모바일 결제창 URL (테스트/라이브 공통, MID로 모드 구분) */
    private const MOBILE_PAYMENT_URL = 'https://mobile.inicis.com/smart/payment/';

    private const ESCROW_TEST_MID = 'iniescrow0';

    private const ESCROW_TEST_INIAPI_KEY = 'yERbIlJ3NhTeObsA';

    private const ESCROW_TEST_INIAPI_IV = 'tOGDXbfoajk2DQ==';

    /** 에스크로 테스트 구매결정용 signKey (mKey = SHA-256(signKey)) */
    private const ESCROW_TEST_SIGN_KEY = 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS';

    private const CBT_AUTH_URL_TEST = 'https://devcbt.inicis.com/cbtauth';

    private const CBT_AUTH_URL_LIVE = 'https://cbt.inicis.com/cbtauth';

    private const CBT_APPROVE_URL_TEST = 'https://devcbt.inicis.com/cbtapprove';

    private const CBT_APPROVE_URL_LIVE = 'https://cbt.inicis.com/cbtapprove';

    /** KG 이니시스 일본결제(CBT) 공식 테스트 MID — 고정값, 변경 불가 */
    public const JAPAN_TEST_MID = 'CBTTEST001';

    private bool $isTest;

    private string $mid;

    private string $signKey;

    private string $inapiKey;

    private string $inapiIv;

    private string $standardTestMid;

    private string $standardTestInapiKey;

    private string $standardTestInapiIv;

    private bool $japanEnabled;

    private string $japanMid;

    private string $japanCbtKey;

    private string $mobileHashKey;

    public function __construct(PluginSettingsService $pluginSettingsService)
    {
        $settings = $pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $this->isTest = $settings['is_test_mode'] ?? true;
        $useEscrow = (bool) ($settings['use_escrow'] ?? false);
        $this->mid = $this->isTest
            ? ($useEscrow ? self::ESCROW_TEST_MID : ($settings['test_mid'] ?? ''))
            : $this->buildLiveMid($settings['live_mid'] ?? '');
        $this->signKey = $this->isTest
            ? ($settings['test_sign_key'] ?? '')
            : ($settings['live_sign_key'] ?? '');
        $this->inapiKey = $this->isTest
            ? ($useEscrow ? self::ESCROW_TEST_INIAPI_KEY : ($settings['test_iniapi_key'] ?? ''))
            : ($settings['live_iniapi_key'] ?? '');
        $this->inapiIv = $this->isTest
            ? ($useEscrow ? self::ESCROW_TEST_INIAPI_IV : ($settings['test_iniapi_iv'] ?? ''))
            : ($settings['live_iniapi_iv'] ?? '');
        $this->standardTestMid = $settings['test_mid'] ?? 'INIpayTest';
        $this->standardTestInapiKey = $settings['test_iniapi_key'] ?? 'ItEQKi3rY7uvDS8l';
        $this->standardTestInapiIv = $settings['test_iniapi_iv'] ?? '2IgsAQSbMqHkAkj3';
        $this->japanEnabled = $settings['japan_enabled'] ?? false;
        $this->japanMid = $this->isTest
            ? self::JAPAN_TEST_MID
            : ($settings['live_japan_mid'] ?? '');
        $this->japanCbtKey = $this->isTest
            ? ($settings['test_japan_sign_key'] ?? '')
            : ($settings['live_japan_sign_key'] ?? '');
        $this->mobileHashKey = $this->isTest
            ? ($settings['test_mobile_hash_key'] ?? '')
            : ($settings['live_mobile_hash_key'] ?? '');
    }

/**

 * isTestMode

 *

 * @return bool

 */

    public function isTestMode(): bool
    {
        return $this->isTest;
    }

/**

 * useEscrowCredentials

 *

 * @param  bool  $isEscrow

 */

    public function useEscrowCredentials(bool $isEscrow): void
    {
        if (! $this->isTest) {
            return;
        }

        if ($isEscrow) {
            $this->mid = self::ESCROW_TEST_MID;
            $this->inapiKey = self::ESCROW_TEST_INIAPI_KEY;
            $this->inapiIv = self::ESCROW_TEST_INIAPI_IV;
        } else {
            $this->mid = $this->standardTestMid;
            $this->inapiKey = $this->standardTestInapiKey;
            $this->inapiIv = $this->standardTestInapiIv;
        }
    }

/**

 * getMid

 *

 * @return string

 */

    public function getMid(): string
    {
        return $this->mid;
    }

    /**
     * getEscrowConfirmMKey
     *
     * @return string
     */
    public function getEscrowConfirmMKey(): string
    {
        $signKey = $this->isTest ? self::ESCROW_TEST_SIGN_KEY : $this->signKey;
        return hash('sha256', $signKey);
    }

/**

 * getJapanMid

 *

 * @return string

 */

    public function getJapanMid(): string
    {
        return $this->japanMid;
    }

/**

 * isJapanEnabled

 *

 * @return bool

 */

    public function isJapanEnabled(): bool
    {
        return $this->japanEnabled;
    }

/**

 * getJsUrl

 *

 * @return string

 */

    public function getJsUrl(): string
    {
        return $this->isTest ? self::JS_URL_TEST : self::JS_URL_LIVE;
    }

/**

 * getCbtAuthUrl

 *

 * @return string

 */

    public function getCbtAuthUrl(): string
    {
        return $this->isTest ? self::CBT_AUTH_URL_TEST : self::CBT_AUTH_URL_LIVE;
    }

/**

 * getCbtApproveUrl

 *

 * @return string

 */

    public function getCbtApproveUrl(): string
    {
        return $this->isTest ? self::CBT_APPROVE_URL_TEST : self::CBT_APPROVE_URL_LIVE;
    }

    /**
     * resolveIdcAuthUrl
     *
     * @param  string  $idcName
     * @param  string  $receivedUrl
     * @return ?string
     */
    public function resolveIdcAuthUrl(string $idcName, string $receivedUrl = ''): ?string
    {
        $pc     = self::IDC_AUTH_URLS[$idcName] ?? null;
        $mobile = self::IDC_MOBILE_AUTH_URLS[$idcName] ?? null;

        if ($receivedUrl !== '' && $receivedUrl === $mobile) {
            return $mobile;
        }

        return $pc;
    }

    /**
     * isValidIdcAuthUrl
     *
     * @param  string  $idcName
     * @param  string  $receivedUrl
     * @return bool
     */
    public function isValidIdcAuthUrl(string $idcName, string $receivedUrl): bool
    {
        $pc     = self::IDC_AUTH_URLS[$idcName] ?? null;
        $mobile = self::IDC_MOBILE_AUTH_URLS[$idcName] ?? null;

        return $receivedUrl === $pc || $receivedUrl === $mobile;
    }

    /**
     * resolveIdcNetCancelUrl
     *
     * @param  string  $idcName
     * @return ?string
     */
    public function resolveIdcNetCancelUrl(string $idcName): ?string
    {
        return self::IDC_NET_CANCEL_URLS[$idcName] ?? null;
    }

    /**
     * getMKey
     *
     * @return string
     */
    public function getMKey(): string
    {
        return hash('sha256', $this->signKey);
    }

    /**
     * generateSignature
     *
     * @param  string  $oid
     * @param  int  $price
     * @param  string  $timestamp
     * @return string
     */
    public function generateSignature(string $oid, int $price, string $timestamp): string
    {
        $plain = 'oid=' . $oid . '&price=' . $price . '&timestamp=' . $timestamp;

        return hash('sha256', $plain);
    }

    /**
     * generateVerification
     *
     * @param  string  $oid
     * @param  int  $price
     * @param  string  $timestamp
     * @return string
     */
    public function generateVerification(string $oid, int $price, string $timestamp): string
    {
        $plain = 'oid=' . $oid . '&price=' . $price . '&signKey=' . $this->signKey . '&timestamp=' . $timestamp;

        return hash('sha256', $plain);
    }

    /**
     * generateCbtHashData
     *
     * @param  string  $mid
     * @param  string  $timestamp
     * @param  int  $amount
     * @param  string  $orderId
     * @return string
     */
    public function generateCbtHashData(string $mid, string $timestamp, int $amount, string $orderId): string
    {
        $plain = $this->japanCbtKey . $mid . $timestamp . (string) $amount . $orderId;

        return hash('sha512', $plain);
    }

    /**
     * authorizePayment
     *
     * @param  string  $authUrl
     * @param  string  $authToken
     * @return array
     */
    public function authorizePayment(string $authUrl, string $authToken): array
    {
        $timestamp = (string) round(microtime(true) * 1000);

        // 알파벳순 정렬: authToken < timestamp
        $signature = hash('sha256', 'authToken=' . $authToken . '&timestamp=' . $timestamp);

        // 알파벳순 정렬: authToken < signKey < timestamp
        $verification = hash('sha256', 'authToken=' . $authToken . '&signKey=' . $this->signKey . '&timestamp=' . $timestamp);

        $response = Http::asForm()->post($authUrl, [
            'mid'          => $this->mid,
            'authToken'    => $authToken,
            'signature'    => $signature,
            'verification' => $verification,
            'timestamp'    => $timestamp,
            'charset'      => 'UTF-8',
            'format'       => 'JSON',
        ]);

        if ($response->failed()) {
            throw new KgInicisApiException('KG Inicis authorize API error: HTTP ' . $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * CBT 승인 처리: POST /cbtapprove with mid + sid
     *
     * @param string $sid KG Inicis CBT 인증 후 반환된 세션 ID
     * @return array PG 응답 데이터
     * @throws \Exception API 호출 실패 시
     */
    public function approveCbtPayment(string $sid): array
    {
        $approveUrl = $this->getCbtApproveUrl();

        $response = Http::asForm()->post($approveUrl, [
            'mid' => $this->japanMid,
            'sid' => $sid,
        ]);

        if ($response->failed()) {
            throw new KgInicisApiException('KG Inicis CBT approve API error: HTTP ' . $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * 망취소 요청 (서버 승인 중 예외 발생 시 결제 원천 취소)
     *
     * @param string $netCancelUrl 이니시스가 콜백으로 전달한 netCancelUrl
     * @param string $authToken    인증 토큰
     */
    public function sendNetCancel(string $netCancelUrl, string $authToken): void
    {
        try {
            Http::asForm()->post($netCancelUrl, [
                'authToken' => $authToken,
            ]);
        } catch (\Throwable $e) {
            Log::error('KG Inicis net cancel failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * getMobilePaymentUrl
     *
     * @return string
     */
    public function getMobilePaymentUrl(): string
    {
        return self::MOBILE_PAYMENT_URL;
    }

    /**
     * generateMobileChkfake
     *
     * @param  string  $oid
     * @param  int  $amount
     * @param  string  $timestamp
     * @return string
     */
    public function generateMobileChkfake(string $oid, int $amount, string $timestamp): string
    {
        $plain = (string) $amount . $oid . $timestamp . $this->mobileHashKey;

        return base64_encode(hash('sha512', $plain, true));
    }

    /**
     * authorizeMobilePayment
     *
     * @param  string  $reqUrl
     * @param  string  $tid
     * @return array
     */
    public function authorizeMobilePayment(string $reqUrl, string $tid): array
    {
        $response = Http::asForm()->post($reqUrl, [
            'P_MID' => $this->mid,
            'P_TID' => $tid,
        ]);

        if ($response->failed()) {
            throw new KgInicisApiException('KG Inicis mobile authorize API error: HTTP ' . $response->status());
        }

        parse_str($response->body(), $result);

        return $result;
    }

    /**
     * 거래 조회 API 호출 (INIAPI v2)
     *
     * @param string $tid 거래번호
     * @return array PG 응답 데이터
     * @throws \Exception API 호출 실패 시
     */
    public function queryTransaction(string $tid): array
    {
        $type = 'inquiry';
        $timestamp = date('YmdHis');
        $clientIp = request()->ip() ?? '127.0.0.1';

        $detail = ['tid' => $tid];
        $detailJson = str_replace('\\/', '/', json_encode($detail, JSON_UNESCAPED_UNICODE));
        $hashData = hash('sha512', $this->inapiKey . $this->mid . $type . $timestamp . $detailJson);

        $baseUrl = $this->isTest ? self::API_BASE_URL_TEST : self::API_BASE_URL_LIVE;
        $apiUrl = $baseUrl . '/v2/pg/inquiry';

        $payload = [
            'mid'       => $this->mid,
            'type'      => $type,
            'timestamp' => $timestamp,
            'clientIp'  => $clientIp,
            'data'      => $detail,
            'hashData'  => $hashData,
        ];

        $response = Http::withHeaders(['Content-Type' => 'application/json;charset=utf-8'])
            ->post($apiUrl, $payload);

        if ($response->failed()) {
            throw new KgInicisApiException('KG Inicis inquiry API error: HTTP ' . $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * 결제 취소 API 호출 (INIAPI v2)
     *
     * @param string      $tid          거래번호 (이니시스 TID)
     * @param string      $payMethod    결제수단 (사용하지 않음, 하위 호환용)
     * @param int|null    $cancelPrice  취소 금액 (null이면 전액 취소)
     * @param string      $msg          취소 사유
     * @param int|null    $totalAmount  원결제 금액 (부분취소 시 confirmPrice 계산용)
     * @return array PG 응답 데이터
     * @throws \Exception API 호출 실패 시
     */
    public function cancelPayment(
        string $tid,
        string $payMethod,
        ?int $cancelPrice = null,
        string $msg = '관리자 취소',
        ?int $totalAmount = null,
    ): array {
        $type = $cancelPrice === null ? 'refund' : 'partialRefund';
        $timestamp = date('YmdHis');
        $clientIp = request()->ip() ?? '127.0.0.1';

        $detail = [
            'tid' => $tid,
            'msg' => $msg,
        ];

        if ($cancelPrice !== null) {
            $confirmPrice = $totalAmount !== null ? ($totalAmount - $cancelPrice) : 0;
            $detail['price'] = (string) $cancelPrice;
            $detail['confirmPrice'] = (string) $confirmPrice;
            $detail['currency'] = 'WON';
            $detail['tax'] = '0';
            $detail['taxfree'] = '0';
        }

        $detailJson = str_replace('\\/', '/', json_encode($detail, JSON_UNESCAPED_UNICODE));
        $hashData = hash('sha512', $this->inapiKey . $this->mid . $type . $timestamp . $detailJson);

        $baseUrl = $this->isTest ? self::API_BASE_URL_TEST : self::API_BASE_URL_LIVE;
        $apiUrl = $baseUrl . '/v2/pg/' . $type;

        $payload = [
            'mid' => $this->mid,
            'type' => $type,
            'timestamp' => $timestamp,
            'clientIp' => $clientIp,
            'data' => $detail,
            'hashData' => $hashData,
        ];

        // 훅: 결제 취소 전 (본인인증 등 확장 지점)
        HookManager::doAction('sirsoft-pay_kginicis.payment.before_cancel', $tid, $payMethod, $cancelPrice, $msg);

        $response = Http::withHeaders(['Content-Type' => 'application/json;charset=utf-8'])
            ->post($apiUrl, $payload);

        if ($response->failed()) {
            throw new KgInicisApiException('KG Inicis cancel API error: HTTP ' . $response->status());
        }

        $result = $response->json() ?? [];

        if (($result['resultCode'] ?? '') !== '00') {
            Log::error('KG Inicis cancel failed', [
                'result_code' => $result['resultCode'] ?? 'UNKNOWN',
                'result_msg' => $result['resultMsg'] ?? '',
                'tid' => $tid,
            ]);
            throw new KgInicisApiException($result['resultMsg'] ?? 'KG Inicis cancel failed');
        }

        // 훅: 결제 취소 완료 후 (외부 소비자 후처리 확장 지점)
        HookManager::doAction('sirsoft-pay_kginicis.payment.after_cancel', $tid, $result);

        return $result;
    }

    /**
     * 에스크로 배송등록/변경 API 호출 (INIAPI v1)
     *
     * PC/모바일 공통 엔드포인트: /api/v1/escrow
     * 메뉴얼: https://manual.inicis.com/pay/escrow_pc.html#dlv
     *
     * @param array $data {
     *   tid:         에스크로 결제 승인 TID
     *   oid:         주문번호
     *   price:       결제금액
     *   report:      등록형태 ('I'=등록, 'U'=변경)
     *   invoice:     운송장번호
     *   registName:  배송등록자
     *   exCode:      택배사코드 (hanjin, cjgls, loge, epost 등)
     *   exName:      택배사명
     *   charge:      배송비 지급형태 ('SH'=판매자부담, 'BH'=구매자부담)
     *   invoiceDay:  배송등록 확인일자 (예: '2024-01-01 10:00:00')
     *   sendName:    송신자 이름
     *   sendTel:     송신자 전화번호
     *   sendPost:    송신자 우편번호
     *   sendAddr1:   송신자 주소
     *   recvName:    수신자 이름
     *   recvTel:     수신자 전화번호
     *   recvPost:    수신자 우편번호
     *   recvAddr:    수신자 주소
     * }
     * @return array PG 응답 (resultCode '00' = 성공)
     * @throws \Exception
     */
    public function registerEscrowDelivery(array $data): array
    {
        $type = 'Dlv';
        $timestamp = date('YmdHis');
        $clientIp = request()->ip() ?? '127.0.0.1';

        // hash: SHA-512(key + type + timestamp + clientIp + mid + oid + tid + price)
        $plainText = $this->inapiKey . $type . $timestamp . $clientIp
            . $this->mid . $data['oid'] . $data['tid'] . $data['price'];
        $hashData = hash('sha512', $plainText);

        $payload = [
            'type'        => $type,
            'mid'         => $this->mid,
            'clientIp'    => $clientIp,
            'timestamp'   => $timestamp,
            'tid'         => $data['tid'],
            'oid'         => $data['oid'],
            'price'       => (string) $data['price'],
            'report'      => $data['report'] ?? 'I',
            'invoice'     => $data['invoice'],
            'registName'  => $data['registName'] ?? '',
            'exCode'      => $data['exCode'],
            'exName'      => $data['exName'],
            'charge'      => $data['charge'] ?? 'SH',
            'invoiceDay'  => $data['invoiceDay'] ?? date('Y-m-d H:i:s'),
            'sendName'    => $data['sendName'] ?? '',
            'sendTel'     => $data['sendTel'] ?? '',
            'sendPost'    => $data['sendPost'] ?? '',
            'sendAddr1'   => $data['sendAddr1'] ?? '',
            'recvName'    => $data['recvName'] ?? '',
            'recvTel'     => $data['recvTel'] ?? '',
            'recvPost'    => $data['recvPost'] ?? '',
            'recvAddr'    => $data['recvAddr'] ?? '',
            'hashData'    => $hashData,
        ];

        $baseUrl = $this->isTest ? self::API_BASE_URL_TEST : self::API_BASE_URL_LIVE;
        $apiUrl = $baseUrl . '/api/v1/escrow';

        $response = Http::withHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
        ])->asForm()->post($apiUrl, $payload);

        if ($response->failed()) {
            throw new KgInicisApiException('KG Inicis escrow delivery API error: HTTP ' . $response->status());
        }

        // 응답: URL-encoded 문자열 또는 JSON 모두 처리
        $body = $response->body();
        $result = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            parse_str($body, $result);
        }

        return $result ?: [];
    }

    /**
     * 에스크로 구매거절확인 API 호출 (INIAPI v1 /api/v1/escrow, type=Dncf)
     *
     * 구매자가 구매거절 선택 후 판매자(관리자)가 거절을 확인하는 절차.
     * hash: SHA-512(key + type + timestamp + clientIp + mid + originalTid)
     *
     * @param array $data { originalTid: string, dcnfName: string }
     * @return array PG 응답 (resultCode '00' = 성공)
     * @throws \Exception
     */
    public function denyConfirmEscrow(array $data): array
    {
        $type      = 'Dncf';
        $timestamp = date('YmdHis');
        $clientIp  = request()->ip() ?? '127.0.0.1';

        $plainText = $this->inapiKey . $type . $timestamp . $clientIp
            . $this->mid . $data['originalTid'];
        $hashData = hash('sha512', $plainText);

        $payload = [
            'type'        => $type,
            'mid'         => $this->mid,
            'clientIp'    => $clientIp,
            'timestamp'   => $timestamp,
            'originalTid' => $data['originalTid'],
            'dcnfName'    => $data['dcnfName'] ?? '관리자',
            'hashData'    => $hashData,
        ];

        $baseUrl = $this->isTest ? self::API_BASE_URL_TEST : self::API_BASE_URL_LIVE;

        $response = Http::withHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
        ])->asForm()->post($baseUrl . '/api/v1/escrow', $payload);

        if ($response->failed()) {
            throw new KgInicisApiException('KG Inicis escrow deny confirm API error: HTTP ' . $response->status());
        }

        $body   = $response->body();
        $result = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            parse_str($body, $result);
        }

        return $result ?: [];
    }

    /**
     * 현금영수증 별도발행 API 호출 (INIAPI v2)
     *
     * 메뉴얼: https://manual.inicis.com/pay/etc-receipt.html
     *
     * @param array $data {
     *   issueType:   '0'=소득공제(소비자용), '1'=지출증빙(사업자용)
     *   issueNumber: 휴대폰번호 / 주민번호 / 사업자번호 (평문 — 내부에서 AES-128-CBC 암호화)
     *   price:       총 결제금액 (int)
     *   supplyPrice: 공급가액 (int)
     *   tax:         부가세 (int)
     *   goodName:    상품명
     *   buyerName:   구매자명
     *   buyerEmail:  구매자 이메일
     *   buyerTel:    구매자 전화번호
     * }
     * @return array PG 응답 (resultCode '00' = 성공)
     * @throws \Exception
     */
    public function issueCashReceipt(array $data): array
    {
        $type = 'receipt';
        $timestamp = date('YmdHis');
        $clientIp = request()->ip() ?? '127.0.0.1';

        // issueNumber: AES-128-CBC(PKCS7) 암호화 후 base64 인코딩
        $encrypted = openssl_encrypt(
            $data['issueNumber'],
            'aes-128-cbc',
            $this->inapiKey,
            OPENSSL_RAW_DATA,
            $this->inapiIv
        );

        if ($encrypted === false) {
            throw new KgInicisApiException('KG Inicis cash receipt: issueNumber encryption failed');
        }

        $encIssueNumber = base64_encode($encrypted);

        $detail = [
            'price'        => (string) $data['price'],
            'supplyPrice'  => (string) $data['supplyPrice'],
            'tax'          => (string) $data['tax'],
            'servicePrice' => '0',
            'issueType'    => $data['issueType'],
            'issueNumber'  => $encIssueNumber,
            'goodName'     => $data['goodName'],
            'buyerName'    => $data['buyerName'],
            'buyerEmail'   => $data['buyerEmail'] ?? '',
            'buyerTel'     => $data['buyerTel'] ?? '',
            'currency'     => 'WON',
        ];

        $detailJson = str_replace('\\/', '/', json_encode($detail, JSON_UNESCAPED_UNICODE));
        $hashData = hash('sha512', $this->inapiKey . $this->mid . $type . $timestamp . $detailJson);

        $baseUrl = $this->isTest ? self::API_BASE_URL_TEST : self::API_BASE_URL_LIVE;
        $apiUrl = $baseUrl . '/v2/pg/receipt';

        $payload = [
            'mid'       => $this->mid,
            'type'      => $type,
            'timestamp' => $timestamp,
            'clientIp'  => $clientIp,
            'data'      => $detail,
            'hashData'  => $hashData,
        ];

        $response = Http::withHeaders(['Content-Type' => 'application/json;charset=utf-8'])
            ->post($apiUrl, $payload);

        if ($response->failed()) {
            throw new KgInicisApiException('KG Inicis cash receipt API error: HTTP ' . $response->status());
        }

        return $response->json() ?? [];
    }

    private function buildLiveMid(string $suffix): string
    {
        if ($suffix === '') {
            return '';
        }

        return str_starts_with($suffix, self::LIVE_MID_PREFIX) ? $suffix : self::LIVE_MID_PREFIX . $suffix;
    }
}
