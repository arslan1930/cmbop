<?php

namespace Tests\Unit;

use App\Services\ContentUpload\DocumentTextExtractor;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class DocumentTextExtractorTest extends TestCase
{
    public function test_extracts_text_from_docx(): void
    {
        $path = sys_get_temp_dir().'/cmbop-extract-test.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello extraction world from docx file content.</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        $result = (new DocumentTextExtractor)->extract($path, 'docx');
        @unlink($path);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Hello extraction world', (string) $result['text']);
        $this->assertGreaterThan(3, $result['word_count']);
        $this->assertNotEmpty($result['html']);
    }

    public function test_rejects_empty_docx(): void
    {
        $path = sys_get_temp_dir().'/cmbop-empty.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body></w:body></w:document>');
        $zip->close();

        $result = (new DocumentTextExtractor)->extract($path, 'docx');
        @unlink($path);

        $this->assertFalse($result['ok']);
        $this->assertSame('empty_document', $result['error_code']);
    }

    public function test_extracts_hyperlink_anchor_and_url_from_docx(): void
    {
        $path = sys_get_temp_dir().'/cmbop-link-test.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" '
            .'Target="https://example.com/growth-tools" TargetMode="External"/>'
            .'</Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:body><w:p><w:r><w:t>Discover the best </w:t></w:r>'
            .'<w:hyperlink r:id="rId5"><w:r><w:t>growth marketing tools</w:t></w:r></w:hyperlink>'
            .'<w:r><w:t> for modern teams working on digital campaigns every week.</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();

        $result = (new DocumentTextExtractor)->extract($path, 'docx');
        @unlink($path);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['links']);
        $this->assertSame('https://example.com/growth-tools', $result['links'][0]['url']);
        $this->assertSame('growth marketing tools', $result['links'][0]['anchor']);
        $this->assertStringContainsString('Article preview', 'Article preview'); // keep assert style simple
        $this->assertStringContainsString('Detected link', (string) $result['html']);
    }

    public function test_extracts_plain_https_url_when_no_hyperlink_part(): void
    {
        $extractor = new DocumentTextExtractor;
        $links = $extractor->extractPlainTextLinks(
            'Teams can learn more at https://example.com/guides/seo and improve their publishing workflow.'
        );

        $this->assertNotEmpty($links);
        $this->assertSame('https://example.com/guides/seo', $links[0]['url']);
    }

    public function test_extracts_embedded_images_from_docx_when_store_callback_provided(): void
    {
        $path = sys_get_temp_dir().'/cmbop-image-test.docx';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rIdImg1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
            .'Target="media/image1.png"/>'
            .'</Relationships>');
        $zip->addFromString('word/media/image1.png', $png);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            .'<w:body><w:p><w:r><w:t>Intro paragraph before the figure for image extraction coverage.</w:t></w:r></w:p>'
            .'<w:p><w:r><w:drawing><wp:inline><a:graphic><a:graphicData>'
            .'<a:blip r:embed="rIdImg1"/>'
            .'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>'
            .'<w:p><w:r><w:t>Closing paragraph after the figure continues the article body text.</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();

        $stored = [];
        $result = (new DocumentTextExtractor)->extract(
            $path,
            'docx',
            function (string $binary, string $ext, string $originalName) use (&$stored): string {
                $stored[] = [$ext, $originalName, strlen($binary)];

                return 'https://cdn.example.test/articles/image1.'.$ext;
            }
        );
        @unlink($path);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($stored);
        $this->assertNotEmpty($result['images']);
        $this->assertStringContainsString('<img src="https://cdn.example.test/articles/image1.png"', (string) $result['html']);
        $this->assertStringContainsString('Intro paragraph', (string) $result['text']);
    }
}
