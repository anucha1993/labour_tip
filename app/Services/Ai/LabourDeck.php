<?php

namespace App\Services\Ai;

use App\Services\ExpiryService;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\Chart\Gridlines;
use PhpOffice\PhpPresentation\Shape\Chart\Legend;
use PhpOffice\PhpPresentation\Shape\Chart\Series;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Bar;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Doughnut;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Line;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Pie;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Border;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;

/**
 * Labour analytics as a PowerPoint deck (16:9, native editable charts), every number taken from the database:
 *   company — for the employer: workforce status, nationality, sex, agency, document expiry, optional name list
 *   system  — whole system: the same plus companies, agencies and data quality
 * The assistant may add its own insights / recommendations as one extra slide.
 */
class LabourDeck
{
    private const W = 960;
    private const H = 540;
    private const FONT = 'Tahoma'; // has Thai glyphs on Windows and macOS
    private const NAVY = '1E3A8A';
    private const INK = '1F2937';
    private const MUTED = '64748B';
    private const PALETTE = ['2563EB', '16A34A', 'F59E0B', 'DC2626', '7C3AED', '0891B2', 'DB2777', '65A30D', 'EA580C', '475569', '0D9488', 'A16207'];
    private const STACK = ['หมดอายุแล้ว' => 'DC2626', 'ภายใน 30 วัน' => 'F59E0B', '31–90 วัน' => '2563EB'];
    private const NAME_ROWS = 14;
    private const MAX_NAME_SLIDES = 10;

    private PhpPresentation $pres;
    private int $count = 0;
    private string $footer = '';

    public function __construct(private LabourQueryService $query, private LabourAnalytics $analytics)
    {
    }

    /**
     * @param  array  $opt  title, insights[], recommendations[], include_names, names_within_days, prepared_by
     * @return array  ['error' => …] | ['bytes', 'title', 'company', 'slides', 'outline' => [], 'numbers' => []]
     */
    public function build(string $scope, string $company, array $opt): array
    {
        $filters = [];
        if ($scope === 'company') {
            $names = array_column($this->query->count(['company' => $company, 'group_by' => 'company'])['groups'], 'group');
            if (! $names) {
                return ['error' => 'ไม่พบบริษัท "' . $company . '" (หรือยังไม่มีแรงงานในบริษัทนี้)'];
            }
            if (count($names) > 1) {
                return ['error' => 'ชื่อบริษัทตรงกับหลายบริษัท กรุณาระบุให้ชัด', 'candidates' => array_slice($names, 0, 10)];
            }
            $company = $names[0];
            $filters = ['company' => $company];
        }
        $job = $filters + ['status_job' => 'job'];
        $date = now()->format('d/m/Y');
        $title = $opt['title'] ?: ($scope === 'company' ? 'รายงานข้อมูลแรงงาน' : 'ภาพรวมข้อมูลแรงงานทั้งระบบ');
        $this->footer = ($scope === 'company' ? $company . ' · ' : '') . 'ข้อมูล ณ ' . $date . ' · The Importer 168';

        $this->pres = new PhpPresentation();
        $this->pres->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);
        $this->pres->getDocumentProperties()->setCreator('The Importer 168 – Labour App')->setTitle($title)->setCompany('The Importer 168');
        $outline = [];

        // numbers
        $status = array_column($this->query->count($filters + ['group_by' => 'status_job'])['groups'], 'total', 'group');
        $total = array_sum($status);
        $working = (int) ($status['ทำงาน'] ?? 0);
        $resign = (int) ($status['ลาออก'] ?? 0);
        $escape = (int) ($status['หลบหนี'] ?? 0);
        if ($total === 0) {
            return ['error' => 'ไม่มีข้อมูลแรงงานตามเงื่อนไขนี้'];
        }

        // 1. title
        $this->titleSlide($title, $scope === 'company' ? $company : 'แรงงานทุกบริษัทในระบบ', 'ข้อมูล ณ ' . $date . ($opt['prepared_by'] ? ' · จัดทำโดย ' . $opt['prepared_by'] : ''));
        $outline[] = 'หน้าปก';

