<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Repo;

/**
 * 리포트 집계(설계서 10). 요청일(일반 요청은 최초 제출일, 작업일지는 입력한 요청일) 기준 기간, 임시저장/취소 건 제외.
 * 지연 = 완료요구일(req_due_date) 대비. 합의 연장(plan_end) 안에 끝난 건은 "합의 연장 내"로 따로 센다.
 * 월간 현황표(monthly)는 기간 필터와 무관하게 연도 1~12월을 접수(요청일)·완료(완료일)·취소(취소일) 발생월로 나눠 센다. 취소 건을 포함하고 SR/작업일지를 분리한다.
 * 각 절은 ['head' => 열 제목, 'rows' => 표시값, 'chart' => 차트 명세|null, 'note' => 안내문(선택)] 형태이며 화면과 CSV 가 같은 표를 쓴다.
 */
final class ReportService
{
    public const GRAINS = ['month', 'quarter', 'year'];
    public const SECTIONS = [
        'monthly', 'service', 'owner', 'type', 'cross_service', 'cross_owner',
        'schedule_service', 'schedule_owner', 'reasons', 'bottleneck', 'period', 'period_service',
    ];

    /** @param array<string,mixed> $in @return array{from:string,to:string,grain:string,service_id:int,maint_type:string,owner_id:int,year:int} */
    public static function filters(array $in, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $from = (string)($in['from'] ?? '');
        $to = (string)($in['to'] ?? '');
        $from = Dates::isDate($from) ? $from : date('Y-01-01', strtotime($today));
        $to = Dates::isDate($to) ? $to : $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $grain = (string)($in['grain'] ?? 'month');
        $sid = (string)($in['service_id'] ?? '');
        $oid = (string)($in['owner_id'] ?? '');
        $yr = (string)($in['year'] ?? '');
        return [
            'from' => $from, 'to' => $to, 'grain' => in_array($grain, self::GRAINS, true) ? $grain : 'month',
            'service_id' => ctype_digit($sid) ? (int)$sid : 0,
            'maint_type' => (string)($in['maint_type'] ?? ''),
            'owner_id' => ctype_digit($oid) ? (int)$oid : 0,
            // 월간 현황표의 연도. 지정이 없으면 종료일의 연도
            'year' => ctype_digit($yr) && (int)$yr >= 2000 && (int)$yr <= 2100 ? (int)$yr : (int)substr($to, 0, 4),
        ];
    }

    /** 데이터 범위: 전체 조회 권한은 전체, 부서 조회 권한은 주관부서 서비스만, 그 외는 없음. @return array{0:string,1:array<string,mixed>} */
    public static function scope(array $user, array $perms): array
    {
        if (in_array('REQUEST_VIEW_ALL', $perms, true)) {
            return ['1 = 1', []];
        }
        if (in_array('REQUEST_VIEW_DEPT', $perms, true) && $user['dept_id'] !== null) {
            return ['s.biz_dept_id = :scope_dept', ['scope_dept' => (int)$user['dept_id']]];
        }
        return ['1 = 0', []];
    }

