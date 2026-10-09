<?php

namespace Solosmartpay\Resources;

use Solosmartpay\Client;
use Solosmartpay\Models\PaymentMethod;

/** Methods enabled for your store: `GET /v1/payment-methods`. */
final class PaymentMethods
{
    public function __construct(private Client $client)
    {
    }

    /** @return list<PaymentMethod> */
    public function all(?string $group = null): array
    {
        $methods = array_map(
            fn (array $row) => PaymentMethod::fromArray($row),
            $this->client->request('GET', '/payment-methods'),
        );

        return array_values($group === null ? $methods : array_filter($methods, fn (PaymentMethod $m) => $m->group === $group));
    }

    public function find(string $code): ?PaymentMethod
    {
        foreach ($this->all() as $method) {
            if (strcasecmp($method->code, $code) === 0) {
                return $method;
            }
        }

        return null;
    }
}
