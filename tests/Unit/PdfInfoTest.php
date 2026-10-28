<?php

namespace Tests\Unit;

use App\Support\PdfInfo;
use PHPUnit\Framework\TestCase;

class PdfInfoTest extends TestCase
{
    public function test_counts_plain_page_objects()
    {
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Pages/Kids[2 0 R 3 0 R]/Count 2>>endobj 2 0 obj<</Type /Page>>endobj 3 0 obj<</Type/Page /Parent 1 0 R>>endobj";
        $this->assertSame(2, PdfInfo::pageCount($pdf));
    }

    public function test_counts_pages_inside_compressed_object_streams()
    {
        $inner = '4 0 obj<</Type/Page>> 5 0 obj<</Type/Page>> 6 0 obj<</Type/Page>> 7 0 obj<</Type/Pages/Count 3>>';
        $pdf = "%PDF-1.5\n9 0 obj<</Type/ObjStm/N 4/First 20/Filter/FlateDecode>>stream\n" . gzcompress($inner) . "\nendstream endobj";
        $this->assertSame(3, PdfInfo::pageCount($pdf));
    }

    public function test_unknown_when_nothing_found()
    {
        $this->assertNull(PdfInfo::pageCount('%PDF-1.4 fake passport'));
    }
}
