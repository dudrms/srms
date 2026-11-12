<?php
declare(strict_types=1);

// 검증/교육용 데모 데이터 생성: php bin/demo.php [--db=storage/db/demo.sqlite] [--reset] [--no-samples]
// 개발·시연 전용(운영 환경에서는 실행 거부). 모든 데모 계정의 비밀번호는 Demo1234 이다.

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Db;
use App\Core\Env;
use App\Core\Migrator;
use App\Core\Repo;
use App\Core\Scope;
use App\Services\CompanySetup;
use App\Services\ProcessService;
use App\Services\RequestService as RS;
use App\Services\ScheduleService;
use App\Services\SplitService;
use App\Services\Translations;
use App\Services\Users;
use App\Services\WorkflowService as WF;
use App\Services\WorklogService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (env('APP_ENV', 'development') === 'production') {
    fwrite(STDERR, "ERROR: 운영 환경(APP_ENV=production)에서는 데모 데이터를 만들 수 없습니다.\n");
    exit(1);
}

$opt = getopt('', ['db::', 'reset', 'no-samples']);
$db = is_string($opt['db'] ?? null) ? $opt['db'] : 'storage/db/demo.sqlite';
Env::set('DB_PATH', $db);

$file = Db::path();
if (is_file($file)) {
    if (!isset($opt['reset'])) {
        fwrite(STDERR, "ERROR: $file 이(가) 이미 있습니다. 새로 만들려면 --reset 을 붙이세요.\n");
        exit(1);
    }
    foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
}

const DEMO_PW = 'Demo1234';
const CO = 'DEMO';

Migrator::run();
CompanySetup::provision(CO, '데모 주식회사', 'ko', 'admin', '관리자', 'admin@example.com', DEMO_PW);
Scope::set(CO);

// 조직: 전산팀(시드) + 현업 2개 부서
$sales = Repo::insert('dept', ['dept_nm' => '영업팀', 'is_it' => 0, 'use_yn' => 1]);
$hr = Repo::insert('dept', ['dept_nm' => '인사팀', 'is_it' => 0, 'use_yn' => 1]);
$it = (int)Repo::find('dept', ['is_it' => 1])['dept_id'];

$mk = static fn(string $id, string $name, int $dept, string $role): int
    => Users::create($id, $name, $id . '@example.com', $dept, 'ko', DEMO_PW, [$role]);
$u = [
    'req1' => $mk('req1', '김요청', $sales, 'REQUESTER'),
    'req2' => $mk('req2', '박인사', $hr, 'REQUESTER'),
    'bizit1' => $mk('bizit1', '최현업전산', $sales, 'BIZ_IT'),
    'bizit2' => $mk('bizit2', '윤현업전산', $hr, 'BIZ_IT'),
    'it1' => $mk('it1', '정전산', $it, 'IT_TEAM'),
    'it2' => $mk('it2', '한전산', $it, 'IT_TEAM'),
];

// 서비스(주관부서/전산 담당자) + 번역
$erp = Repo::insert('service', ['service_cd' => 'ERP', 'service_nm' => '전사 ERP', 'biz_dept_id' => $sales, 'it_owner_id' => $u['it1'], 'use_yn' => 1]);
$hrs = Repo::insert('service', ['service_cd' => 'HRS', 'service_nm' => '인사시스템', 'biz_dept_id' => $hr, 'it_owner_id' => $u['it2'], 'use_yn' => 1]);
Translations::save('SERVICE', 'ERP', ['ja' => '全社ERP', 'en' => 'Corporate ERP', 'zh' => '全公司ERP']);
Translations::save('SERVICE', 'HRS', ['ja' => '人事システム', 'en' => 'HR System', 'zh' => '人事系统']);
// 현업 IT 담당자(서비스 주관부서 소속 BIZ_IT 중 지정)
Repo::insert('service_biz_it', ['service_id' => $erp, 'user_id' => $u['bizit1']]);
Repo::insert('service_biz_it', ['service_id' => $hrs, 'user_id' => $u['bizit2']]);

// 서비스 × 타입 → 프로세스(긴급 장애만 긴급 프로세스)
$pid = static fn(string $cd): string => (string)Repo::find('process_master', ['process_cd' => $cd])['process_id'];
$map = [];
foreach ([$erp, $hrs] as $sid) {
    foreach (['NEW_DEV', 'MODIFY', 'DATA', 'ETC'] as $t) {
        $map[$sid][$t] = $pid('GENERAL');
    }
    $map[$sid]['BUGFIX'] = $pid('URGENT');
}
ProcessService::saveMapping($map);

echo "DB       : $file\n";
echo "회사     : " . CO . " (데모 주식회사)\n";

