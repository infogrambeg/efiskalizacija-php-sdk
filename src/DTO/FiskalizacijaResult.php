<?php

declare(strict_types=1);

namespace Efiskalizacija\DTO;

final class FiskalizacijaResult
{
    public function __construct(
        public readonly string $pfrBroj,
        public readonly ?string $qrCode,
        public readonly ?int $racunId,
        public readonly ?string $requestId,
        public readonly ?string $poruka = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data API response "rezultat" objekat
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pfrBroj: $data['pfr_broj'] ?? '',
            qrCode: $data['qr_code'] ?? null,
            racunId: isset($data['racun_id']) ? (int) $data['racun_id'] : null,
            requestId: $data['request_id'] ?? null,
            poruka: $data['poruka'] ?? null,
        );
    }
}
