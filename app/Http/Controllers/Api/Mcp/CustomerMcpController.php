<?php

namespace App\Http\Controllers\Api\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\Servers\CustomerMarketplaceServer;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Symfony\Component\HttpFoundation\Response;

class CustomerMcpController extends Controller
{
    public function handle(Request $request): Response
    {
        $transport = new HttpTransport($request);
        $server = app()->make(CustomerMarketplaceServer::class, [
            'transport' => $transport,
        ]);
        $server->start();

        $response = $transport->run();

        // Stateless: never mint a session. Echo one only if the client sent it
        // so a reconnect after an older deploy does not look like a reset.
        $sessionId = trim((string) $request->header('Mcp-Session-Id', ''));
        if ($sessionId !== '') {
            $response->headers->set('Mcp-Session-Id', $sessionId);
        }

        return $response;
    }

    public function rejectListen(): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32600,
                'message' => 'This MCP endpoint is stateless Streamable HTTP. Use POST. SSE clients should call /api/mcp/customer/sse.',
            ],
            'id' => null,
        ], 405, [
            'Allow' => 'POST, OPTIONS',
        ]);
    }

    public function sse(Request $request): Response
    {
        $sessionId = trim((string) $request->header('Mcp-Session-Id', ''));
        $messageUrl = url('/api/mcp/customer');

        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
        if ($sessionId !== '') {
            $headers['Mcp-Session-Id'] = $sessionId;
        }

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
        }, 200, $headers);
    }
}
