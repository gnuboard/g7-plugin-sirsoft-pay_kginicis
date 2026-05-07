<?php

declare(strict_types=1);

return [
    'errors' => [
        'cash_receipt_invalid_issue_type' => '발행 유형이 올바르지 않습니다.',
        'cash_receipt_missing_issue_number' => '식별번호를 입력해주세요.',
        'cash_receipt_already_issued' => '이미 현금영수증이 발행된 결제입니다.',
        'cash_receipt_issue_failed' => '현금영수증 발행에 실패했습니다.',
    ],
    'refund' => [
        'missing_tid' => '거래 ID(TID)가 없어 환불을 진행할 수 없습니다.',
        'default_reason' => '구매자 환불 요청',
    ],
    'defaults' => [
        'good_name' => '상품',
    ],
];