        // 2. key numbers + automatic summary
        $cards = [['แรงงานทั้งหมด', $total], ['ทำงานอยู่', $working], ['ลาออก', $resign], ['หลบหนี', $escape]];
        if ($scope === 'system') {
            $ov = $this->query->overview();
            $cards[] = ['บริษัท/นายจ้าง', (int) $ov['companies']];
            $cards[] = ['เอเจนซี่', (int) $ov['agencies']];
        }
        $charts = $this->chartPlan($scope, $filters, $job);
        $computed = [];
        foreach ($charts as $c) {
            $r = $this->analytics->chart($c);
            if (! isset($r['error']) && empty($r['empty'])) {
                $computed[] = $r + ['note' => $c['note'] ?? ''];
            }
        }
        $summary = $this->autoSummary($total, $working, $resign, $escape, $computed);
        $this->numbersSlide('ภาพรวม', $cards, $summary);
        $outline[] = 'ภาพรวมตัวเลขสำคัญ';

        // 3. charts
        foreach ($computed as $c) {
            $this->chartSlide($c);
            $outline[] = $c['title'];
        }

        // 4. assistant's insights / recommendations
        if ($opt['insights'] || $opt['recommendations']) {
            $this->bulletsSlide('ข้อสังเกตและข้อเสนอแนะ', $opt['insights'], $opt['recommendations']);
            $outline[] = 'ข้อสังเกตและข้อเสนอแนะ';
        }