    /**
     * @param array<string,mixed> $f filters() 결과
     * @return array<string,mixed> summary + sections
     */
    public static function build(array $f, array $user, array $perms, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        [$scopeSql, $scopeP] = self::scope($user, $perms);
        [$where, $p] = self::where($f, $scopeSql, $scopeP);
        $from = self::FROM;
        $open = "r.status IN ('IN_PROGRESS', 'RETURNED')";
        $lateExpr = "((r.status = 'COMPLETED' AND r.actual_end > r.req_due_date) OR ($open AND :today > r.req_due_date))";
        $rd = self::REQ_DATE;
        $agg = "COUNT(*) AS total,
                SUM(CASE WHEN r.status = 'COMPLETED' THEN 1 ELSE 0 END) AS done,
                SUM(CASE WHEN $open THEN 1 ELSE 0 END) AS open_cnt,
                ROUND(AVG(CASE WHEN r.status = 'COMPLETED' THEN julianday(r.actual_end) - julianday($rd) END), 1) AS avg_days,
                ROUND(COALESCE(SUM(r.effort_hours), 0), 2) AS effort,
                SUM(CASE WHEN $lateExpr THEN 1 ELSE 0 END) AS late";
        $pt = $p + ['today' => $today];

        $svcRows = Repo::query("SELECT s.service_cd, s.service_nm, $agg $from WHERE $where GROUP BY s.service_id ORDER BY s.service_cd", $pt);
        $ownRows = Repo::query("SELECT ow.user_nm AS owner_nm, $agg $from WHERE $where GROUP BY ow.user_id ORDER BY ow.user_nm IS NULL, ow.user_nm", $pt);
        $typeRows = Repo::query("SELECT r.maint_type, COUNT(*) AS total $from WHERE $where GROUP BY r.maint_type ORDER BY total DESC, r.maint_type", $p);
        $types = self::typeNames();
        $sum = Repo::queryOne("SELECT $agg $from WHERE $where", $pt) ?? [];
        $canceled = (int)Repo::queryVal(
            'SELECT COUNT(*) ' . $from . ' WHERE ' . str_replace("r.status NOT IN ('DRAFT', 'CANCELED')", "r.status = 'CANCELED'", $where), $p
        );

        $sections = [
            'monthly' => self::monthly($f, $scopeSql, $scopeP, $today),
            'service' => self::agg($svcRows, 'rpt.col.service', static fn(array $r): string => self::svcName($r)),
            'owner' => self::agg($ownRows, 'rpt.col.owner', static fn(array $r): string => (string)($r['owner_nm'] ?? t('dash.unassigned'))),
            'type' => self::typeSection($typeRows, $types),
            'cross_service' => self::cross(
                Repo::query("SELECT s.service_cd, s.service_nm, r.maint_type, COUNT(*) AS n $from WHERE $where GROUP BY s.service_id, r.maint_type", $p),
                $types, 'rpt.col.service', static fn(array $r): string => self::svcName($r), 'service_cd'
            ),
            'cross_owner' => self::cross(
                Repo::query("SELECT ow.user_nm AS owner_nm, r.maint_type, COUNT(*) AS n $from WHERE $where GROUP BY ow.user_id, r.maint_type", $p),
                $types, 'rpt.col.owner', static fn(array $r): string => (string)($r['owner_nm'] ?? t('dash.unassigned')), 'owner_nm'
            ),
        ];
        $schedAgg = "SUM(CASE WHEN r.status = 'COMPLETED' THEN 1 ELSE 0 END) AS done,
            SUM(CASE WHEN r.status = 'COMPLETED' AND r.actual_end > r.req_due_date THEN 1 ELSE 0 END) AS late_done,
            SUM(CASE WHEN r.status = 'COMPLETED' AND r.actual_end > r.req_due_date AND r.plan_end IS NOT NULL AND r.actual_end <= r.plan_end THEN 1 ELSE 0 END) AS late_ext,
            SUM(CASE WHEN r.status = 'COMPLETED' AND r.actual_end > r.req_due_date THEN julianday(r.actual_end) - julianday(r.req_due_date) ELSE 0 END) AS late_days,
            SUM(CASE WHEN $open AND :today > r.req_due_date THEN 1 ELSE 0 END) AS open_over,
            SUM(CASE WHEN $open AND :today > r.req_due_date AND r.plan_end IS NOT NULL AND :today <= r.plan_end THEN 1 ELSE 0 END) AS open_ext,
            SUM((SELECT COUNT(*) FROM schedule_change sc WHERE sc.company_cd = r.company_cd AND sc.request_id = r.request_id)) AS changes,
            SUM((SELECT COUNT(*) FROM schedule_change sc WHERE sc.company_cd = r.company_cd AND sc.request_id = r.request_id AND sc.status = 'AGREED')) AS agreed";
        $sections['schedule_service'] = self::schedule(
            Repo::query("SELECT s.service_cd, s.service_nm, $schedAgg $from WHERE $where GROUP BY s.service_id ORDER BY s.service_cd", $pt),
            'rpt.col.service', static fn(array $r): string => self::svcName($r)
        );
        $sections['schedule_owner'] = self::schedule(
            Repo::query("SELECT ow.user_nm AS owner_nm, $schedAgg $from WHERE $where GROUP BY ow.user_id ORDER BY ow.user_nm IS NULL, ow.user_nm", $pt),
            'rpt.col.owner', static fn(array $r): string => (string)($r['owner_nm'] ?? t('dash.unassigned'))
        );
        $sections['reasons'] = self::reasons(Repo::query(
            "SELECT sc.reason_cd, COUNT(*) AS n FROM schedule_change sc
               JOIN request r ON r.company_cd = sc.company_cd AND r.request_id = sc.request_id
               JOIN service s ON s.company_cd = r.company_cd AND s.service_id = r.service_id
               LEFT JOIN user ow ON ow.company_cd = r.company_cd AND ow.user_id = COALESCE(r.it_assignee_id, s.it_owner_id)
              WHERE $where AND sc.status <> 'WITHDRAWN' GROUP BY sc.reason_cd ORDER BY n DESC, sc.reason_cd", $p
        ));
        $sections['bottleneck'] = self::bottleneck(Repo::query(
            "SELECT pm.process_cd, pm.process_nm, rs.step_no, rs.step_nm, ps.step_id, COUNT(*) AS n,
                    ROUND(AVG(julianday(rs.ended_at) - julianday(rs.started_at)), 2) AS avg_days,
                    ROUND(MAX(julianday(rs.ended_at) - julianday(rs.started_at)), 2) AS max_days
               FROM request_step rs
               JOIN request r ON r.company_cd = rs.company_cd AND r.request_id = rs.request_id
               JOIN service s ON s.company_cd = r.company_cd AND s.service_id = r.service_id
               LEFT JOIN user ow ON ow.company_cd = r.company_cd AND ow.user_id = COALESCE(r.it_assignee_id, s.it_owner_id)
               JOIN process_version pv ON pv.company_cd = r.company_cd AND pv.version_id = r.version_id
               JOIN process_master pm ON pm.company_cd = pv.company_cd AND pm.process_id = pv.process_id
               LEFT JOIN process_step ps ON ps.company_cd = r.company_cd AND ps.version_id = r.version_id AND ps.step_no = rs.step_no
              WHERE $where AND r.reg_by IS NULL AND rs.status = 'DONE' AND rs.step_no > 1 AND rs.started_at IS NOT NULL AND rs.ended_at IS NOT NULL
              GROUP BY pm.process_id, rs.step_no ORDER BY pm.process_cd, rs.step_no", $p
        ));
        $bucket = self::bucket($f['grain']);
        $periodRows = Repo::query(
            "SELECT $bucket AS period, COUNT(*) AS total, SUM(CASE WHEN r.status = 'COMPLETED' THEN 1 ELSE 0 END) AS done
               $from WHERE $where GROUP BY period ORDER BY period", $p
        );
        $sections['period'] = self::period($periodRows, $f['grain']);
        $sections['period_service'] = self::periodService(Repo::query(
            "SELECT s.service_cd, s.service_nm, $bucket AS period, COUNT(*) AS n $from WHERE $where GROUP BY s.service_id, period", $p
        ), $periodRows);

        return [
            'filters' => $f,
            'summary' => [
                'total' => (int)($sum['total'] ?? 0), 'done' => (int)($sum['done'] ?? 0), 'open' => (int)($sum['open_cnt'] ?? 0),
                'canceled' => $canceled, 'late' => (int)($sum['late'] ?? 0), 'effort' => (float)($sum['effort'] ?? 0),
                'avg_days' => $sum['avg_days'] === null ? null : (float)$sum['avg_days'],
            ],
            'sections' => $sections,
        ];
    }

