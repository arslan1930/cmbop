<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Support\InvoiceLinePresenter;
use Tests\TestCase;

class InvoiceLinePresenterTest extends TestCase
{
    public function test_website_label_prefers_site_name_and_domain_over_publisher_column(): void
    {
        $this->assertSame(
            'Inka Magazin (inkamagazin.example)',
            InvoiceLinePresenter::websiteLabel([
                'site_name' => 'Inka Magazin',
                'site_url' => 'https://inkamagazin.example',
                'publisher_website' => 'josen J',
            ])
        );
    }

    public function test_legacy_publisher_website_url_still_maps_to_domain(): void
    {
        $this->assertSame(
            'inkamagazin.example',
            InvoiceLinePresenter::websiteLabel([
                'publisher_website' => 'https://inkamagazin.example/old-path',
            ])
        );
    }

    public function test_live_url_requires_http_and_ignores_unsafe_values(): void
    {
        $this->assertSame(
            'https://inkamagazin.example/post',
            InvoiceLinePresenter::liveUrl(['live_url' => 'https://inkamagazin.example/post'])
        );
        $this->assertNull(InvoiceLinePresenter::liveUrl(['live_url' => 'javascript:alert(1)']));
        $this->assertNull(InvoiceLinePresenter::liveUrl([]));
    }

    public function test_checkout_reference_is_omitted_when_it_duplicates_the_order_number(): void
    {
        $invoice = new Invoice([
            'type' => Invoice::TYPE_TAX_INVOICE,
            'reference_code' => 'ORD-INV-29',
            'order_number' => 'ORD-INV-29',
        ]);

        $this->assertNull(InvoiceLinePresenter::checkoutReference($invoice));
    }
}
