<?php

namespace App\Http\Controllers\Api\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\Servers\CustomerMarketplaceServer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Symfony\Component\HttpFoundation\Response;

class CustomerMcpController extends Controller
{
    public function handle(Request $request): Response
    {
        if (strtoupper($request->method()) === 'GET') {
            return $this->sse($request);
        }

        $sessionId = trim((string) $request->header('Mcp-Session-Id', ''));
        if ($sessionId === '') {
            $sessionId = (string) Str::uuid();
        }

        $transport = new HttpTransport($request);
        $server = app()->make(CustomerMarketplaceServer::class, [
            'transport' => $transport,
        ]);
        $server->start();

        $response = $transport->run();
        $response->headers->set('Mcp-Session-Id', $sessionId);

        return $response;
    }

    public function sse(Request $request): Response
    {
        $sessionId = trim((string) $request->header('Mcp-Session-Id', ''));
        if ($sessionId === '') {
            $sessionId = (string) Str::uuid();
        }

        $messageUrl = url('/api/mcp/customer');

        return response()->stream(function () use ($messageUrl): void {
            echo "event: endpoint\n";
            echo 'data: '.$messageUrl."\n\n";
            if (ob_get_level() !== 0) {
                ob_flush();
            }
            flush();

            $maxSeconds = app()->runningUnitTests() ? 0 : 25;
            $started = time();
            while ($maxSeconds > 0 && ! connection_aborted() && (time() - $started) < $maxSeconds) {
                echo ": keepalive\n\n";
                if (ob_get_level() !== 0) {
                    ob_flush();
                }
                flush();
                usleep(2_000_000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Mcp-Session-Id' => $sessionId,
        ]);
    }
}
