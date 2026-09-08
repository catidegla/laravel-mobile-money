<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney;

use Catidegla\MobileMoney\Contracts\Provider;
use Catidegla\MobileMoney\Data\CollectionRequest;
use Catidegla\MobileMoney\Data\Transaction;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Exceptions\ProviderException;
use Catidegla\MobileMoney\Providers\MtnMomoProvider;
use Catidegla\MobileMoney\Providers\WaveProvider;
use Closure;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves and caches provider drivers, and routes payments between them.
 *
 * Routing is the part worth having. In this region a merchant deals with three
 * or four networks at once and the right one depends on the customer's number,
 * so a package that makes you pick the driver by hand has left the hard part
 * to the caller.
 */
class MobileMoneyManager
{
    /** @var array<string, Provider> */
    private array $drivers = [];

    /** @var array<string, Closure(array, Application): Provider> */
    private array $custom = [];

    public function __construct(private readonly Application $app) {}

    /** Register a driver this package does not ship. */
    public function extend(string $name, Closure $factory): self
    {
        $this->custom[$name] = $factory;

        return $this;
    }

    public function driver(?string $name = null): Provider
    {
        $name ??= $this->config('default');

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    private function resolve(string $name): Provider
    {
        $config = $this->config("providers.{$name}");

        if (! is_array($config)) {
            throw ProviderException::unknownDriver($name, array_keys($this->config('providers') ?? []));
        }

        if (isset($this->custom[$name])) {
            return ($this->custom[$name])($config, $this->app);
        }

        return match ($config['driver'] ?? $name) {
            'mtn_momo' => new MtnMomoProvider($config),
            'wave' => new WaveProvider($config),
            default => throw ProviderException::unknownDriver($name, array_keys($this->config('providers') ?? [])),
        };
    }

    /**
     * Every configured driver that can move this currency in this country.
     *
     * @return array<string, Provider>
     */
    public function availableFor(Currency $currency, string $country): array
    {
        $matches = [];

        foreach (array_keys($this->config('providers') ?? []) as $name) {
            $driver = $this->driver($name);

            if ($driver->supports($currency, $country)) {
                $matches[$name] = $driver;
            }
        }

        return $matches;
    }

    /**
     * Pick a driver for a payment without being told which one.
     *
     * Order of preference:
     *   1. The operator the numbering plan identifies, where it is unambiguous.
     *   2. The configured priority list for that country.
     *   3. Any driver that supports the market.
     */
    public function routeFor(CollectionRequest $request): Provider
    {
        $currency = $request->amount->currency;
        $country = $request->payer->country;
        $candidates = $this->availableFor($currency, $country);

        if ($candidates === []) {
            throw ProviderException::unsupported('any configured provider', $currency->value, $country);
        }

        if ($hint = $request->payer->operatorHint()) {
            foreach ($candidates as $name => $driver) {
                if (str_contains($name, $hint) || str_contains($driver->name(), $hint)) {
                    return $driver;
                }
            }
        }

        foreach ($this->config("routing.{$country}") ?? [] as $preferred) {
            if (isset($candidates[$preferred])) {
                return $candidates[$preferred];
            }
        }

        return reset($candidates);
    }

    /** Collect, choosing the driver from the payer's number. */
    public function collect(CollectionRequest $request, ?string $using = null): Transaction
    {
        $driver = $using !== null ? $this->driver($using) : $this->routeFor($request);

        return $driver->collect($request);
    }

    public function status(string $reference, string $using): Transaction
    {
        return $this->driver($using)->status($reference);
    }

    private function config(string $key): mixed
    {
        return $this->app['config']->get("mobile-money.{$key}");
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->driver()->{$method}(...$arguments);
    }
}
