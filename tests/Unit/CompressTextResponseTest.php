<?php

namespace Tests\Unit;

use App\Http\Middleware\CompressTextResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class CompressTextResponseTest extends TestCase
{
    public function test_gzips_html_when_the_client_asks(): void
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_ACCEPT_ENCODING' => 'gzip',
            'HTTP_X_TEST_COMPRESS' => '1',
        ]);
        $html = str_repeat('<p>catalog row</p>', 80);
        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $out = (new CompressTextResponse)->handle($request, static fn () => $response);

        $this->assertSame('gzip', $out->headers->get('Content-Encoding'));
        $this->assertStringContainsString('Accept-Encoding', (string) $out->headers->get('Vary'));
        $decoded = gzdecode($out->getContent());
        $this->assertSame($html, $decoded);
        $this->assertLessThan(strlen($html), strlen($out->getContent()));
    }

    public function test_leaves_html_alone_without_accept_encoding(): void
    {
        $request = Request::create('/', 'GET');
        $html = str_repeat('<p>catalog row</p>', 80);
        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $out = (new CompressTextResponse)->handle($request, static fn () => $response);

        $this->assertNull($out->headers->get('Content-Encoding'));
        $this->assertSame($html, $out->getContent());
    }
}