if (!isset($opt['no-samples'])) {
    $day = static fn(int $n): string => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
    $req = static fn(int $by, int $svc, string $type, string $title, string $prio, int $dueDays): int => RS::save(null, $by, [
        'service_id' => (string)$svc, 'maint_type' => $type, 'title' => $title, 'priority' => $prio,
        'content_html' => '<p>' . htmlspecialchars($title, ENT_QUOTES) . ' 관련 데모 요청입니다.</p>', 'req_due_date' => $day($dueDays),
    ]);
    $pass = static function (int $rid, int ...$users): void {
        foreach ($users as $uid) {
            WF::act($rid, $uid, 'PASS', '');
        }
    };

    // S1 완료 건(투입공수 포함): 검토→승인→수행→검수→종료
    $r = $req($u['req1'], $erp, 'MODIFY', '[데모] 주문 화면 검색 조건 추가', 'NORMAL', 20);
    WF::submit($r, $u['req1']);
    $pass($r, $u['it1'], $u['bizit1'], $u['it1']);
    RS::setEffort($r, $u['it1'], '28');
    $pass($r, $u['req1'], $u['it2']);

    // S2 검토 대기(처리함 데모)
    $r = $req($u['req2'], $hrs, 'MODIFY', '[데모] 급여 명세서 양식 변경', 'NORMAL', 15);
    WF::submit($r, $u['req2']);

    // S3 수행 중 + 일정 변경 협의 대기(요청자 회신 필요)
    $r = $req($u['req1'], $erp, 'NEW_DEV', '[데모] 재고 현황 대시보드 신규 개발', 'HIGH', 14);
    WF::submit($r, $u['req1']);
    $pass($r, $u['it1'], $u['bizit1']);
    ScheduleService::propose($r, $u['it1'], $day(24), 'RESOURCE', '선행 프로젝트 지연으로 투입 인력이 부족합니다.');

    // S4 반려(보완요청) 건
    $r = $req($u['req2'], $hrs, 'DATA', '[데모] 퇴사자 데이터 정리', 'LOW', 25);
    WF::submit($r, $u['req2']);
    WF::act($r, $u['it2'], 'REJECT', '대상 기간과 범위를 구체적으로 적어 주세요.');

    // S5 긴급 장애 수행 중 + 완료요구일 경과(지연 표시)
    $r = $req($u['req1'], $erp, 'BUGFIX', '[데모] 주문 마감 배치 오류', 'URGENT', 3);
    WF::submit($r, $u['req1']);
    $pass($r, $u['it1']);
    Repo::update('request', ['request_id' => $r], ['req_due_date' => $day(-2)]);

    // S6 임시저장
    $req($u['req2'], $hrs, 'ETC', '[데모] 임시 저장된 요청', 'NORMAL', 30);

    // S7, S8 작업일지(IT 담당자가 전화·메일 요청을 사후 등록, 승인 없이 완료)
    foreach ([
        [$u['it1'], $u['req1'], $erp, 'PHONE', '[데모] 주문 조회 속도 문의 후 인덱스 점검', -6, -5, '4'],
        [$u['it2'], $u['req2'], $hrs, 'MAIL', '[데모] 급여 대장 재출력', -3, -3, '8'],
    ] as [$by, $who, $svc, $ch, $title, $from, $to, $hours]) {
        WorklogService::save(null, $by, [
            'request_date' => $day($from), 'actual_end' => $day($to), 'requester_id' => (string)$who, 'service_id' => (string)$svc,
            'channel' => $ch, 'title' => $title, 'effort_hours' => $hours,
            'content_html' => '<p>' . htmlspecialchars($title, ENT_QUOTES) . ' — 사후 등록한 데모 작업일지입니다.</p>',
        ]);
    }

    // S9 SR 분할(요청 1건이 두 명의 IT 담당자가 필요해 전산팀이 검토 단계에서 분할)
    $r = $req($u['req1'], $erp, 'MODIFY', '[데모] 주문·재고 화면 문구 일괄 수정', 'NORMAL', 18);
    WF::submit($r, $u['req1']);
    SplitService::split($r, $u['it1'], [
        'title' => '[데모] 재고 화면 문구 수정(분할)', 'note' => '재고 화면 부분은 한전산이 맡습니다.',
        'new_assignee_id' => (string)$u['it2'], 'orig_assignee_id' => '',
    ]);

    echo "샘플 요청: 6건(완료 1, 검토대기 1, 일정협의 1, 보완요청 1, 긴급·지연 1, 임시저장 1) + 작업일지 2건 + SR 분할 1쌍\n";
}

echo "\n계정(모두 비밀번호 " . DEMO_PW . ", 회사 " . CO . ")\n";
foreach ([
    ['admin', '시스템관리자'], ['req1', '현업 사용자(영업팀)'], ['req2', '현업 사용자(인사팀)'],
    ['bizit1', '현업 전산담당(영업팀, ERP 승인)'], ['bizit2', '현업 전산담당(인사팀, HRS 승인)'],
    ['it1', '전산팀(ERP 담당자)'], ['it2', '전산팀(HRS 담당자)'],
] as [$id, $desc]) {
    echo sprintf("  %-7s %s\n", $id, $desc);
}
