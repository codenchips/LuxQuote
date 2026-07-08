<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PdfDownloadUrlService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PdfPreparedDownloadTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/pdf-downloads'));

        parent::tearDown();
    }

    public function test_prepared_pdf_download_opens_inline_with_the_original_filename(): void
    {
        $path = storage_path('framework/testing/prepared-pdf-test.pdf');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, self::pdfFixtureContent());

        $user = User::factory()->make(['id' => 123]);
        $download = app(PdfDownloadUrlService::class)->register([
            'path' => $path,
            'filename' => 'Lighting-Schedule-20930-R1.pdf',
        ], $user->id);

        $this->actingAs($user)
            ->get($download['url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="Lighting-Schedule-20930-R1.pdf"');

        $this->actingAs($user)
            ->get($download['url'])
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="Lighting-Schedule-20930-R1.pdf"');

        $this->assertFileDoesNotExist($path);
    }

    public function test_prepared_pdf_download_is_scoped_to_the_authenticated_user(): void
    {
        $path = storage_path('framework/testing/prepared-pdf-other-user-test.pdf');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, self::pdfFixtureContent());

        $download = app(PdfDownloadUrlService::class)->register([
            'path' => $path,
            'filename' => 'private.pdf',
        ], 456);

        $this->actingAs(User::factory()->make(['id' => 123]))
            ->get($download['url'])
            ->assertForbidden();

        $this->assertFileDoesNotExist($path);
    }

    private static function pdfFixtureContent(): string
    {
        return <<<'PDF'
%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>
endobj
4 0 obj
<< /Length 40 >>
stream
BT /F1 12 Tf 20 100 Td (Test PDF) Tj ET
endstream
endobj
5 0 obj
<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>
endobj
xref
0 6
0000000000 65535 f
0000000009 00000 n
0000000058 00000 n
0000000115 00000 n
0000000241 00000 n
0000000331 00000 n
trailer
<< /Size 6 /Root 1 0 R >>
startxref
401
%%EOF
PDF;
    }
}