    // ------------------------------------------------------------------ 월간 현황표

    /**
     * 연도 1~12월 그리드. 접수=요청일, 완료=완료일, 취소=취소일이 속한 달에 센다(취소 건 포함, 임시저장·미제출 취소 제외).
     * 이월+접수-완료-취소=진행(기말)이고 한 달의 진행(기말)은 다음 달의 이월과 같다. 이번 달의 기한 초과는 오늘 기준, 지난 달은 말일 기준이다.
     * @return array<string,mixed>
     */
    private static function monthly(array $f, string $scopeSql, array $scopeP, string $today): array
    {
        $y = (int)$f['year'];
        [$where, $p] = self::where($f, $scopeSql, $scopeP, false);
        $rows = Repo::query(
            'SELECT r.status, ' . self::REQ_DATE . ' AS rd, r.req_due_date AS due, r.plan_end, r.actual_end, r.canceled_at, r.updated_at, r.effort_hours,
                    (r.reg_by IS NOT NULL) AS wl' . self::FROM . "
              WHERE $where AND r.status <> 'DRAFT' AND NOT (r.status = 'CANCELED' AND r.request_date IS NULL)", $p
        );
        $thisYear = (int)substr($today, 0, 4);
        $last = $y < $thisYear ? 12 : ($y === $thisYear ? (int)substr($today, 5, 2) : 0);
        $blank = ['carry' => 0, 'in' => 0, 'done' => 0, 'cancel' => 0, 'open' => 0, 'over' => 0, 'ext' => 0, 'late' => 0, 'days' => 0.0, 'hrs' => 0.0];
        $cells = [];
        foreach (['sr', 'wl', 'all'] as $g) {
            for ($m = 1; $m <= 12; $m++) {
                $cells[$g][$m] = $blank;
            }
        }
        foreach ($rows as $r) {
            $rd = (string)$r['rd'];
            $end = $r['status'] === 'COMPLETED' ? max($rd, (string)($r['actual_end'] ?? substr((string)$r['updated_at'], 0, 10))) : null;
            $cx = $r['status'] === 'CANCELED' ? max($rd, (string)($r['canceled_at'] ?? substr((string)$r['updated_at'], 0, 10))) : null;
            $due = (string)$r['due'];
            $plan = $r['plan_end'] === null ? null : (string)$r['plan_end'];
            $hrs = (float)($r['effort_hours'] ?? 0);
            for ($m = 1; $m <= $last; $m++) {
                $ms = sprintf('%04d-%02d-01', $y, $m);
                $me = date('Y-m-t', strtotime($ms));
                $asof = min($me, $today);
                $hit = [];
                if ($rd < $ms && ($end === null || $end >= $ms) && ($cx === null || $cx >= $ms)) {
                    $hit['carry'] = 1;
                }
                if ($rd >= $ms && $rd <= $me) {
                    $hit['in'] = 1;
                }
                if ($end !== null && $end >= $ms && $end <= $me) {
                    $hit['done'] = 1;
                    $hit['days'] = (strtotime($end) - strtotime($rd)) / 86400;
                    $hit['hrs'] = $hrs;
                    if ($end > $due) {
                        $hit['late'] = 1;
                    }
                }
                if ($cx !== null && $cx >= $ms && $cx <= $me) {
                    $hit['cancel'] = 1;
                }
                if ($rd <= $me && ($end === null || $end > $me) && ($cx === null || $cx > $me)) {
                    $hit['open'] = 1;
                    if ($asof > $due) {
                        $hit['over'] = 1;
                        if ($plan !== null && $asof <= $plan) {
                            $hit['ext'] = 1;
                        }
                    }
                }
                foreach ([((int)$r['wl'] === 1 ? 'wl' : 'sr'), 'all'] as $g) {
                    foreach ($hit as $k => $v) {
                        $cells[$g][$m][$k] += $v;
                    }
                }
            }
        }

        $head = [t('rpt.col.group'), t('rpt.col.metric')];
        for ($m = 1; $m <= 12; $m++) {
            $head[] = t('rpt.col.month_n', ['n' => $m]);
        }
        $head[] = t('rpt.col.sum');

        $out = [];
        $stat = [];
        foreach (['sr', 'wl', 'all'] as $g) {
            $c = $cells[$g];
            $sum = static fn(string $k): int|float => array_sum(array_column(array_slice($c, 0, $last), $k));
            $at = static fn(string $k): int => $last > 0 ? (int)$c[$last][$k] : 0;
            $tot = ['carry' => $last > 0 ? $c[1]['carry'] : 0, 'in' => $sum('in'), 'done' => $sum('done'), 'cancel' => $sum('cancel'),
                'open' => $at('open'), 'over' => $at('over'), 'ext' => $at('ext'), 'late' => $sum('late'), 'hrs' => $sum('hrs'), 'days' => $sum('days')];
            $stat[$g] = $tot;
            $line = static function (string $metric, callable $cell, mixed $total) use ($g, $last): array {
                $r = [t('rpt.grp.' . $g), $metric];
                for ($m = 1; $m <= 12; $m++) {
                    $r[] = $m <= $last ? $cell($m) : '-';
                }
                $r[] = $last > 0 ? $total : '-';
                return $r;
            };
            $simple = static fn(string $k): callable => static fn(int $m): int => (int)$c[$m][$k];
            $rate = static fn(array $x): string => self::pct($x['done'], $x['carry'] + $x['in'] - $x['cancel']);
            $avg = static fn(array $x): string => $x['done'] > 0 ? number_format($x['days'] / $x['done'], 1, '.', '') : '-';
            // 작업일지는 저장 즉시 정시 완료되므로 이월·완료율·지연 같은 지표는 SR 블록에만 둔다(전체 = SR + 작업일지).
            if ($g === 'sr') {
                $out[] = $line(t('rpt.m.carry'), $simple('carry'), $tot['carry']);
            }
            $out[] = $line(t('rpt.m.in'), $simple('in'), $tot['in']);
            if ($g === 'sr') {
                $out[] = $line(t('rpt.col.done'), $simple('done'), $tot['done']);
                $out[] = $line(t('rpt.m.cancel'), $simple('cancel'), $tot['cancel']);
                $out[] = $line(t('rpt.m.open'), $simple('open'), $tot['open']);
                $out[] = $line(t('rpt.col.open_over'), $simple('over'), $tot['over']);
                $out[] = $line(t('rpt.col.open_ext'), $simple('ext'), $tot['ext']);
                $out[] = $line(t('rpt.col.late_done'), $simple('late'), $tot['late']);
                $out[] = $line(t('rpt.col.done_rate'), static fn(int $m): string => $rate($c[$m]), $rate($tot));
                $out[] = $line(t('rpt.col.avg_days'), static fn(int $m): string => $avg($c[$m]), $avg($tot));
            }
            $out[] = $line(t('rpt.col.effort'), static fn(int $m): float => round($c[$m]['hrs'], 2), round($tot['hrs'], 2));
        }

        $share = static function (string $label, string $g, string $k) use ($cells, $last, $stat): array {
            $r = [t('rpt.share'), $label];
            for ($m = 1; $m <= 12; $m++) {
                $r[] = $m <= $last ? self::pct($cells[$g][$m][$k], $cells['all'][$m][$k]) : '-';
            }
            $r[] = $last > 0 ? self::pct($stat[$g][$k], $stat['all'][$k]) : '-';
            return $r;
        };
        $out[] = $share(t('rpt.share.in_sr'), 'sr', 'in');
        $out[] = $share(t('rpt.share.in_wl'), 'wl', 'in');
        $out[] = $share(t('rpt.share.eff_sr'), 'sr', 'hrs');
        $out[] = $share(t('rpt.share.eff_wl'), 'wl', 'hrs');

        $labels = [];
        $in = [];
        $done = [];
        for ($m = 1; $m <= $last; $m++) {
            $labels[] = t('rpt.col.month_n', ['n' => $m]);
            $in[] = (int)$cells['sr'][$m]['in'];
            $done[] = (int)$cells['sr'][$m]['done'];
        }
        $grp = t('rpt.grp.sr') . ' ';
        return [
            'head' => $head, 'rows' => $out, 'note' => t('rpt.note.monthly', ['year' => $y]),
            'chart' => self::chart('bar', $labels, [[$grp . t('rpt.m.in'), $in], [$grp . t('rpt.col.done'), $done]]),
        ];
    }

