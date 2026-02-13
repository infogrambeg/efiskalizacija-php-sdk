<?php

declare(strict_types=1);

namespace Efiskalizacija\Webhook;

final class WebhookPayload
{
    public function __construct(
        public readonly string $event,
        public readonly ?string $pfrBroj,
        public readonly ?int $racunId,
        public readonly ?float $iznos,
        public readonly ?string $status,
        /** @var array<string, mixed> */
        public readonly array $rawData,
    ) {
    }

    /**
     * Parsiraj webhook payload iz JSON stringa.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Neispravan webhook JSON payload');
        }

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            event: $data['event'] ?? '',
            pfrBroj: $data['pfr_broj'] ?? null,
            racunId: isset($data['racun_id']) ? (int) $data['racun_id'] : null,
            iznos: isset($data['iznos']) ? (float) $data['iznos'] : null,
            status: $data['status'] ?? null,
            rawData: $data,
        );
    }

    public function isFiscalized(): bool
    {
        return $this->event === 'invoice.fiscalized';
    }

    public function isFailed(): bool
    {
        return $this->event === 'invoice.failed';
    }
}
