<?php

namespace App\Support;

/**
 * Public founder / blog-author identity. Companies House and invoices stay
 * on Teqno Ltd; this is the name shown on posts, About, and Person schema.
 */
class BrandPerson
{
    public const PUBLIC_NAME = 'M. Arslan';

    public const SLUG = 'm-arslan';

    /**
     * Leftover public bylines that must render as PUBLIC_NAME.
     *
     * @return list<string>
     */
    public static function founderAliases(): array
    {
        return [
            'Arslan Jason',
            'Arslan',
            'Jason Arslan',
            'M Arslan',
            'M.Arslan',
        ];
    }

    public static function publicName(): string
    {
        $fromConfig = trim((string) config('company.founder_name', ''));

        return $fromConfig !== '' ? $fromConfig : self::PUBLIC_NAME;
    }

    public static function slug(): string
    {
        $fromConfig = trim((string) config('company.founder_slug', ''));

        return $fromConfig !== '' ? $fromConfig : self::SLUG;
    }

    public static function url(): string
    {
        return url('/author/'.self::slug());
    }

    public static function isFounderAlias(?string $name): bool
    {
        $name = trim((string) $name);
        if ($name === '') {
            return true;
        }

        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $name) ?? $name);
        $public = mb_strtolower(self::publicName());
        if ($normalized === $public) {
            return true;
        }

        foreach (self::founderAliases() as $alias) {
            if ($normalized === mb_strtolower($alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Name to print on a public post. Unknown third-party bylines stay as stored.
     */
    public static function displayName(?string $stored): string
    {
        if (self::isFounderAlias($stored)) {
            return self::publicName();
        }

        $stored = trim((string) $stored);

        return $stored !== '' ? $stored : self::publicName();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function schema(array $extra = []): array
    {
        $orgId = class_exists(BrandOrganization::class) && method_exists(BrandOrganization::class, 'organizationId')
            ? BrandOrganization::organizationId()
            : rtrim((string) url('/'), '/').'/#organization';

        return array_merge([
            '@type' => 'Person',
            '@id' => self::url().'#person',
            'name' => self::publicName(),
            'url' => self::url(),
            'jobTitle' => 'Founder',
            'worksFor' => [
                '@type' => 'Organization',
                '@id' => $orgId,
                'name' => 'SEOLinkBuildings',
            ],
        ], $extra);
    }

    /**
     * Compact Person node for BlogPosting.author / Organization.founder.
     *
     * @return array<string, mixed>
     */
    public static function citation(): array
    {
        return [
            '@type' => 'Person',
            '@id' => self::url().'#person',
            'name' => self::publicName(),
            'url' => self::url(),
        ];
    }
}