    // ------------------------------------------------------------------ 조건

    /** 기간 기준일: 요청일(request_date). 일반 요청은 최초 제출일, 작업일지는 입력한 요청일. created_at 는 값이 없는 예외 건의 안전망이다. */
    private const REQ_DATE = 'COALESCE(r.request_date, date(r.created_at))';

    private const FROM = ' FROM request r
        JOIN service s ON s.company_cd = r.company_cd AND s.service_id = r.service_id
        LEFT JOIN user ow ON ow.company_cd = r.company_cd AND ow.user_id = COALESCE(r.it_assignee_id, s.it_owner_id)';

    /** @return array{0:string,1:array<string,mixed>} */
    private static function where(array $f, string $scopeSql, array $scopeP, bool $period = true): array
    {
        $w = ['r.company_cd = :company_cd', $scopeSql];
        $p = $scopeP;
        if ($period) {
            $w[] = "r.status NOT IN ('DRAFT', 'CANCELED')";
            $w[] = self::REQ_DATE . ' BETWEEN :f_from AND :f_to';
            $p += ['f_from' => $f['from'], 'f_to' => $f['to']];
        }
        if ($f['service_id'] > 0) {
            $w[] = 'r.service_id = :f_service';
            $p['f_service'] = $f['service_id'];
        }
        if ($f['maint_type'] !== '') {
            $w[] = 'r.maint_type = :f_type';
            $p['f_type'] = $f['maint_type'];
        }
        if ($f['owner_id'] > 0) {
            $w[] = 'COALESCE(r.it_assignee_id, s.it_owner_id) = :f_owner';
            $p['f_owner'] = $f['owner_id'];
        }
        return [implode(' AND ', $w), $p];
    }

