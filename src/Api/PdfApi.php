<?php

declare(strict_types=1);

namespace Efiskalizacija\Api;

use Efiskalizacija\Exception\EfiskalizacijaException;

final class PdfApi extends AbstractApi
{
    /**
     * Preuzmi PDF fiskalnog racuna (binarni sadrzaj).
     *
     * @param string $pfrBroj PFR broj racuna
     * @param bool $download true = attachment, false = inline
     * @return string Binarni PDF sadrzaj
     *
     * @throws EfiskalizacijaException
     */
    public function download(string $pfrBroj, bool $download = true): string
    {
        $query = ['pfr' => $pfrBroj];
        if ($download) {
            $query['download'] = '1';
        }

        $response = $this->getRaw('/pdf', $query);

        return $response->body;
    }

    /**
     * Preuzmi i sacuvaj PDF u fajl.
     *
     * @throws EfiskalizacijaException
     */
    public function downloadToFile(string $pfrBroj, string $filePath): void
    {
        $content = $this->download($pfrBroj);

        $written = file_put_contents($filePath, $content);
        if ($written === false) {
            throw new EfiskalizacijaException(
                "Ne mogu da sacuvam PDF u: {$filePath}"
            );
        }
    }
}
