<?php

namespace Database\Seeders;

use App\Models\Blog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->first();
        $author = $adminRole
            ? User::whereHas('roles', fn ($q) => $q->where('roles.id', $adminRole->id))->first()
            : null;
        $author = $author ?? User::query()->first();

        $posts = [
            [
                'title' => 'How to Build High-Quality Backlinks in 2026',
                'excerpt' => 'A practical guide to earning editorial links that improve rankings without risky shortcuts.',
                'tags' => ['seo', 'link building', 'backlinks'],
                'content' => <<<'HTML'
<p>High-quality backlinks still move the needle for organic growth. The difference in 2026 is that search engines reward relevance, trust, and natural placement more than raw volume.</p>
<p>Start with a clear niche, then pursue placements on sites that already cover related topics. Marketplace catalogs make it easier to filter by country, language, domain authority, and topical fit.</p>
<p>Focus on editorial guest posts, digital PR stories, and contextual insertions. Avoid PBNs and abrupt anchor-text patterns. Measure referral traffic and ranking movement after each campaign.</p>
HTML,
            ],
            [
                'title' => 'Digital PR Ideas That Earn Coverage and Links',
                'excerpt' => 'Campaign concepts publishers and journalists are more likely to pick up.',
                'tags' => ['digital pr', 'content', 'outreach'],
                'content' => <<<'HTML'
<p>Digital PR works when your story is useful, timely, or genuinely newsworthy. Data studies, expert commentary, and industry benchmarks often outperform generic product pitches.</p>
<p>Pair each idea with a distribution plan: target publications, outreach angles, and the link destinations you want to strengthen. Keep claims accurate and cite sources.</p>
<p>After coverage goes live, track referring domains, brand mentions, and assisted conversions so you can double down on formats that work.</p>
HTML,
            ],
            [
                'title' => 'Guest Posting Checklist for Advertisers',
                'excerpt' => 'What to prepare before ordering a placement so publishers can deliver faster.',
                'tags' => ['guest posting', 'advertisers', 'marketplace'],
                'content' => <<<'HTML'
<p>Successful guest posts start with clear briefs. Define your target audience, preferred anchor text, competitor examples, and any claims that need supporting evidence.</p>
<p>Upload clean content, confirm the live URL requirements, and communicate turnaround expectations inside the order thread. Quality writing and natural linking still convert best.</p>
<p>After publication, verify indexation, check the link attributes, and add the placement to your content calendar for future refreshes.</p>
HTML,
            ],
            [
                'title' => 'Choosing Publishers by Country and Language',
                'excerpt' => 'How multilingual markets change link value and content strategy.',
                'tags' => ['international seo', 'localization'],
                'content' => <<<'HTML'
<p>Country and language targeting matter as much as domain metrics. A relevant publisher in your market often outperforms a higher-DR site with no topical or geographic overlap.</p>
<p>Use marketplace filters for English-speaking regions, EU markets, Chinese markets, and Gulf countries when your campaigns need regional coverage. Match content language to the audience you want to rank for.</p>
<p>Local intent, currency mentions, and culturally accurate examples help placements feel native and earn better engagement.</p>
HTML,
            ],
        ];

        foreach ($posts as $index => $post) {
            $slug = Str::slug($post['title']);

            Blog::updateOrCreate(
                ['slug' => $slug],
                [
                    'title' => $post['title'],
                    'excerpt' => $post['excerpt'],
                    'content' => $post['content'],
                    'featured_image' => null,
                    'author' => $author?->name ?? 'SEOLinkBuildings',
                    'tags' => $post['tags'],
                    'status' => 'published',
                    'published_at' => now()->subDays($index + 1),
                    'created_by' => $author?->id,
                    'updated_by' => $author?->id,
                ]
            );
        }
    }
}
