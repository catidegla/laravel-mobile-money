<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Exceptions;

final class ProviderException extends MobileMoneyException
{
    public static function rejected(string $provider, int $status, string $body): self
    {
        return (new self(sprintf('%s rejected the request with HTTP %d: %s', $provider, $status, $body)))
            ->withContext(['provider' => $provider, 'http_status' => $status]);
    }

    public static function authentication(string $provider, int $status, string $body): self
    {
        return (new self(sprintf(
            '%s refused the credentials (HTTP %d): %s. Check the subscription key, API user and API key, '.
            'and that they belong to the environment you are targeting.',
            $provider,
            $status,
            $body,
        )))->withContext(['provider' => $provider, 'http_status' => $status]);
    }

    public static function notFound(string $provider, string $reference): self
    {
        return (new self(sprintf(
            '%s has no transaction with reference %s. If this payment was just initiated, the provider may not '.
            'have indexed it yet, so treat it as pending rather than lost.',
            $provider,
            $reference,
        )))->withContext(['provider' => $provider, 'reference' => $reference, 'kind' => 'not_found']);
    }

    /**
     * Whether the provider said it has never heard of this reference.
     *
     * Worth telling apart from every other provider failure, because it is the
     * only one that might mean the request never arrived rather than that the
     * answer is temporarily unavailable. Might: whether a given provider
     * returns 404 for a reference it never received, as opposed to one it has
     * simply not indexed yet, is not something this package has verified
     * against any live sandbox. So the distinction is surfaced and nothing is
     * concluded from it.
     */
    public function isNotFound(): bool
    {
        return ($this->context['kind'] ?? null) === 'not_found';
    }

    public static function unsupported(string $provider, string $currency, string $country): self
    {
        return (new self(sprintf(
            '%s is not configured for %s in %s. Add the currency and country to its entry in config/mobile-money.php, '.
            'or route this payment to a provider that covers that market.',
            $provider,
            $currency,
            $country,
        )))->withContext(['provider' => $provider, 'currency' => $currency, 'country' => $country]);
    }

    public static function unknownDriver(string $name, array $available): self
    {
        return new self(sprintf(
            'No mobile money driver named "%s". Configured drivers: %s.',
            $name,
            $available === [] ? 'none' : implode(', ', $available),
        ));
    }

    public static function signatureMismatch(string $provider): self
    {
        return new self(sprintf('The %s webhook signature did not verify.', $provider));
    }
}
