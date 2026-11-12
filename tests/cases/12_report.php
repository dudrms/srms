<?php
declare(strict_types=1);

use App\Core\Repo;
use App\Core\Scope;
use App\Services\ProcessService as PS;
use App\Services\ReportService;
use App\Services\RequestService as RS;
use App\Services\WorkflowService as WF;

/** 리포트 시나리오(고정 날짜): 기간 2026-01-01~2026-12-31 */
function rpt_world(): array
{
    $w = rw();
    $w['svc2'] = Repo::insert('service', ['service_cd' => 'HR', 'service_nm' => '인사', 'biz_dept_id' => $w['oth'], 'it_owner_id' => $w['it2'], 'use_yn' => 1]);
    PS::saveMapping([$w['svc2'] => ['MODIFY' => (string)pid_of('GENERAL'), 'BUGFIX' => (string)pid_of('URGENT')]]);
    $set = static function (int $rid, array $d): void {
        if (isset($d['created_at']) && !isset($d['request_date'])) {
            $d['request_date'] = substr((string)$d['created_at'], 0, 10); // 요청일(제출일)=작성일로 맞춘 고정 날짜
        }
        Repo::update('request', ['request_id' => $rid], $d);
    };
    $w['r1'] = live($w, ['title' => 'R1']);
    $set($w['r1'], ['status' => 'COMPLETED', 'created_at' => '2026-01-15 09:00:00', 'req_due_date' => '2026-02-01', 'actual_end' => '2026-01-25', 'effort_hours' => 2.5]);
    $w['r2'] = live($w, ['title' => 'R2']);
    $set($w['r2'], ['status' => 'COMPLETED', 'created_at' => '2026-01-20 09:00:00', 'req_due_date' => '2026-02-01', 'actual_end' => '2026-02-05']);
    $w['r3'] = live($w, ['title' => 'R3', 'maint_type' => 'BUGFIX']);
    $set($w['r3'], ['status' => 'COMPLETED', 'created_at' => '2026-02-03 09:00:00', 'req_due_date' => '2026-02-10', 'plan_end' => '2026-02-20', 'actual_end' => '2026-02-15', 'effort_hours' => 1.0]);
    $w['r4'] = live($w, ['title' => 'R4']);
    $set($w['r4'], ['created_at' => '2026-02-10 09:00:00', 'req_due_date' => day(-1), 'plan_end' => day(5)]);
    $w['r5'] = live($w, ['title' => 'R5', 'service_id' => (string)$w['svc2']]);
    $set($w['r5'], ['created_at' => '2026-03-01 09:00:00', 'req_due_date' => day(10)]);
    $w['r6'] = live($w, ['title' => 'R6']);
    WF::cancel($w['r6'], $w['req']);
    $set($w['r6'], ['created_at' => '2026-02-11 09:00:00']);
    $w['r7'] = mk_req($w, ['title' => 'R7']);
    $set($w['r7'], ['created_at' => '2026-02-12 09:00:00']);
    $stay = static function (int $rid, string $a, string $b): void {
        Repo::update('request_step', ['request_id' => $rid, 'step_no' => 2], ['status' => 'DONE', 'started_at' => $a, 'ended_at' => $b]);
    };
    $stay($w['r1'], '2026-01-16 00:00:00', '2026-01-18 12:00:00');
    $stay($w['r2'], '2026-01-21 00:00:00', '2026-01-22 12:00:00');
    $stay($w['r3'], '2026-02-04 00:00:00', '2026-02-05 00:00:00');
    foreach ([[$w['r3'], 'AGREED', 'RESOURCE'], [$w['r4'], 'REJECTED', 'TECH_ISSUE']] as [$rid, $st, $reason]) {
        Repo::insert('schedule_change', [
            'request_id' => $rid, 'step_no' => 2, 'prev_end' => '2026-02-10', 'new_end' => '2026-02-20', 'reason_cd' => $reason, 'reason_text' => 'x',
            'proposer_id' => $w['owner'], 'status' => $st, 'proposed_at' => '2026-02-05 10:00:00',
        ]);
    }
    return $w;
}

