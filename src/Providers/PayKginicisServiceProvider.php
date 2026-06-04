<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Sirsoft\PayKginicis\Repositories\CbtCvsOperationsRepository;
use Plugins\Sirsoft\PayKginicis\Repositories\CbtCvsOperationsRepositoryInterface;

class PayKginicisServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'sirsoft-pay_kginicis';

    /**
     * Repository 인터페이스 ↔ 구현체 매핑.
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        CbtCvsOperationsRepositoryInterface::class => CbtCvsOperationsRepository::class,
    ];
}
