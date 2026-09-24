<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Server-side visitor-chat provider. The browser never sees API keys.
 */
class VisitorSupportChatService
{
    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{ok: bool, reply?: string, message?: string}
     */
    public function reply(string $message, array $history = []): array
    {
        $provider = strtolower(trim((string) config('services.support_chat.provider', 'local')));

        try {
            $reply = match ($provider) {
                'openai' => $this->viaOpenAi($message, $history),
                'http' => $this->viaHttp($message, $history),
                default => $this->localReply($message),
            };
        } catch (\Throwable $e) {
            Log::warning('visitor-support-chat.failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'We could not reach support just now. Please try again in a moment.',
            ];
        }

        $reply = trim((string) $reply);
        if ($reply === '') {
            return [
                'ok' => false,
                'message' => 'Support did not return a reply. Please try again.',
            ];
        }

        return ['ok' => true, 'reply' => $reply];
    }

    private function localReply(string $message): string
    {
        $company = trim((string) config('app.name', 'SEOLinkBuildings'));
        $user = auth()->user();
        $name = trim((string) ($user->name ?? ''));
        $email = trim((string) ($user->email ?? ''));
        $hello = $name !== '' ? 'Thanks, '.$name.'.' : 'Thanks for writing in.';

        $this->notifySupport($message, $name, $email);

        return $hello.' A teammate at '.$company.' will follow up here. '
            .'You can also use Contact if you need billing or account help.';
    }

    private function notifySupport(string $message, string $name, string $email): void
    {
        $to = trim((string) config('email_notifications.brand.support_email', ''));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $who = $name !== '' ? $name : 'Visitor';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $who .= ' <'.$email.'>';
        }

        $from = trim((string) (config('email_notifications.brand.sender_email') ?: config('mail.from.address')));
        $fromName = trim((string) (config('email_notifications.brand.sender_name') ?: config('mail.from.name')));

        try {
            Mail::raw(
                "Support chat message from {$who}\n\n{$message}",
                function ($mail) use ($to, $from, $fromName, $name, $email): void {
                    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
                        $mail->from($from, $fromName !== '' ? $fromName : null);
                    }
                    $mail->to($to)->subject('Support chat message');
                    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $mail->replyTo($email, $name !== '' ? $name : null);
                    }
                }
            );
        } catch (\Throwable $e) {
            Log::warning('visitor-support-chat.notify-failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    private function viaHttp(string $message, array $history): string
    {
        $endpoint = trim((string) config('services.support_chat.endpoint', ''));
        if ($endpoint === '' || ! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('SUPPORT_CHAT_ENDPOINT is not a valid URL.');
        }

        $request = Http::timeout((int) config('services.support_chat.timeout', 12))
            ->acceptJson()
            ->asJson();

        $key = trim((string) config('services.support_chat.api_key', ''));
        if ($key !== '') {
            $request = $request->withToken($key);
        }

        $response = $request->post($endpoint, [
            'message' => $message,
            'history' => $history,
            'visitor' => $this->visitorPayload(),
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('HTTP provider returned HTTP '.$response->status());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException('HTTP provider returned an empty body.');
        }

        $reply = $json['reply'] ?? $json['message'] ?? $json['content'] ?? '';

        return is_string($reply) ? $reply : '';
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    private function viaOpenAi(string $message, array $history): string
    {
        $key = trim((string) config('services.support_chat.api_key', ''));
        if ($key === '') {
            throw new \RuntimeException('SUPPORT_CHAT_API_KEY is empty.');
        }

        $endpoint = trim((string) config('services.support_chat.endpoint', ''));
        if ($endpoint === '') {
            $endpoint = 'https://api.openai.com/v1/chat/completions';
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are customer support for '.config('app.name', 'SEOLinkBuildings')
                    .', a guest-post marketplace. Be concise, honest, and never invent order or payout details.',
            ],
        ];
        foreach ($history as $item) {
            $messages[] = [
                'role' => $item['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $item['content'],
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        $response = Http::timeout((int) config('services.support_chat.timeout', 12))
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->post($endpoint, [
                'model' => (string) config('services.support_chat.model', 'gpt-4o-mini'),
                'messages' => $messages,
                'temperature' => 0.4,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('OpenAI provider returned HTTP '.$response->status());
        }

        $reply = data_get($response->json(), 'choices.0.message.content', '');

        return is_string($reply) ? $reply : '';
    }

    /**
     * @return array{name: ?string, email: ?string}
     */
    private function visitorPayload(): array
    {
        $user = auth()->user();
        if (! $user) {
            return ['name' => null, 'email' => null];
        }

        $email = trim((string) ($user->email ?? ''));

        return [
            'name' => trim((string) ($user->name ?? '')) ?: null,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
        ];
    }
}