function rpt(int $uid, array $filter = []): array
{
    Scope::set('A01');
    [$u, , $p] = ctx_of($uid);
    $f = ReportService::filters($filter + ['from' => '2026-01-01', 'to' => '2026-12-31']);
    return ReportService::build($f, $u, $p, date('Y-m-d'));
}

function rpt_row(array $section, string $first): array
{
    foreach ($section['rows'] as $r) {
        if ($r[0] === $first) {
            return $r;
        }
    }
    throw new RuntimeException("row not found: $first");
}

test('report: 기간 기준은 요청일(최초 제출일)이며 작성일(created_at)이 아니다', function () {
    $w = rpt_world();
    // R5: 1월에 임시저장했지만 3월에 제출(요청일 2026-03-01)
    Repo::update('request', ['request_id' => $w['r5']], ['created_at' => '2026-01-02 09:00:00']);
    eq(1, rpt($w['owner'], ['from' => '2026-03-01', 'to' => '2026-03-31'])['summary']['total'], '3월 제출 건');
    eq(2, rpt($w['owner'], ['from' => '2026-01-01', 'to' => '2026-01-31'])['summary']['total'], '1월 제출은 R1, R2 뿐');
    // 평균 처리일도 요청일 기준: R2 요청일을 앞당기면 평균이 늘어난다
    $before = rpt($w['owner'])['summary']['avg_days'];
    Repo::update('request', ['request_id' => $w['r2']], ['request_date' => '2026-01-10']);
    ok(rpt($w['owner'])['summary']['avg_days'] > $before, '평균 처리일은 request_date 기준');
    // request_date 가 비어 있는 예외 건은 created_at 로 대신한다
    Repo::update('request', ['request_id' => $w['r1']], ['request_date' => null]);
    eq(2, rpt($w['owner'], ['from' => '2026-01-01', 'to' => '2026-01-31'])['summary']['total'], 'created_at 안전망');
});

test('report: 요약과 서비스/담당자/타입별 실적', function () {
    $w = rpt_world();
    $d = rpt($w['owner']);
    $s = $d['summary'];
    eq(5, $s['total'], '임시저장·취소 제외');
    eq(3, $s['done']);
    eq(2, $s['open']);
    eq(1, $s['canceled']);
    eq(3.5, $s['effort']);
    eq(3, $s['late'], 'R2, R3 지연 완료 + R4 기한 초과');
    // [서비스, 건수, 완료, 진행중, 완료율, 평균일수, 공수, 지연]
    eq(['ERP', 4, 3, 1, '75.0', '12.7', '3.5', 3], rpt_row($d['sections']['service'], 'ERP'));
    eq(['인사', 1, 0, 1, '0.0', '-', '0', 0], rpt_row($d['sections']['service'], '인사'));
    eq(['Owner', 4, 3, 1, '75.0', '12.7', '3.5', 3], rpt_row($d['sections']['owner'], 'Owner'));
    eq(1, rpt_row($d['sections']['owner'], 'It2')[1]);
    eq('타입', $d['sections']['type']['head'][0]);
    eq(['기능수정', 4, '80.0'], rpt_row($d['sections']['type'], '기능수정'));
    eq(['장애처리', 1, '20.0'], rpt_row($d['sections']['type'], '장애처리'));
    ok($d['sections']['service']['chart']['type'] === 'bar' && $d['sections']['type']['chart']['type'] === 'doughnut');
});

test('report: 교차 집계(서비스×타입, 담당자×타입)', function () {
    $w = rpt_world();
    $c = rpt($w['owner'])['sections'];
    eq(['서비스', '신규개발', '기능수정', '장애처리', '데이터작업', '기타', '건수'], $c['cross_service']['head']);
    eq(['ERP', 0, 3, 1, 0, 0, 4], rpt_row($c['cross_service'], 'ERP'));
    eq(['인사', 0, 1, 0, 0, 0, 1], rpt_row($c['cross_service'], '인사'));
    eq(['Owner', 0, 3, 1, 0, 0, 4], rpt_row($c['cross_owner'], 'Owner'));
});

