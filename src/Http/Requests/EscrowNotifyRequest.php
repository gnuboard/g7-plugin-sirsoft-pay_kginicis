<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * KG 이니시스 에스크로 상태변경 통보 요청 검증
 *
 * POST /plugins/sirsoft-pay_kginicis/payment/escrow-notify
 * 공식 매뉴얼: https://manual.inicis.com/pay/etc-noti.html#es
 *
 * cl_status 코드:
 *   2  = 배송등록 (판매자 배송정보 등록)
 *   3  = 구매확인 (구매자 구매확인)
 *   31 = 자동구매확인
 *   32 = 강제구매확인
 *   4  = 구매거부 (구매자 거부)
 *   8  = 취소
 *   10 = 거부확인
 *
 * 응답으로 정확히 "cd_rslt=0000" (200, text/plain) 를 돌려줘야 합니다.
 * 10분 단위 배치 처리, 최대 10회 재시도.
 */
class EscrowNotifyRequest extends FormRequest
{
/**
 * authorize
 *
 * @return bool
 */
public function authorize(): bool
    {
        return true;
    }

/**

 * rules

 *

 * @return array

 */

    public function rules(): array
    {
        return [
            // 필수 식별자
            'id_merchant'  => ['required', 'string', 'max:10'],
            'no_oid'       => ['required', 'string', 'max:40'],
            'no_tid'       => ['required', 'string', 'max:40'],
            'dt_req'       => ['required', 'string', 'max:14'],

            // 상태 코드
            'cl_status'    => ['required', 'string', 'max:2'],
            'cl_paymethod' => ['required', 'string', 'max:2'],
            'price'        => ['required', 'string', 'max:12'],

            // 선택 필드
            'msg_deny'     => ['nullable', 'string', 'max:256'],
            'tid_org'      => ['nullable', 'string', 'max:40'],
        ];
    }
}