    private static function bucket(string $grain): string
    {
        $d = self::REQ_DATE;
        return match ($grain) {
            'year' => "strftime('%Y', $d)",
            'quarter' => "strftime('%Y', $d) || '-Q' || ((CAST(strftime('%m', $d) AS INTEGER) + 2) / 3)",
            default => "strftime('%Y-%m', $d)",
        };
    }

    /** @return array<string,string> */
    private static function typeNames(): array
    {
        $out = [];
        foreach (Repo::list('code', ['group_cd' => 'MAINT_TYPE'], 'sort_no, code_cd') as $c) {
            $out[(string)$c['code_cd']] = master_name('CODE', 'MAINT_TYPE:' . $c['code_cd'], (string)$c['code_nm']);
        }
        return $out;
    }

    private static function svcName(array $r): string
    {
        return master_name('SERVICE', (string)$r['service_cd'], (string)$r['service_nm']);
    }

    private static function pct(int|float $a, int|float $b): string
    {
        return $b > 0 ? number_format($a / $b * 100, 1, '.', '') : '-';
    }

    private static function num(mixed $v): string
    {
        return $v === null ? '-' : (string)(float)$v;
    }

    // ------------------------------------------------------------------ 절 구성

    /** 서비스/담당자 실적 표. */
    private static function agg(array $rows, string $firstHead, callable $label): array
    {
        $head = [t($firstHead), t('rpt.col.total'), t('rpt.col.done'), t('rpt.col.open'), t('rpt.col.done_rate'), t('rpt.col.avg_days'), t('rpt.col.effort'), t('rpt.col.late')];
        $out = [];
        $labels = [];
        $tot = [];
        $done = [];
        foreach ($rows as $r) {
            $out[] = [$label($r), (int)$r['total'], (int)$r['done'], (int)$r['open_cnt'], self::pct((int)$r['done'], (int)$r['total']),
                self::num($r['avg_days']), self::num($r['effort']), (int)$r['late']];
            $labels[] = $label($r);
            $tot[] = (int)$r['total'];
            $done[] = (int)$r['done'];
        }
        return ['head' => $head, 'rows' => $out, 'chart' => self::chart('bar', $labels, [[t('rpt.col.total'), $tot], [t('rpt.col.done'), $done]])];
    }

