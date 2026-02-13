<?php

declare(strict_types=1);

namespace Efiskalizacija\DTO;

final class TenantStatus
{
    public function __construct(
        public readonly string $naziv,
        public readonly string $pib,
        public readonly string $environment,
        public readonly int $currentMonthInvoices,
        public readonly int $maxInvoicesPerMonth,
        public readonly int $totalInvoices,
        public readonly int $thisMonth,
        public readonly float $successRate,
        public readonly string $version,
    ) {
    }

    /**
     * @param array<string, mixed> $data API response "data" objekat
     */
    public static function fromArray(array $data): self
    {
        $tenant = $data['tenant'] ?? [];
        $stats = $data['statistics'] ?? [];
        $system = $data['system'] ?? [];

        return new self(
            naziv: $tenant['naziv'] ?? '',
            pib: $tenant['pib'] ?? '',
            environment: $tenant['environment'] ?? '',
            currentMonthInvoices: (int) ($tenant['current_month_invoices'] ?? 0),
            maxInvoicesPerMonth: (int) ($tenant['max_invoices_per_month'] ?? 0),
            totalInvoices: (int) ($stats['total_invoices'] ?? 0),
            thisMonth: (int) ($stats['this_month'] ?? 0),
            successRate: (float) ($stats['success_rate'] ?? 0),
            version: $system['version'] ?? '',
        );
    }
}