        // 5. names whose documents expire soon (company only, on request)
        if ($opt['include_names']) {
            $days = max(1, min(365, (int) $opt['names_within_days'] ?: 60));
            $n = $this->nameSlides($job, $days);
            $outline[] = $n ? "รายชื่อแรงงานที่เอกสารหมดอายุแล้ว/จะหมดใน {$days} วัน ({$n} สไลด์)" : "รายชื่อ: ไม่มีเอกสารที่จะหมดใน {$days} วัน";
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pptx');
        IOFactory::createWriter($this->pres, 'PowerPoint2007')->save($tmp);
        $this->thaiFonts($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return [
            'bytes' => $bytes, 'title' => $title, 'company' => $scope === 'company' ? $company : 'ทั้งระบบ', 'slides' => $this->count, 'outline' => $outline,
            'numbers' => ['total' => $total, 'working' => $working, 'resign' => $resign, 'escape' => $escape, 'summary' => $summary],
        ];
    }

    /** Which charts go in the deck (empty ones are skipped). */
    private function chartPlan(string $scope, array $filters, array $job): array
    {
        $plan = [['title' => 'สถานะการทำงานของแรงงาน', 'source' => 'group', 'group_by' => 'status_job', 'filters' => $filters, 'chart_type' => 'doughnut']];
        if ($scope === 'system') {
            $plan[] = ['title' => 'บริษัทที่มีแรงงานทำงานอยู่มากที่สุด', 'source' => 'group', 'group_by' => 'company', 'filters' => $job, 'chart_type' => 'horizontal_bar', 'top' => 12, 'rest' => false, 'note' => '12 บริษัทแรก (นับเฉพาะแรงงานที่ทำงานอยู่)'];
        }
        $plan[] = ['title' => 'สัญชาติของแรงงานที่ทำงานอยู่', 'source' => 'group', 'group_by' => 'nationality', 'filters' => $job];
        $plan[] = ['title' => 'เพศของแรงงานที่ทำงานอยู่', 'source' => 'group', 'group_by' => 'sex', 'filters' => $job, 'chart_type' => 'doughnut'];
        $plan[] = ['title' => 'เอเจนซี่ของแรงงานที่ทำงานอยู่', 'source' => 'group', 'group_by' => 'agency', 'filters' => $job, 'chart_type' => 'horizontal_bar', 'top' => 10];
        $plan[] = ['title' => 'สถานะเอกสารของแรงงานที่ทำงานอยู่', 'source' => 'expiry_status', 'filters' => $job, 'note' => 'นับเฉพาะแรงงานที่ทำงานอยู่ · สีแดง = หมดอายุแล้ว ต้องดำเนินการทันที'];
        $plan[] = ['title' => 'วีซ่าที่จะหมดอายุในแต่ละเดือน', 'source' => 'expiry_timeline', 'expiry_type' => 'visa', 'months' => $scope === 'system' ? 12 : 6, 'filters' => $filters, 'note' => 'นับเฉพาะแรงงานที่ทำงานอยู่ — ใช้วางแผนต่ออายุล่วงหน้า'];
        $plan[] = ['title' => 'ใบอนุญาตทำงานที่จะหมดอายุในแต่ละเดือน', 'source' => 'expiry_timeline', 'expiry_type' => 'work', 'months' => $scope === 'system' ? 12 : 6, 'filters' => $filters, 'note' => 'นับเฉพาะแรงงานที่ทำงานอยู่'];

        return $plan;
    }

    /** Plain-language summary computed from the numbers (no AI). */
    private function autoSummary(int $total, int $working, int $resign, int $escape, array $charts): array
    {
        $pct = fn ($n, $of) => $of ? round($n / $of * 100) . '%' : '0%';
        $out = ["แรงงานทั้งหมด " . number_format($total) . " คน ทำงานอยู่ " . number_format($working) . " คน (" . $pct($working, $total) . ")"];
        if ($resign || $escape) {
            $out[] = 'ลาออก ' . number_format($resign) . ' คน (' . $pct($resign, $total) . ') · หลบหนี ' . number_format($escape) . ' คน (' . $pct($escape, $total) . ')';
        }
        foreach ($charts as $c) {
            if ($c['title'] === 'สัญชาติของแรงงานที่ทำงานอยู่') {
                $out[] = 'สัญชาติหลัก: ' . $c['labels'][0] . ' ' . number_format($c['datasets'][0]['data'][0]) . ' คน (' . $pct($c['datasets'][0]['data'][0], $c['total']) . ' ของคนที่ทำงานอยู่)';
            }
            if ($c['title'] === 'สถานะเอกสารของแรงงานที่ทำงานอยู่') {
                $expired = $soon = [];
                foreach ($c['labels'] as $i => $label) {
                    if ($n = $c['datasets'][0]['data'][$i]) {
                        $expired[] = "{$label} {$n}";
                    }
                    if ($n = $c['datasets'][1]['data'][$i]) {
                        $soon[] = "{$label} {$n}";
                    }
                }
                $out[] = $expired ? 'เอกสารหมดอายุแล้ว (คน): ' . implode(' · ', $expired) : 'ไม่มีเอกสารที่หมดอายุแล้ว';
                if ($soon) {
                    $out[] = 'ต้องต่ออายุภายใน 30 วัน (คน): ' . implode(' · ', $soon);
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ slides

    private function slide(): Slide
    {
        $slide = $this->count === 0 ? $this->pres->getActiveSlide() : $this->pres->createSlide();
        $this->count++;

        return $slide;
    }

    /** White slide with a navy band, title and footer. */
    private function contentSlide(string $title, string $subtitle = ''): Slide
    {
        $s = $this->slide();
        $this->box($s, 0, 0, self::W, 6, self::NAVY);
        $this->text($s, $title, 40, 22, self::W - 80, 44, 24, self::NAVY, true);
        if ($subtitle !== '') {
            $this->text($s, $subtitle, 40, 62, self::W - 80, 24, 12, self::MUTED);
        }
        $this->text($s, $this->footer, 40, self::H - 30, self::W - 140, 20, 9, self::MUTED);
        $this->text($s, (string) $this->count, self::W - 80, self::H - 30, 40, 20, 9, self::MUTED, false, Alignment::HORIZONTAL_RIGHT);

        return $s;
    }

    private function titleSlide(string $title, string $subtitle, string $meta): void
    {
        $s = $this->slide();
        $this->box($s, 0, 0, self::W, self::H, self::NAVY);
        $this->box($s, 60, 250, 80, 4, 'F59E0B');
        $this->text($s, $title, 60, 150, self::W - 120, 90, 36, 'FFFFFF', true);
        $this->text($s, $subtitle, 60, 270, self::W - 120, 40, 20, 'E0E7FF');
        $this->text($s, $meta, 60, 320, self::W - 120, 30, 13, 'C7D2FE');
        $this->text($s, 'The Importer 168', 60, self::H - 60, 400, 30, 12, 'C7D2FE', true);
    }

    private function numbersSlide(string $title, array $cards, array $summary): void
    {
        $s = $this->contentSlide($title, 'ตัวเลขสำคัญ');
        $n = count($cards);
        $gap = 16;
        $w = (int) ((self::W - 80 - $gap * ($n - 1)) / $n);
        foreach ($cards as $i => [$label, $value]) {
            $x = 40 + $i * ($w + $gap);
            $this->box($s, $x, 100, $w, 110, 'EEF2FF');
            $this->text($s, $label, $x + 14, 112, $w - 28, 24, 13, self::MUTED);
            $this->text($s, number_format($value), $x + 14, 140, $w - 28, 50, 32, self::PALETTE[$i % count(self::PALETTE)], true);
        }
        $this->bullets($s, 'สรุป', $summary, 40, 232, self::W - 80, 260);
    }

    private function chartSlide(array $c): void
    {
        $s = $this->contentSlide($c['title'], $c['note'] ?: 'รวม ' . number_format($c['total']) . ' คน');
        $round = in_array($c['type'], ['pie', 'doughnut'], true);

        $shape = $s->createChartShape()->setOffsetX(40)->setOffsetY(96)->setWidth(self::W - 80)->setHeight(self::H - 140);
        $shape->getTitle()->setVisible(false);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFFFFFFF'));
        $shape->getBorder()->setLineStyle(Border::LINE_NONE);

        $type = match ($c['type']) {
            'pie' => new Pie(),
            'doughnut' => (new Doughnut())->setHoleSize(55),
            'line' => new Line(),
            default => new Bar(),
        };
        if ($type instanceof Bar) {
            $type->setBarDirection($c['type'] === 'horizontal_bar' ? Bar::DIRECTION_HORIZONTAL : Bar::DIRECTION_VERTICAL);
            $type->setGapWidthPercent(60);
            if ($c['stacked']) {
                $type->setBarGrouping(Bar::GROUPING_STACKED);
                $type->setOverlapWidthPercent(100);
            }
        }

        $labels = $this->uniqueLabels($c['labels']);
        if ($c['type'] === 'horizontal_bar') { // PowerPoint draws the first category at the bottom
            $labels = array_reverse($labels);
        }
        foreach ($c['datasets'] as $k => $d) {
            $data = $c['type'] === 'horizontal_bar' ? array_reverse($d['data']) : $d['data'];
            $series = new Series($d['label'], array_combine($labels, array_map('intval', $data)));
            $series->setShowValue(true)->setShowSeriesName(false)->setShowCategoryName(false)->setShowLegendKey(false);
            $series->setDlblNumFormat('#,##0;-#,##0;;'); // hide 0 labels
            $series->getFont()->setName(self::FONT)->setSize(11)->setBold(true)->setColor(new Color('FF' . ($round || $c['stacked'] ? 'FFFFFF' : self::INK)));
            if ($round) {
                $series->setShowPercentage(true)->setSeparator("\n");
                foreach (array_keys($labels) as $i) {
                    $series->getDataPointFill($i)->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF' . self::PALETTE[$i % count(self::PALETTE)]));
                }
            } else {
                $color = self::STACK[$d['label']] ?? self::PALETTE[$k % count(self::PALETTE)];
                $series->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF' . $color));
                if (! $c['stacked']) {
                    $series->setLabelPosition(Series::LABEL_OUTSIDEEND);
                }
            }
            $type->addSeries($series);
        }
        $shape->getPlotArea()->setType($type);

        $legend = $shape->getLegend();
        $legend->setVisible($round || count($c['datasets']) > 1);
        $legend->setPosition($round ? Legend::POSITION_RIGHT : Legend::POSITION_TOP);
        $legend->getFont()->setName(self::FONT)->setSize(12);
        $legend->getBorder()->setLineStyle(Border::LINE_NONE);
        if (! $round) {
            foreach ([$shape->getPlotArea()->getAxisX(), $shape->getPlotArea()->getAxisY()] as $axis) {
                $axis->setTitle('');
                $axis->getFont()->setName(self::FONT)->setSize(11)->setColor(new Color('FF' . self::INK));
            }
            $grid = new Gridlines();
            $grid->getOutline()->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FFE2E8F0'));
            $shape->getPlotArea()->getAxisY()->setMajorGridlines($grid);
        }
    }

    private function bulletsSlide(string $title, array $insights, array $recommendations): void
    {
        $s = $this->contentSlide($title, 'จัดทำโดยผู้ช่วย AI จากข้อมูลในระบบ — โปรดตรวจทานก่อนนำเสนอ');
        if ($insights && $recommendations) {
            $this->bullets($s, 'ข้อสังเกต', $insights, 40, 100, 430, 390);
            $this->bullets($s, 'ข้อเสนอแนะ', $recommendations, 490, 100, 430, 390);
        } else {
            $this->bullets($s, $insights ? 'ข้อสังเกต' : 'ข้อเสนอแนะ', $insights ?: $recommendations, 40, 100, self::W - 80, 390);
        }
    }

    /** Table slides of labours whose documents are expired or expire within $days (working labours only). */
    private function nameSlides(array $job, int $days): int
    {
        $rows = [];
        foreach (ExpiryService::TYPES as $type => $col) {
            foreach ($this->query->exportRows($job + ['expiring' => ['type' => $type, 'within_days' => $days, 'include_expired' => true]]) as $r) {
                $end = $r->{$col};
                if (is_blank_date($end)) {
                    continue;
                }
                $left = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($end)->startOfDay(), false);
                $rows[] = [trim($r->labour_prefix . '. ' . $r->labour_fullname), (string) $r->labour_passport_number, ExpiryService::LABELS[$type], fmt_date($end), $left < 0 ? 'หมดแล้ว ' . abs($left) . ' วัน' : $left . ' วัน', $left];
            }
        }
        usort($rows, fn ($a, $b) => $a[5] <=> $b[5]);
        $pages = array_slice(array_chunk($rows, self::NAME_ROWS), 0, self::MAX_NAME_SLIDES);
        $shown = array_sum(array_map('count', $pages));
        foreach ($pages as $p => $chunk) {
            $s = $this->contentSlide('รายชื่อแรงงานที่ต้องต่ออายุเอกสาร' . (count($pages) > 1 ? ' (' . ($p + 1) . '/' . count($pages) . ')' : ''),
                'เอกสารหมดอายุแล้ว หรือจะหมดภายใน ' . $days . ' วัน · ' . number_format(count($rows)) . ' รายการ' . ($shown < count($rows) ? ' (แสดง ' . $shown . ' รายการแรก — ขอ Excel ได้)' : ''));
            $table = $s->createTableShape(5)->setOffsetX(40)->setOffsetY(96)->setWidth(self::W - 80);
            $widths = [300, 140, 150, 130, 160];
            $this->tableRow($table, ['ชื่อ-สกุล', 'เลขพาสปอร์ต', 'เอกสาร', 'วันหมดอายุ', 'เหลือ'], $widths, true);
            foreach ($chunk as $r) {
                $this->tableRow($table, array_slice($r, 0, 5), $widths, false, $r[5] < 0 ? 'DC2626' : ($r[5] <= 30 ? 'B45309' : null));
            }
        }

        return count($pages);
    }

    // ------------------------------------------------------------------ drawing helpers

    private function text(Slide $s, string $text, int $x, int $y, int $w, int $h, int $size, string $color, bool $bold = false, string $align = Alignment::HORIZONTAL_LEFT): RichText
    {
        $shape = $s->createRichTextShape()->setOffsetX($x)->setOffsetY($y)->setWidth($w)->setHeight($h);
        $shape->setInsetLeft(0)->setInsetRight(0)->setInsetTop(0)->setInsetBottom(0);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal($align);
        $shape->createTextRun($text)->getFont()->setName(self::FONT)->setSize($size)->setBold($bold)->setColor(new Color('FF' . $color));

        return $shape;
    }

    private function box(Slide $s, int $x, int $y, int $w, int $h, string $color): void
    {
        $shape = $s->createRichTextShape()->setOffsetX($x)->setOffsetY($y)->setWidth($w)->setHeight($h);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF' . $color));
    }

    private function bullets(Slide $s, string $heading, array $items, int $x, int $y, int $w, int $h): void
    {
        $shape = $s->createRichTextShape()->setOffsetX($x)->setOffsetY($y)->setWidth($w)->setHeight($h);
        $shape->setInsetLeft(0)->setInsetRight(0);
        $shape->setAutoFit(RichText::AUTOFIT_NORMAL);
        $shape->createTextRun($heading)->getFont()->setName(self::FONT)->setSize(16)->setBold(true)->setColor(new Color('FF' . self::NAVY));
        foreach ($items as $item) {
            $p = $shape->createParagraph();
            $p->getBulletStyle()->setBulletType(Bullet::TYPE_BULLET)->setBulletChar('•')->setBulletColor(new Color('FF' . self::NAVY));
            $p->getAlignment()->setMarginLeft(22)->setIndent(-18);
            $p->setSpacingBefore(8);
            $p->createTextRun($item)->getFont()->setName(self::FONT)->setSize(15)->setColor(new Color('FF' . self::INK));
        }
    }

    private function tableRow($table, array $cells, array $widths, bool $head, ?string $color = null): void
    {
        $row = $table->createRow()->setHeight(26);
        if ($head) {
            $row->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF' . self::NAVY));
        }
        foreach ($cells as $i => $value) {
            $cell = $row->nextCell()->setWidth($widths[$i]);
            $cell->getActiveParagraph()->getFont()->setName(self::FONT);
            $cell->createTextRun((string) $value)->getFont()->setName(self::FONT)->setSize(11)->setBold($head)
                ->setColor(new Color('FF' . ($head ? 'FFFFFF' : ($i === 4 && $color ? $color : self::INK))));
            foreach (['getTop', 'getBottom', 'getLeft', 'getRight'] as $b) {
                $cell->getBorders()->{$b}()->setLineStyle(Border::LINE_SINGLE)->setColor(new Color('FFE2E8F0'));
            }
        }
    }

    /**
     * PhpPresentation writes one font per text run (<a:latin>), so Thai falls back to the theme's complex-script
     * font and looks different from the digits. Add <a:cs> next to every <a:latin> and make the theme use FONT too.
     */
    private function thaiFonts(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! preg_match('#^ppt/(slides|charts|theme|slideMasters|slideLayouts)/[^/]+\.xml$#', $name)) {
                continue;
            }
            $xml = (string) $zip->getFromIndex($i);
            $new = preg_replace('#(<a:(?:latin|cs) typeface=")(Calibri|)"#', '$1' . self::FONT . '"', $xml);
            // add <a:cs> only where the run has none (the theme already lists latin/ea/cs)
            $new = preg_replace('#(<a:latin typeface="' . self::FONT . '"[^>]*/>)(?!\s*<a:(?:ea|cs)\b)#', '$1<a:cs typeface="' . self::FONT . '"/>', $new);
            // chart axes: <a:latin/><a:ea typeface="Calibri"/> without <a:cs>
            $new = preg_replace('#<a:ea typeface="[^"]*"/>(?!\s*<a:cs\b)#', '<a:ea typeface="' . self::FONT . '"/><a:cs typeface="' . self::FONT . '"/>', $new);
            if ($new !== $xml) {
                $zip->addFromString($name, $new);
            }
        }
        $zip->close();
    }

    /** Chart categories must be unique array keys. */
    private function uniqueLabels(array $labels): array
    {
        $seen = [];
        foreach ($labels as $i => $l) {
            $l = (string) $l;
            while (isset($seen[$l])) {
                $l .= ' ';
            }
            $seen[$l] = true;
            $labels[$i] = $l;
        }

        return array_values($labels);
    }
}