test('report: 일정 준수/지연(합의 연장 구분), 변경 사유, 변경 횟수', function () {
    $w = rpt_world();
    $c = rpt($w['owner'])['sections'];
    // [서비스, 완료, 지연완료, 합의연장내, 합의없이, 지연일합, 평균지연일, 미완료초과, 연장중, 변경요청, 합의]
    eq(['ERP', 3, 2, 1, 1, 9.0, 4.5, 1, 1, 2, 1], rpt_row($c['schedule_service'], 'ERP'));
    eq(['인사', 0, 0, 0, 0, 0.0, '-', 0, 0, 0, 0], rpt_row($c['schedule_service'], '인사'));
    eq(2, rpt_row($c['schedule_owner'], 'Owner')[2]);
    eq(['리소스 부족', 1, '50.0'], rpt_row($c['reasons'], '리소스 부족'));
    eq(['기술 이슈', 1, '50.0'], rpt_row($c['reasons'], '기술 이슈'));
    // 철회된 제안은 사유 분포에서 제외
    Repo::update('schedule_change', ['request_id' => $w['r4']], ['status' => 'WITHDRAWN']);
    eq(1, count(rpt($w['owner'])['sections']['reasons']['rows']));
});

test('report: 프로세스 병목(단계별 평균 체류시간)', function () {
    $w = rpt_world();
    $b = rpt($w['owner'])['sections']['bottleneck'];
    eq(['일반 유지보수', 2, '검토', 2, 2.0, 2.5], rpt_row($b, '일반 유지보수'));
    eq(['긴급 장애', 2, '승인', 1, 1.0, 1.0], rpt_row($b, '긴급 장애'));
    eq(2, count($b['rows']), '제출 단계·진행 중 단계는 제외');
});

test('report: 기간별 추이와 서비스×기간', function () {
    $w = rpt_world();
    $p = rpt($w['owner'])['sections'];
    eq(['2026-01', 2, 2, '100.0'], $p['period']['rows'][0]);
    eq(['2026-02', 2, 1, '50.0'], $p['period']['rows'][1]);
    eq(['2026-03', 1, 0, '0.0'], $p['period']['rows'][2]);
    eq(['서비스', '2026-01', '2026-02', '2026-03', '건수'], $p['period_service']['head']);
    eq(['ERP', 2, 2, 0, 4], rpt_row($p['period_service'], 'ERP'));
    $q = rpt($w['owner'], ['grain' => 'quarter'])['sections']['period'];
    eq(['2026-Q1', 5, 3, '60.0'], $q['rows'][0]);
    $y = rpt($w['owner'], ['grain' => 'year'])['sections']['period'];
    eq(['2026', 5, 3, '60.0'], $y['rows'][0]);
});

test('report: 필터(기간/서비스/타입/담당자)와 입력 정규화', function () {
    $w = rpt_world();
    eq(4, rpt($w['owner'], ['to' => '2026-02-28'])['summary']['total'], '3월 건 제외');
    eq(1, rpt($w['owner'], ['service_id' => (string)$w['svc2']])['summary']['total']);
    eq(1, rpt($w['owner'], ['maint_type' => 'BUGFIX'])['summary']['total']);
    eq(1, rpt($w['owner'], ['owner_id' => (string)$w['it2']])['summary']['total']);
    eq(0, rpt($w['owner'], ['maint_type' => 'NOPE'])['summary']['total']);
    $f = ReportService::filters(['from' => '2026-05-01', 'to' => '2026-01-01', 'grain' => 'x', 'service_id' => '1; DROP', 'owner_id' => '-3'], '2026-11-09');
    eq(['2026-01-01', '2026-05-01', 'month', 0, 0], [$f['from'], $f['to'], $f['grain'], $f['service_id'], $f['owner_id']], '순서 바로잡기/잘못된 값은 무시');
    $f = ReportService::filters([], '2026-11-09');
    eq(['2026-01-01', '2026-11-09'], [$f['from'], $f['to']], '기본은 올해 초~오늘');
    $f = ReportService::filters(['from' => '2026-02-30', 'to' => 'abc'], '2026-11-09');
    eq(['2026-01-01', '2026-11-09'], [$f['from'], $f['to']]);
});

