<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Facades;

use Catidegla\MobileMoney\Contracts\Provider;
use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Transaction;
use Catidegla\MobileMoney\Enums\Currency;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Provider driver(string|null $name = null)
 * @method static Transaction collect(CollectionRequest $request, string|null $using = null)
 * @method static Transaction status(string $reference, string $using)
 * @method static Provider routeFor(CollectionRequest $request)
 * @method static array availableFor(Currency $currency, string $country)
 *
 * @see \Catidegla\MobileMoney\MobileMoneyManager
 */
class MobileMoney extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mobile-money';
    }
}
