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
        /**
         * Nalog nema mesečni limit računa: server šalje
         * `tenant.max_invoices_per_month: null`. Tada je `maxInvoicesPerMonth`
         * 0 (kao do 1.1.1), pa ga čitajte tek kad je ovo `false`. Od 1.2.0.
         */
        public readonly bool $unlimitedInvoices = false,
    ) {
    }

    /**
     * Objekat sačuvan kroz `serialize()` sa 1.1.1 ili starijom verzijom nema
     * `unlimitedInvoices`; posle `unserialize()` je `false` (kao kod objekta
     * napravljenog konstruktorom bez tog argumenta), a sačuvana svojstva
     * zadržavaju vrednost.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->naziv = $data['naziv'] ?? '';
        $this->pib = $data['pib'] ?? '';
        $this->environment = $data['environment'] ?? '';
        $this->currentMonthInvoices = $data['currentMonthInvoices'] ?? 0;
        $this->maxInvoicesPerMonth = $data['maxInvoicesPerMonth'] ?? 0;
        $this->totalInvoices = $data['totalInvoices'] ?? 0;
        $this->thisMonth = $data['thisMonth'] ?? 0;
        $this->successRate = $data['successRate'] ?? 0.0;
        $this->version = $data['version'] ?? '';
        $this->unlimitedInvoices = $data['unlimitedInvoices'] ?? false;
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
            unlimitedInvoices: is_array($tenant)
                && array_key_exists('max_invoices_per_month', $tenant)
                && $tenant['max_invoices_per_month'] === null,
        );
    }
}
