<?php

namespace Tests\Support;

use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

trait CreatesContentSubmissions
{
    protected function makeDocxFile(string $absolutePath, string $text = 'This is a compliant marketing article about software tools and productivity tips for teams.'): void
    {
        $dir = dirname($absolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();
    }

    protected function createApprovedSubmission(
        User $user,
        ?int $siteId = null,
        int $copyIndex = 0,
        string $anchor = 'best software tools',
        string $target = 'https://example.com/tools',
        string $country = 'us',
        string $language = 'en',
    ): ContentSubmission {
        config(['content_moderation.enabled' => false]);

        $relative = 'content-uploads/'.$user->id.'/test-'.uniqid('', true).'-'.($siteId ?? 'lib').'-'.$copyIndex.'.docx';
        $absolute = Storage::disk('local')->path($relative);
        $this->makeDocxFile($absolute);
        // Ensure Laravel disk sees the file even if written via absolute path helpers.
        if (! Storage::disk('local')->exists($relative)) {
            Storage::disk('local')->put($relative, file_get_contents($absolute) ?: '');
        }

        return ContentSubmission::create([
            'user_id' => $user->id,
            'site_id' => $siteId,
            'copy_index' => $copyIndex,
            'original_filename' => 'article.docx',
            'title' => 'Test Article',
            'country' => strtolower($country),
            'language' => strtolower($language),
            'disk' => 'local',
            'path' => $relative,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => filesize($absolute) ?: 100,
            'extracted_text' => 'This is a compliant marketing article about software tools and productivity tips for teams working on digital projects worldwide.',
            'preview_html' => '<p>This is a compliant marketing article about software tools and productivity tips for teams working on digital projects worldwide.</p>',
            'word_count' => 20,
            'uniqueness_score' => 85,
            'quality_score' => 80,
            'evaluation_status' => 'approved',
            'evaluated_at' => now(),
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
            'anchor_text' => $anchor,
            'target_url' => $target,
            'publication_mode' => ContentSubmission::MODE_IMMEDIATE,
            'timezone' => 'UTC',
            'wizard_step' => 5,
            'expires_at' => now()->addMonths(6),
        ]);
    }

    /**
     * Fund the advertiser wallet so checkout can use payment_method=wallet.
     */
    protected function fundAdvertiserWallet(User $user, float $balance = 5000): Wallet
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        if (! $user->roles()->where('roles.id', $role->id)->exists()) {
            $user->roles()->attach($role->id);
        }
        if (! $user->active_role_id) {
            $user->active_role_id = $role->id;
            $user->save();
        }

        return Wallet::updateOrCreate(
            ['user_id' => $user->id, 'role_id' => $role->id],
            [
                'balance' => $balance,
                'reserved_balance' => 0,
                'bonus_balance' => 0,
                'bonus_reserved' => 0,
                'currency' => 'EUR',
            ]
        );
    }
}
