<?php

namespace App\Services\Ai;

use App\Services\ExpiryService;
use Carbon\Carbon;

/**
 * Chart data computed from the database (never typed in by the model). Shared by the chat charts (create_chart)
 * and the PowerPoint deck (LabourDeck), so both always show the same numbers.
 */
class LabourAnalytics
{
    public const CHART_TYPES = ['bar', 'horizontal_bar', 'pie', 'doughnut', 'line'];

    public const STATUS_JOB = ['job' => 'ทำงานอยู่', 'resign' => 'ลาออก', 'escape' => 'หลบหนี'];

    public function __construct(private LabourQueryService $query)
    {
    }

    /**
     * @param  array  $args  title, source (group|expiry_timeline|expiry_status), group_by, expiry_type, months, chart_type, filters, top
     * @return array  ['error' => …] | ['empty' => true, …] | chart: title, subtitle, type, stacked, labels, datasets [{label, data}], total
     */
    public function chart(array $args): array
    {
        $filters = is_array($args['filters'] ?? null) ? $args['filters'] : [];
        $source = (string) ($args['source'] ?? 'group');
        $title = mb_substr(trim((string) ($args['title'] ?? '')), 0, 100) ?: 'กราฟข้อมูลแรงงาน';
        $stacked = false;

        if ($source === 'group') {
            $group = (string) ($args['group_by'] ?? '');
            if (! isset(LabourQueryService::GROUP_FIELDS[$group])) {
                return ['error' => 'group_by ต้องเป็น ' . implode('/', array_keys(LabourQueryService::GROUP_FIELDS))];
            }
            $rows = $this->query->count($filters + ['group_by' => $group])['groups'];
            $top = max(3, min(20, (int) ($args['top'] ?? 12)));
            if (count($rows) > $top) { // top N-1 + "อื่นๆ", or just the top N when rest=false (e.g. ranking companies)
                $rest = array_sum(array_column(array_slice($rows, $top - 1), 'total'));
                $rows = ($args['rest'] ?? true) === false
                    ? array_slice($rows, 0, $top)
                    : array_merge(array_slice($rows, 0, $top - 1), [['group' => 'อื่นๆ', 'total' => $rest]]);
            }
            $labels = array_map('strval', array_column($rows, 'group'));
            $datasets = [['label' => 'จำนวนแรงงาน (คน)', 'data' => array_column($rows, 'total')]];
            $default = count($rows) <= 6 ? 'doughnut' : 'bar';
        } elseif ($source === 'expiry_timeline') {
            $t = $this->query->expiryTimeline((string) ($args['expiry_type'] ?? 'visa'), (int) ($args['months'] ?? 6), null, $filters);
            if (isset($t['error'])) {
                return $t;
            }
            $labels = array_merge(['หมดอายุแล้ว'], array_map(fn ($m) => Carbon::createFromFormat('Y-m-d', $m['month'] . '-01')->locale('th')->translatedFormat('M') . ' ' . substr((string) (substr($m['month'], 0, 4) + 543), 2), $t['months']));
            $datasets = [['label' => $t['label'] . ' (คน)', 'data' => array_merge([(int) $t['expired_already']], array_column($t['months'], 'total'))]];
            $default = 'bar';
        } elseif ($source === 'expiry_status') {
            $labels = [];
            $series = ['หมดอายุแล้ว' => [], 'ภายใน 30 วัน' => [], '31–90 วัน' => []];
            foreach (array_keys(ExpiryService::TYPES) as $type) {
                $labels[] = ExpiryService::LABELS[$type];
                $in30 = $this->query->count($filters + ['expiring' => ['type' => $type, 'within_days' => 30]])['total'];
                $in90 = $this->query->count($filters + ['expiring' => ['type' => $type, 'within_days' => 90]])['total'];
                $series['หมดอายุแล้ว'][] = $this->query->count($filters + ['expired' => ['type' => $type]])['total'];
                $series['ภายใน 30 วัน'][] = $in30;
                $series['31–90 วัน'][] = max(0, $in90 - $in30);
            }
            $datasets = array_map(fn ($k, $v) => ['label' => $k, 'data' => $v], array_keys($series), $series);
            $default = 'bar';
            $stacked = true;
        } else {
            return ['error' => 'source ต้องเป็น group / expiry_timeline / expiry_status'];
        }

        $total = array_sum(array_map(fn ($d) => array_sum($d['data']), $datasets));
        if ($total === 0) {
            return ['empty' => true, 'title' => $title, 'message' => 'ไม่พบข้อมูลตามเงื่อนไข', 'filters' => $filters];
        }
        $type = in_array($args['chart_type'] ?? '', self::CHART_TYPES, true) ? $args['chart_type'] : $default;
        if ($stacked && in_array($type, ['pie', 'doughnut', 'line'], true)) {
            $type = 'bar';
        }

        return ['title' => $title, 'subtitle' => $this->subtitle($filters), 'type' => $type, 'stacked' => $stacked, 'labels' => $labels, 'datasets' => $datasets, 'total' => $total];
    }

    /** "บริษัท X · ทำงานอยู่ · ข้อมูล ณ 03/11/2026" */
    public function subtitle(array $filters): string
    {
        $parts = array_filter([$filters['company'] ?? null, $filters['agency'] ?? null, self::STATUS_JOB[$filters['status_job'] ?? ''] ?? null]);
        $parts[] = 'ข้อมูล ณ ' . now()->format('d/m/Y');

        return implode(' · ', $parts);
    }
}