test('report: 데이터 범위(전체/주관부서/그 외)와 회사 격리', function () {
    $w = rpt_world();
    eq(5, rpt($w['owner'])['summary']['total'], '전산팀은 전체');
    $b = rpt($w['bizit']);
    eq(4, $b['summary']['total'], '주관부서(ERP)만');
    eq(['ERP'], array_column($b['sections']['service']['rows'], 0));
    $o = rpt($w['othbiz']);
    eq(['인사'], array_column($o['sections']['service']['rows'], 0));
    eq(0, rpt($w['req'])['summary']['total'], '범위 없는 사용자는 빈 결과');
    Scope::set('B01');
    $admin = Repo::find('user', ['login_id' => 'admin']);
    [$u, , $p] = ctx_of((int)$admin['user_id']);
    $d = ReportService::build(ReportService::filters(['from' => '2026-01-01', 'to' => '2026-12-31']), $u, $p);
    eq(0, $d['summary']['total']);
    eq([], $d['sections']['service']['rows']);
});

test('report: CSV(BOM, 인용, 수식 주입 방지)', function () {
    eq('"abc"', ReportService::csvCell('abc'));
    eq('"a""b"', ReportService::csvCell('a"b'));
    eq('"\'=SUM(1)"', ReportService::csvCell('=SUM(1)'));
    eq('"\'+cmd"', ReportService::csvCell('+cmd'));
    eq('"\'@x"', ReportService::csvCell('@x'));
    eq('"-5"', ReportService::csvCell(-5), '음수 숫자는 그대로');
    eq('"-"', ReportService::csvCell('-'), '빈 값 표시는 그대로');
    eq('"\'-1+1"', ReportService::csvCell('-1+1'));
    eq('"가,나"', ReportService::csvCell('가,나'));
    $csv = ReportService::csv(['head' => ['a', 'b'], 'rows' => [['x', 1], ['=1+1', '2']]]);
    ok(str_starts_with($csv, "\xEF\xBB\xBF\"a\",\"b\"\r\n"));
    ok(str_contains($csv, "\"'=1+1\",\"2\"\r\n"));
});

/** 월간 현황표 고정 시나리오(2026, 오늘=2026-05-20): rpt_world 의 R1~R6 에 날짜를 못 박고 작업일지 W1 을 더한다. */
function rpt_month_world(): array
{
    $w = rpt_world();
    Repo::update('request', ['request_id' => $w['r4']], ['req_due_date' => '2026-03-01', 'plan_end' => '2026-05-31']);
    Repo::update('request', ['request_id' => $w['r5']], ['req_due_date' => '2026-12-31']);
    Repo::update('request', ['request_id' => $w['r6']], ['canceled_at' => '2026-04-10', 'req_due_date' => '2026-12-31']);
    $w['w1'] = live($w, ['title' => 'W1']);
    Repo::update('request', ['request_id' => $w['w1']], [
        'status' => 'COMPLETED', 'reg_by' => $w['owner'], 'request_date' => '2026-02-20', 'req_due_date' => '2026-02-20',
        'actual_end' => '2026-02-20', 'effort_hours' => 0.5, 'created_at' => '2026-02-20 09:00:00',
    ]);
    $w['r8'] = mk_req($w, ['title' => 'R8']);   // 제출하지 않고 취소한 건은 접수에 잡히지 않는다
    WF::cancel($w['r8'], $w['req']);
    return $w;
}

function rpt_month(array $w, array $filter = [], ?int $uid = null, string $today = '2026-05-20'): array
{
    Scope::set('A01');
    [$u, , $p] = ctx_of($uid ?? $w['owner']);
    $f = ReportService::filters($filter + ['from' => '2026-01-01', 'to' => '2026-12-31', 'year' => '2026']);
    return ReportService::build($f, $u, $p, $today)['sections']['monthly'];
}

/** @return array{0:list<mixed>,1:mixed} 1~12월 값과 합계 */
function mrow(array $sec, string $group, string $metric): array
{
    foreach ($sec['rows'] as $r) {
        if ($r[0] === $group && $r[1] === $metric) {
            return [array_slice($r, 2, 12), $r[14]];
        }
    }
    throw new RuntimeException("monthly row not found: $group / $metric");
}

