<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\PayKginicis\Services\CbtCvsOperationsService;

class AdminCbtCvsOperationsController extends AdminBaseController
{
    public function __construct(
        private readonly CbtCvsOperationsService $operationsService,
    ) {
        parent::__construct();
    }

    public function show(string $orderNumber): JsonResponse
    {
        $summary = $this->operationsService->summary($orderNumber);
        if ($summary === null) {
            return ResponseHelper::pluginError(
                'sirsoft-pay_kginicis',
                'messages.errors.order_not_found',
                404,
            );
        }

        return ResponseHelper::success('messages.success', $summary);
    }

    public function simulateNotify(Request $request, string $orderNumber): JsonResponse
    {
        $result = $this->operationsService->simulatePaidNotify($orderNumber, $request->ip());

        if (! ($result['ok'] ?? false)) {
            return $this->operationError($result);
        }

        return ResponseHelper::pluginSuccess(
            'sirsoft-pay_kginicis',
            'messages.cbt_cvs.simulate_success',
            $result['summary'] ?? null,
        );
    }

    public function expire(string $orderNumber): JsonResponse
    {
        $result = $this->operationsService->expireOverdue($orderNumber);

        if (! ($result['ok'] ?? false)) {
            return $this->operationError($result);
        }

        return ResponseHelper::pluginSuccess(
            'sirsoft-pay_kginicis',
            'messages.cbt_cvs.expire_success',
            $result['summary'] ?? null,
        );
    }

    public function recheck(string $orderNumber): JsonResponse
    {
        $result = $this->operationsService->markRechecked($orderNumber);

        if (! ($result['ok'] ?? false)) {
            return $this->operationError($result);
        }

        return ResponseHelper::pluginSuccess(
            'sirsoft-pay_kginicis',
            'messages.cbt_cvs.recheck_success',
            $result['summary'] ?? null,
        );
    }

    private function operationError(array $result): JsonResponse
    {
        return ResponseHelper::pluginError(
            'sirsoft-pay_kginicis',
            (string) ($result['message_key'] ?? 'messages.errors.cbt_failed'),
            (int) ($result['status'] ?? 422),
        );
    }
}
