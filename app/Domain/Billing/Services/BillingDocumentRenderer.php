<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Currencies;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Phase B47 — Module 29 §75–76: invoice and credit note PDFs, drawn from the
 * stored document (its lines, amounts, tax and bill-to snapshot), never from
 * current prices — so a document printed again shows the same figures.
 *
 * Each document records the template version it was issued with
 * (document_version); a later template change applies to new documents.
 * Rendering is local (dompdf): no remote fonts, images or URLs are loaded.
 */
final class BillingDocumentRenderer
{
    public const TEMPLATE_VERSION = 1;

    public function __construct(private readonly ConfigService $config) {}

    public function invoice(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'payments']);

        return $this->pdf('billing.invoice-pdf', ['invoice' => $invoice, 'credits' => CreditNote::query()->withoutTenantScope()
            ->where('invoice_id', $invoice->id)->where('status', CreditNote::ISSUED)->orderBy('id')->get()]);
    }

    public function creditNote(CreditNote $note): string
    {
        return $this->pdf('billing.credit-note-pdf', ['note' => $note, 'invoice' => $note->invoice()->withoutGlobalScopes()->firstOrFail()]);
    }

    /** @param array<string, mixed> $data */
    private function pdf(string $view, array $data): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans'); // owner decision 14: sans-serif
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, [...$data,
            'issuer' => ['name' => (string) $this->config->get('billing.issuer_name'), 'details' => $this->config->get('billing.issuer_details')],
            'taxLabel' => (string) $this->config->get('billing.tax_label'),
            // Display only: every figure is stored and added up as an integer.
            'money' => fn (int $minor, string $currency) => $currency.' '.number_format((float) Currencies::amount($minor, $currency), Currencies::digits($currency)),
            'templateVersion' => self::TEMPLATE_VERSION,
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