test('report 월간: 접수·완료·취소·이월·진행을 발생월로 나눠 SR/작업일지/전체로 센다', function () {
    $w = rpt_month_world();
    $m = rpt_month($w);
    eq(['구분', '지표', '1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월', '합계'], $m['head']);
    $dash = array_fill(0, 7, '-');
    $row = static fn(string $g, string $k): array => mrow($m, $g, $k);
    // SR: R1~R6 (R6 은 4월 취소, R8 임시저장 취소는 제외)
    eq([[0, 1, 2, 3, 2, ...$dash], 0], $row('SR', '이월'));
    eq([[2, 3, 1, 0, 0, ...$dash], 6], $row('SR', '접수'));
    eq([[1, 2, 0, 0, 0, ...$dash], 3], $row('SR', '완료'));
    eq([[0, 0, 0, 1, 0, ...$dash], 1], $row('SR', '취소'));
    eq([[1, 2, 3, 2, 2, ...$dash], 2], $row('SR', '진행(기말)'));
    eq([[0, 0, 1, 1, 1, ...$dash], 1], $row('SR', '미완료 기한 초과'));
    eq([[0, 0, 1, 1, 1, ...$dash], 1], $row('SR', '(합의 연장 중)'));
    eq([[0, 2, 0, 0, 0, ...$dash], 2], $row('SR', '지연 완료'));
    eq([['50.0', '50.0', '0.0', '0.0', '0.0', ...$dash], '60.0'], $row('SR', '완료율(%)'));
    eq([['10.0', '14.0', '-', '-', '-', ...$dash], '12.7'], $row('SR', '평균 처리일수'));
    eq([[2.5, 1.0, 0.0, 0.0, 0.0, ...$dash], 3.5], $row('SR', '투입시간(h)'));
    // 작업일지: 저장 즉시 정시 완료이므로 접수와 투입시간만 남긴다(접수는 요청일 월)
    eq([[0, 1, 0, 0, 0, ...$dash], 1], $row('작업일지', '접수'));
    eq([[0.0, 0.5, 0.0, 0.0, 0.0, ...$dash], 0.5], $row('작업일지', '투입시간(h)'));
    // 작업일지·전체 블록에는 접수와 투입시간 행만 있다
    foreach (['작업일지', '전체'] as $g) {
        eq(['접수', '투입시간(h)'], array_column(array_filter($m['rows'], static fn(array $r): bool => $r[0] === $g), 1), "$g 블록 행");
    }
    // 전체 = SR + 작업일지
    eq([[2, 4, 1, 0, 0, ...$dash], 7], $row('전체', '접수'));
    eq([[2.5, 1.5, 0.0, 0.0, 0.0, ...$dash], 4.0], $row('전체', '투입시간(h)'));
    // 비중: 접수·투입시간 중 SR/작업일지
    eq([['100.0', '75.0', '100.0', '-', '-', ...$dash], '85.7'], $row('비중', '접수 SR(%)'));
    eq([['0.0', '25.0', '0.0', '-', '-', ...$dash], '14.3'], $row('비중', '접수 작업일지(%)'));
    eq([['100.0', '66.7', '-', '-', '-', ...$dash], '87.5'], $row('비중', '투입시간 SR(%)'));
    eq([['0.0', '33.3', '-', '-', '-', ...$dash], '12.5'], $row('비중', '투입시간 작업일지(%)'));
    ok(str_contains($m['note'], '2026') && $m['chart']['type'] === 'bar' && count($m['chart']['labels']) === 5);
    eq([2, 3, 1, 0, 0], $m['chart']['datasets'][0]['data'], '차트는 SR 접수');
    eq(['SR 접수', 'SR 완료'], array_column($m['chart']['datasets'], 'label'));
});

