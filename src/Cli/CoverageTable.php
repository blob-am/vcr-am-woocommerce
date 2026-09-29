<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Cli;

use BlobSolutions\WooCommerceVcrAm\Catalog\Coverage\Report;
use BlobSolutions\WooCommerceVcrAm\Catalog\Coverage\StoreSku;

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Turns a catalog {@see Report} into the rows `wp vcr check-catalog`
 * prints.
 *
 * Its own class because rendering is not the command's job, and because
 * the shape of these rows is a contract: scripts parse the `--format=json`
 * output, so the columns and the problem slugs are worth testing
 * independently of how the command narrates them.
 */
final class CoverageTable
{
    /** Column order, and the only place it is decided. */
    public const COLUMNS = ['problem', 'sku', 'product', 'id'];

    /**
     * One flat table, because every finding wants the same four columns and
     * a merchant reads the list top to bottom. Blockers come first, then
     * what could not be decided, then the informational tail.
     *
     * `problem` is a fixed slug and deliberately untranslated: it is the
     * column a script greps, so it has to mean the same thing in every
     * locale. The prose around the table is where the explaining happens.
     *
     * @return list<array{problem: string, sku: string, product: string, id: string}>
     */
    public function rows(Report $report): array
    {
        $rows = [];

        foreach ($report->withoutSku as $reference) {
            $rows[] = $this->row('no-sku', $reference);
        }

        foreach ($report->missing as $reference) {
            $rows[] = $this->row('no-offer', $reference);
        }

        foreach ($report->archived as $reference) {
            $rows[] = $this->row('archived-offer', $reference);
        }

        foreach ($report->unverified as $reference) {
            $rows[] = $this->row('unverified', $reference);
        }

        foreach ($report->orphanOffers as $externalId) {
            // An orphan is an offer, not a product: there is no local row
            // to name or link to, and the external id is the whole finding.
            $rows[] = [
                'problem' => 'orphan-offer',
                'sku' => $externalId,
                'product' => '',
                'id' => '',
            ];
        }

        return $rows;
    }

    /**
     * @return array{problem: string, sku: string, product: string, id: string}
     */
    private function row(string $problem, StoreSku $reference): array
    {
        return [
            'problem' => $problem,
            'sku' => $reference->sku,
            'product' => $reference->label,
            'id' => (string) ($reference->productId ?? ''),
        ];
    }
}
