<?php

declare(strict_types=1);

return [
    'errors' => [
        'cash_receipt_invalid_issue_type' => 'Invalid cash receipt issue type.',
        'cash_receipt_missing_issue_number' => 'Please enter the identification number.',
        'cash_receipt_already_issued' => 'A cash receipt has already been issued for this payment.',
        'cash_receipt_issue_failed' => 'Failed to issue cash receipt.',
    ],
    'refund' => [
        'missing_tid' => 'Cannot process refund: transaction ID (TID) is missing.',
        'default_reason' => 'Buyer refund request',
    ],
    'defaults' => [
        'good_name' => 'Goods',
    ],
];