test('report 월간: 이월+접수-완료-취소=진행(기말)이고 진행은 다음 달 이월과 같다', function () {
    $w = rpt_month_world();
    foreach ([rpt_month($w), rpt_month($w, [], null, '2026-12-31'), rpt_month($w, ['year' => '2025'], null, '2026-12-31')] as $m) {
        foreach (['SR'] as $g) {
            [$carry] = mrow($m, $g, '이월');
            [$in] = mrow($m, $g, '접수');
            [$done] = mrow($m, $g, '완료');
            [$cancel] = mrow($m, $g, '취소');
            [$open] = mrow($m, $g, '진행(기말)');
            for ($i = 0; $i < 12; $i++) {
                if ($carry[$i] === '-') {
                    continue;
                }
                eq($carry[$i] + $in[$i] - $done[$i] - $cancel[$i], $open[$i], "$g " . ($i + 1) . '월 항등식');
                if ($i < 11 && $carry[$i + 1] !== '-') {
                    eq($open[$i], $carry[$i + 1], "$g " . ($i + 1) . '월 진행 = 다음 달 이월');
                }
            }
        }
    }
    // 연말까지 오늘이 지나가면 기한 초과가 늘어난다: R5(완료요구일 2026-12-31)는 12월 말에도 넘지 않았다
    [$over, $tot] = mrow(rpt_month($w, [], null, '2026-12-31'), 'SR', '미완료 기한 초과');
    eq([1, 1], [$over[11], $tot]);
    // 이전 연도는 모두 0, 아직 오지 않은 연도는 모두 '-'
    [$in25, $t25] = mrow(rpt_month($w, ['year' => '2025']), 'SR', '접수');
    eq([array_fill(0, 12, 0), 0], [$in25, $t25]);
    [$in27, $t27] = mrow(rpt_month($w, ['year' => '2027']), 'SR', '접수');
    eq([array_fill(0, 12, '-'), '-'], [$in27, $t27]);
});

test('report 월간: 기간·단위 필터와 무관하고 서비스/타입/담당자 필터와 데이터 범위는 적용된다', function () {
    $w = rpt_month_world();
    $all = mrow(rpt_month($w), 'SR', '접수');
    eq($all, mrow(rpt_month($w, ['from' => '2026-03-01', 'to' => '2026-03-31', 'grain' => 'year']), 'SR', '접수'), '기간 필터 무시');
    eq($all, mrow(rpt_month($w, ['year' => '2026', 'to' => '2025-12-31', 'from' => '2025-01-01']), 'SR', '접수'), 'year 지정 시 기간과 무관');
    [$svc] = mrow(rpt_month($w, ['service_id' => (string)$w['svc2']]), 'SR', '접수');
    eq([0, 0, 1, 0, 0], array_slice($svc, 0, 5), '인사 서비스는 R5 한 건');
    [$bug] = mrow(rpt_month($w, ['maint_type' => 'BUGFIX']), 'SR', '접수');
    eq([0, 1, 0, 0, 0], array_slice($bug, 0, 5), '장애처리는 R3');
    [$own] = mrow(rpt_month($w, ['owner_id' => (string)$w['it2']]), 'SR', '접수');
    eq([0, 0, 1, 0, 0], array_slice($own, 0, 5), 'it2 담당은 R5');
    [$biz] = mrow(rpt_month($w, [], $w['bizit']), 'SR', '접수');
    eq([2, 3, 0, 0, 0], array_slice($biz, 0, 5), '주관부서(ERP)만: R5(인사) 제외');
    [$none] = mrow(rpt_month($w, [], $w['req']), 'SR', '접수');
    eq(array_fill(0, 5, 0), array_slice($none, 0, 5), '범위 없는 사용자는 0');
    // year 입력 정규화: 잘못된 값은 종료일의 연도
    eq(2026, ReportService::filters(['year' => 'x', 'to' => '2026-05-01'])['year']);
    eq(2026, ReportService::filters(['year' => '1999', 'to' => '2026-05-01'])['year']);
    eq(2031, ReportService::filters(['year' => '2031'])['year']);
});

