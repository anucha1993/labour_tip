<?php

namespace App\Http\Controllers\PDF;

use Codedge\Fpdf\Fpdf\Fpdf;

/**
 * Shared landscape A4 table renderer for the labour PDF reports.
 */
trait LabourPdfReport
{
    protected function streamPdf($rows, string $title, string $filename)
    {
        $pdf = new Fpdf();
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddFont('THSarabunNew', '', 'THSarabunNew.php');
        $pdf->AddFont('THSarabunNew', 'B', 'THSarabunNew_b.php');

        $columns = [
            ['ลำดับ', 8, 'C'],
            ['ชื่อ-สกุล', 38, 'L'],
            ['เพศ', 10, 'C'],
            ['วันเกิด', 17, 'C'],
            ['สัญชาติ', 14, 'C'],
            ['บริษัท', 55, 'L'],
            ['เลขที่หนังสือเดินทาง', 24, 'C'],
            ['เลขที่วีซ่า', 20, 'C'],
            ['เลขที่ใบอนุญาตทำงาน', 30, 'C'],
            ['รายงานตัว 90 วัน', 20, 'C'],
            ['วีซ่าหมดอายุ', 20, 'C'],
            ['เลขที่ ตม.', 18, 'C'],
            ['สถานะ', 15, 'C'],
        ];

        $header = function () use ($pdf, $title, $columns) {
            $pdf->AddPage('L', 'A4');
            $logo = public_path('images/HeaderText.jpg');
            if (is_file($logo)) {
                $pdf->Image($logo, 8, 6, 90);
            }
            $pdf->SetFont('THSarabunNew', 'B', 16);
            $pdf->SetXY(8, 24);
            $pdf->Cell(0, 8, tis620($title), 0, 1, 'C');
            $pdf->SetFont('THSarabunNew', '', 10);
            $pdf->Cell(0, 5, tis620('พิมพ์เมื่อ ' . date('d/m/Y H:i')), 0, 1, 'R');
            $pdf->Ln(1);

            $pdf->SetFont('THSarabunNew', 'B', 11);
            $pdf->SetFillColor(226, 232, 240);
            foreach ($columns as [$label, $width]) {
                $pdf->Cell($width, 7, tis620($label), 1, 0, 'C', true);
            }
            $pdf->Ln();
            $pdf->SetFont('THSarabunNew', '', 10);
        };

        $header();

        $pdf->SetFillColor(255, 255, 255);
        $i = 0;
        foreach ($rows as $row) {
            if ($pdf->GetY() > 190) {
                $header();
            }
            $values = [
                ++$i,
                $row->labour_prefix . '.' . $row->labour_fullname,
                sex_label($row->labour_sex),
                fmt_date($row->labour_birthday),
                $row->name_th ?? $row->labour_nationality,
                $row->company_name ?? '-',
                $row->labour_passport_number,
                $row->labour_visa_number,
                $row->labour_workpremit_number,
                fmt_date($row->labour_day90_date_end),
                fmt_date($row->labour_visa_date_end),
                $row->labour_tm_number,
                status_job_label($row->labour_status_job),
            ];
            foreach ($columns as $k => [$label, $width, $align]) {
                $text = tis620($values[$k] ?? '');
                // Trim text that would overflow its cell.
                while ($text !== '' && $pdf->GetStringWidth($text) > $width - 2) {
                    $text = substr($text, 0, -1);
                }
                $pdf->Cell($width, 6, $text, 1, 0, $align);
            }
            $pdf->Ln();
        }

        if ($i === 0) {
            $pdf->Cell(0, 8, tis620('ไม่พบข้อมูลตามเงื่อนไขที่เลือก'), 0, 1, 'C');
        }

        return response($pdf->Output('S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }
}