    /** @param array<string,string> $types */
    private static function typeSection(array $rows, array $types): array
    {
        $sum = array_sum(array_map(static fn(array $r): int => (int)$r['total'], $rows));
        $out = [];
        $labels = [];
        $data = [];
        foreach ($rows as $r) {
            $name = $types[$r['maint_type']] ?? (string)$r['maint_type'];
            $out[] = [$name, (int)$r['total'], self::pct((int)$r['total'], $sum)];
            $labels[] = $name;
            $data[] = (int)$r['total'];
        }
        return ['head' => [t('rpt.col.type'), t('rpt.col.total'), t('rpt.col.ratio')], 'rows' => $out, 'chart' => self::chart('doughnut', $labels, [[t('rpt.col.total'), $data]])];
    }

    /** @param array<string,string> $types */
    private static function cross(array $rows, array $types, string $firstHead, callable $label, string $key): array
    {
        $grid = [];
        $names = [];
        foreach ($rows as $r) {
            $k = (string)($r[$key] ?? '');
            $names[$k] = $label($r);
            $grid[$k][(string)$r['maint_type']] = (int)$r['n'];
        }
        $cols = array_keys($types);
        foreach ($rows as $r) {
            if (!in_array((string)$r['maint_type'], $cols, true)) {
                $cols[] = (string)$r['maint_type'];
            }
        }
        $head = array_merge([t($firstHead)], array_map(static fn(string $c): string => $types[$c] ?? $c, $cols), [t('rpt.col.total')]);
        $out = [];
        foreach ($grid as $k => $cells) {
            $line = [$names[$k]];
            foreach ($cols as $c) {
                $line[] = $cells[$c] ?? 0;
            }
            $line[] = array_sum($cells);
            $out[] = $line;
        }
        return ['head' => $head, 'rows' => $out, 'chart' => null];
    }