test('report 월간: CSV 내보내기와 화면(연도 입력, 안내문)', function () {
    $w = rpt_month_world();
    as_user('owner');
    $r = http('GET', '/reports/export/monthly?year=2026');
    eq(200, $r->status);
    ok(str_starts_with($r->body, "\xEF\xBB\xBF\"구분\",\"지표\",\"1월\""));
    ok(str_contains($r->body, '"SR","접수"') && str_contains($r->body, '"작업일지","접수"') && str_contains($r->body, '"비중","접수 SR(%)"'));
    $p = http('GET', '/reports?year=2026');
    eq(200, $p->status);
    ok(body_has($p, 'rpt.sec.monthly') && str_contains($p->body, 'name="year"') && str_contains($p->body, '2026년 1~12월'));
    // 기본은 월간 현황 탭: 상세 표와 기간 입력은 없고, 적용 조건과 기준 설명이 있다
    ok(!body_has($p, 'rpt.sec.service') && !str_contains($p->body, 'type="date"') && str_contains($p->body, e(t('rpt.def.m2'))) && str_contains($p->body, e(t('rpt.applied'))));
    ok(str_contains($p->body, 'type="hidden" name="from"'), '다른 탭 값 유지');
    ok(str_contains($p->body, 'tab=detail') && str_contains($p->body, 'nav-link active'), '탭 링크');
    // 상세 실적 탭: 기간·추이 표 단위 입력, 월간 현황표와 연도 입력은 없다
    $d = http('GET', '/reports?tab=detail&year=2026&service_id=' . $w['svc2']);
    eq(200, $d->status);
    ok(body_has($d, 'rpt.sec.service') && !body_has($d, 'rpt.sec.monthly') && str_contains($d->body, 'type="date"') && str_contains($d->body, 'name="grain"'));
    ok(str_contains($d->body, 'type="hidden" name="year"') && str_contains($d->body, e(t('rpt.def.d1'))));
    ok(str_contains($d->body, '인사') && str_contains($d->body, e(t('request.service'))), '적용 조건에 서비스 이름');
    eq(200, http('GET', '/reports?tab=nope')->status, '알 수 없는 탭은 월간 현황');
});

test('http: 리포트 화면/CSV 권한, 이스케이프, 다국어', function () {
    $w = rpt_world();
    Repo::update('service', ['service_id' => $w['svc2']], ['service_nm' => '<b>인사</b>=1']);
    foreach (['req', 'other'] as $u) {
        as_user($u);
        eq(403, http('GET', '/reports')->status, $u);
        eq(403, http('GET', '/reports/export/service')->status, $u);
    }
    http('GET', '/');
    $_SESSION = [];
    eq(302, http('GET', '/reports')->status, '비로그인');
    as_user('owner');
    $r = http('GET', '/reports?tab=detail&from=2026-01-01&to=2026-12-31');
    eq(200, $r->status);
    ok(str_contains($r->body, 'data-chart=') && str_contains($r->body, 'vendor/chartjs/chart.umd.min.js') && str_contains($r->body, 'js/report.js'));
    ok(body_has($r, 'rpt.sec.service') && body_has($r, 'rpt.sec.bottleneck'));
    ok(!str_contains($r->body, '<b>인사</b>') && str_contains($r->body, '&lt;b&gt;인사&lt;/b&gt;'), '서비스명 이스케이프');
    ok(!preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $r->body), '인라인 스크립트 없음');
    // CSV
    $c = http('GET', '/reports/export/service?from=2026-01-01&to=2026-12-31');
    eq(200, $c->status);
    ok(str_contains($c->headers['Content-Type'], 'text/csv') && str_contains($c->headers['Content-Disposition'], 'attachment; filename="srms_service_'));
    ok(str_starts_with($c->body, "\xEF\xBB\xBF"));
    ok(str_contains($c->body, '"ERP","4","3","1","75.0","12.7","3.5","3"'));
    eq(404, http('GET', '/reports/export/nope')->status);
    eq(404, http('GET', '/reports/export/..%2Fetc')->status);
    // 주관부서 담당자는 자기 부서 서비스만
    as_user('bizit');
    $r = http('GET', '/reports?tab=detail&from=2026-01-01&to=2026-12-31');
    ok(str_contains($r->body, 'ERP') && !str_contains($r->body, '인사'), '부서 범위');
    // 다국어 + 다른 회사
    as_user('owner');
    foreach (['ja', 'en'] as $lang) {
        http('POST', '/lang', ['lang' => $lang, 'back' => '/']);
        $r = http('GET', '/reports?tab=detail');
        eq(200, $r->status, $lang);
        ok(str_contains($r->body, e(App\Core\I18n::load($lang)['rpt.sec.service'])), $lang);
    }
    App\Core\I18n::set('ko');
    as_admin('B01', 'Admin5678');
    $r = http('GET', '/reports?tab=detail&from=2026-01-01&to=2026-12-31');
    eq(200, $r->status);
    ok(!str_contains($r->body, 'ERP'), '다른 회사 데이터 없음');
});

