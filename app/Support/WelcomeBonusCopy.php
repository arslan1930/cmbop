<?php

namespace App\Support;

use App\Services\Wallet\WelcomeBonusService;

class WelcomeBonusCopy
{
    /**
     * Live public offer. Leftover or a throw is not a grant.
     *
     * @return array{can_grant: bool, amount: float, euro: string}
     */
    public static function offer(): array
    {
        try {
            $bonus = app(WelcomeBonusService::class);
            if (! $bonus->canGrant()) {
                return ['can_grant' => false, 'amount' => 0.0, 'euro' => ''];
            }

            $amount = $bonus->amount();

            return [
                'can_grant' => true,
                'amount' => $amount,
                'euro' => $bonus->formatEuro($amount),
            ];
        } catch (\Throwable) {
            return ['can_grant' => false, 'amount' => 0.0, 'euro' => ''];
        }
    }

    public static function canGrant(): bool
    {
        return self::offer()['can_grant'];
    }

    public static function euro(): string
    {
        return self::offer()['euro'];
    }

    public static function mentionsOfferAmount(string $text): bool
    {
        return str_contains($text, '€20') || str_contains($text, '20 €');
    }

    public static function replaceAmount(string $text, string $euro): string
    {
        return str_replace(['€20', '20 €'], $euro, $text);
    }

    /**
     * When grants are live, swap the hardcoded €20 / 20 € token for the admin amount.
     * When they are not, use the _off key if the on-copy still advertises a grant.
     */
    public static function message(string $key, ?string $offKey = null): string
    {
        $on = (string) __('messages.'.$key);
        $offer = self::offer();

        if ($offer['can_grant'] && $offer['euro'] !== '') {
            return self::replaceAmount($on, $offer['euro']);
        }

        if ($offKey && self::mentionsOfferAmount($on) && trans()->has('messages.'.$offKey)) {
            return (string) __('messages.'.$offKey);
        }

        return $on;
    }

    /**
     * Strip leftover “new advertisers receive welcome credit” promises from
     * curated blog HTML still sitting in the DB after Disable.
     */
    public static function scrubGrantAdvertisingHtml(string $html): string
    {
        if ($html === '' || self::canGrant()) {
            return $html;
        }

        $replacements = [
            'New advertisers receive a welcome credit that can be used toward placements under the platform rules.' => 'When a welcome promotion is active, new advertisers may receive spend-only credit toward placements under the platform rules.',
            'New advertisers receive a welcome wallet credit under the current signup rules; treat it as purchasing power for placements, not a cash withdrawal.' => 'When a welcome promotion is active, treat any credited amount as purchasing power for placements, not a cash withdrawal.',
            'New advertisers often see a welcome credit. Treat it as purchasing power for placements under the current rules. It is not a cash gift you can withdraw.' => 'When a welcome promotion is active, treat any credited amount as purchasing power for placements under the current rules. It is not a cash gift you can withdraw.',
            'Neue Advertiser erhalten ein Willkommensguthaben, das unter den Plattformregeln für Platzierungen nutzbar ist.' => 'Wenn eine Willkommensaktion aktiv ist, können neue Advertiser ein nur ausgebbares Guthaben für Platzierungen unter den Plattformregeln erhalten.',
            'Neue Advertiser erhalten ein Willkommensguthaben nach den aktuellen Signup-Regeln; behandeln Sie es als Kaufkraft für Platzierungen, nicht als auszahlbares Bargeld.' => 'Wenn eine Willkommensaktion aktiv ist, behandeln Sie gutgeschriebenes Guthaben als Kaufkraft für Platzierungen, nicht als auszahlbares Bargeld.',
            'Les nouveaux annonceurs reçoivent aussi un crédit de bienvenue utilisable sous les règles de la plateforme.' => 'Lorsqu’une offre de bienvenue est active, un crédit dépensable uniquement peut s’appliquer sous les règles de la plateforme.',
            'Les nouveaux comptes reçoivent un crédit de bienvenue selon les règles en vigueur. Ce n’est pas un retrait cash : c’est du pouvoir d’achat pour des placements.' => 'Lorsqu’une offre de bienvenue est active, tout crédit accordé reste du pouvoir d’achat pour des placements — pas un retrait cash.',
            'Nieuwe adverteerders krijgen ook welkomstkrediet onder de platformregels.' => 'Als een welkomstactie actief is, kan besteedbaar welkomstkrediet gelden onder de platformregels.',
            'Nieuwe adverteerders krijgen welkomstkrediet volgens de actuele signup-regels. Dat is koopkracht voor plaatsingen, geen cash-opname.' => 'Als een welkomstactie actief is, is eventueel welkomstkrediet koopkracht voor plaatsingen, geen cash-opname.',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    /**
     * Rewrite the grant line in the llms.txt template so crawlers
     * do not see €20 after Disable or an amount change.
     */
    public static function applyToLlmsTxt(string $body): string
    {
        $grantLine = '- New advertisers: €20 welcome credit for first orders (spend-only, not withdrawable).';
        $offLine = '- New advertisers: promotional welcome credit is spend-only when granted; it is not always offered.';
        $offer = self::offer();
        $line = ($offer['can_grant'] && $offer['euro'] !== '')
            ? '- New advertisers: '.$offer['euro'].' welcome credit for first orders (spend-only, not withdrawable).'
            : $offLine;

        if (str_contains($body, $grantLine) || str_contains($body, $offLine)) {
            return str_replace([$grantLine, $offLine], $line, $body);
        }

        return $body;
    }
}