    private static function schedule(array $rows, string $firstHead, callable $label): array
    {
        $head = [
            t($firstHead), t('rpt.col.done'), t('rpt.col.late_done'), t('rpt.col.late_ext'), t('rpt.col.late_none'),
            t('rpt.col.late_days'), t('rpt.col.avg_late_days'), t('rpt.col.open_over'), t('rpt.col.open_ext'), t('rpt.col.changes'), t('rpt.col.agreed'),
        ];
        $out = [];
        $labels = [];
        $a = [];
        $b = [];
        foreach ($rows as $r) {
            $late = (int)$r['late_done'];
            $out[] = [
                $label($r), (int)$r['done'], $late, (int)$r['late_ext'], $late - (int)$r['late_ext'],
                round((float)$r['late_days'], 1), $late > 0 ? round((float)$r['late_days'] / $late, 1) : '-',
                (int)$r['open_over'], (int)$r['open_ext'], (int)$r['changes'], (int)$r['agreed'],
            ];
            $labels[] = $label($r);
            $a[] = (int)$r['late_ext'];
            $b[] = $late - (int)$r['late_ext'];
        }
        return ['head' => $head, 'rows' => $out, 'chart' => self::chart('bar', $labels, [[t('rpt.col.late_ext'), $a], [t('rpt.col.late_none'), $b]], true)];
    }

    private static function reasons(array $rows): array
    {
        $names = [];
        foreach (Repo::list('code', ['group_cd' => ScheduleService::REASON_GROUP]) as $c) {
            $names[(string)$c['code_cd']] = master_name('CODE', ScheduleService::REASON_GROUP . ':' . $c['code_cd'], (string)$c['code_nm']);
        }
        $sum = array_sum(array_map(static fn(array $r): int => (int)$r['n'], $rows));
        $out = [];
        $labels = [];
        $data = [];
        foreach ($rows as $r) {
            $n = $names[$r['reason_cd']] ?? (string)$r['reason_cd'];
            $out[] = [$n, (int)$r['n'], self::pct((int)$r['n'], $sum)];
            $labels[] = $n;
            $data[] = (int)$r['n'];
        }
        return ['head' => [t('sched.reason'), t('rpt.col.count'), t('rpt.col.ratio')], 'rows' => $out, 'chart' => self::chart('doughnut', $labels, [[t('rpt.col.count'), $data]])];
    }