test('request: 투입시간은 작업 단계 처리자만 입력', function () {
    $w = rw();
    $rid = live($w);
    $req = rrow($rid);
    $svc = WF::service($req);
    ok(RS::canSetEffort($req, $svc, $w['owner']), '서비스 전산 담당(작업 단계 처리자)');
    ok(!RS::canSetEffort($req, $svc, $w['bizit']));
    ok(!RS::canSetEffort($req, $svc, $w['req']));
    perr_p(fn() => RS::setEffort($rid, $w['bizit'], '3'), 'request.err_effort_forbidden');
    foreach (['', 'abc', '-1', '1.234', '12345', '10000', '1,5'] as $bad) {
        perr_p(fn() => RS::setEffort($rid, $w['owner'], $bad), 'request.err_effort');
    }
    RS::setEffort($rid, $w['owner'], '3.5');
    eq(3.5, (float)rrow($rid)['effort_hours']);
    RS::setEffort($rid, $w['owner'], '0.25');
    eq(0.25, (float)rrow($rid)['effort_hours'], '15분 = 0.25시간 그대로 저장');
    RS::setEffort($rid, $w['owner'], '9999.99');
    eq(9999.99, (float)rrow($rid)['effort_hours'], '상한');
    RS::setEffort($rid, $w['owner'], '0');
    eq(0.0, (float)rrow($rid)['effort_hours']);
    $draft = mk_req($w);
    ok(!RS::canSetEffort(rrow($draft), $svc, $w['owner']), '임시저장은 불가');
    as_user('bizit');
    http('POST', "/requests/$rid/effort", ['effort_hours' => '9']);
    eq(t('request.err_effort_forbidden'), last_flash());
    as_user('owner');
    $page = http('GET', "/requests/$rid")->body;
    ok(str_contains($page, 'name="effort_hours"') && str_contains($page, e(t('effort.unit_h'))) && !str_contains($page, 'effort_unit') && !str_contains($page, 'data-effort'), '시간 입력란(단위 선택 없음)');
    eq(403, http('POST', "/requests/$rid/effort", ['effort_hours' => '9'], false)->status, 'CSRF');
    http('POST', "/requests/$rid/effort", ['effort_hours' => '9.5']);
    eq(t('common.saved'), last_flash());
    Scope::set('A01');
    eq(9.5, (float)rrow($rid)['effort_hours']);
    ok(str_contains(http('GET', "/requests/$rid")->body, '9.5' . t('effort.unit_h')), '저장된 값은 시간으로 표시');
    http('POST', "/requests/$rid/effort", ['effort_hours' => 'x']);
    eq(t('request.err_effort'), last_flash());
    as_user('req');
    ok(!str_contains(http('GET', "/requests/$rid")->body, 'name="effort_hours"'), '요청자에게는 입력란 없음');
});

test('투입시간: 입력 검증과 표시 형식·저장 컨럼', function () {
    eq([2.0, 0.25, 0.01, 9999.99, 0.0, 12.5], [
        App\Services\Effort::parse('2'), App\Services\Effort::parse('0.25'), App\Services\Effort::parse(' 0.01 '),
        App\Services\Effort::parse('9999.99'), App\Services\Effort::parse('0'), App\Services\Effort::parse('12.50'),
    ]);
    foreach (['', ' ', 'abc', '-1', '1,5', '1.234', '10000', '1e3', '.5'] as $bad) {
        eq(null, App\Services\Effort::parse($bad), "'$bad'");
    }
    eq(['0.25', '3.5', '2', '0', '-', '1.23'], [
        App\Services\Effort::fmt(0.25), App\Services\Effort::fmt(3.5), App\Services\Effort::fmt(2.0), App\Services\Effort::fmt(0),
        App\Services\Effort::fmt(null), App\Services\Effort::fmt(1.234),
    ], '표시 형식(뒤쪽 0 생략, 소수 둘째 자리)');
    $cols = array_column(App\Core\Db::pdo()->query('PRAGMA table_info(request)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    ok(in_array('effort_hours', $cols, true) && !in_array('effort_md', $cols, true), '마이그레이션 011: effort_md 제거, effort_hours 추가');
});