    private static function bottleneck(array $rows): array
    {
        $out = [];
        $labels = [];
        $data = [];
        foreach ($rows as $r) {
            $proc = master_name('PROCESS', (string)$r['process_cd'], (string)$r['process_nm']);
            $step = $r['step_id'] !== null ? master_name('STEP', (string)$r['step_id'], (string)$r['step_nm']) : (string)$r['step_nm'];
            $out[] = [$proc, (int)$r['step_no'], $step, (int)$r['n'], (float)$r['avg_days'], (float)$r['max_days']];
            $labels[] = $proc . ' · ' . $step;
            $data[] = (float)$r['avg_days'];
        }
        return [
            'head' => [t('rpt.col.process'), t('rpt.col.step_no'), t('rpt.col.step'), t('rpt.col.count'), t('rpt.col.avg_stay'), t('rpt.col.max_stay')],
            'rows' => $out, 'chart' => self::chart('bar', $labels, [[t('rpt.col.avg_stay'), $data]]),
        ];
    }

    private static function period(array $rows, string $grain): array
    {
        $out = [];
        $labels = [];
        $a = [];
        $b = [];
        foreach ($rows as $r) {
            $out[] = [(string)$r['period'], (int)$r['total'], (int)$r['done'], self::pct((int)$r['done'], (int)$r['total'])];
            $labels[] = (string)$r['period'];
            $a[] = (int)$r['total'];
            $b[] = (int)$r['done'];
        }
        return [
            'head' => [t('rpt.grain.' . $grain), t('rpt.col.total'), t('rpt.col.done'), t('rpt.col.done_rate')], 'rows' => $out,
            'chart' => self::chart('line', $labels, [[t('rpt.col.total'), $a], [t('rpt.col.done'), $b]]),
        ];
    }

    private static function periodService(array $rows, array $periodRows): array
    {
        $periods = array_map(static fn(array $r): string => (string)$r['period'], $periodRows);
        $grid = [];
        $names = [];
        foreach ($rows as $r) {
            $names[(string)$r['service_cd']] = self::svcName($r);
            $grid[(string)$r['service_cd']][(string)$r['period']] = (int)$r['n'];
        }
        ksort($grid);
        $out = [];
        foreach ($grid as $cd => $cells) {
            $line = [$names[$cd]];
            foreach ($periods as $p) {
                $line[] = $cells[$p] ?? 0;
            }
            $line[] = array_sum($cells);
            $out[] = $line;
        }
        return ['head' => array_merge([t('rpt.col.service')], $periods, [t('rpt.col.total')]), 'rows' => $out, 'chart' => null];
    }

    /** @param list<array{0:string,1:list<int|float>}> $sets */
    private static function chart(string $type, array $labels, array $sets, bool $stacked = false): ?array
    {
        if ($labels === []) {
            return null;
        }
        return [
            'type' => $type, 'stacked' => $stacked, 'labels' => $labels,
            'datasets' => array_map(static fn(array $s): array => ['label' => $s[0], 'data' => $s[1]], $sets),
        ];
    }

    // ------------------------------------------------------------------ CSV

    /** 엑셀에서 수식으로 실행되지 않도록 위험한 시작 문자를 막는다. */
    public static function csvCell(mixed $v): string
    {
        $s = (string)$v;
        if ($s !== '' && $s !== '-' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s)) {
            $s = "'" . $s;
        }
        return '"' . str_replace('"', '""', $s) . '"';
    }

    /** UTF-8 BOM 포함 CSV 본문. @param array{head:list<string>,rows:list<list<mixed>>} $section */
    public static function csv(array $section): string
    {
        $lines = [implode(',', array_map([self::class, 'csvCell'], $section['head']))];
        foreach ($section['rows'] as $row) {
            $lines[] = implode(',', array_map([self::class, 'csvCell'], $row));
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }
}
