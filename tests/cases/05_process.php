<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\I18n;
use App\Core\Repo;
use App\Core\Scope;
use App\Services\ProcessError;
use App\Services\ProcessService as PS;
use App\Services\Seeder;
use App\Services\Users;

function perr(callable $fn, string $key): void
{
    try {
        $fn();
    } catch (ProcessError $e) {
        eq($key, $e->key);
        return;
    }
    throw new RuntimeException("expected ProcessError $key");
}

function pid_of(string $code): int
{
    return (int)Repo::find('process_master', ['process_cd' => $code])['process_id'];
}

function latest_vid(int $pid): int
{
    return (int)PS::versions($pid)[0]['version_id'];
}

function day(int $offset): string
{
    return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' day'));
}

test('helpers: user_options 는 부서별로 묶고 이스케이프하며 선택값을 유지한다', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $d = Repo::insert('dept', ['dept_nm' => 'A<b>팀', 'is_it' => 0, 'use_yn' => 1]);
    $u1 = App\Services\Users::create('zed', 'Zed', 'z@example.com', $d, 'ko', 'Passw0rd1', ['REQUESTER']);
    $html = user_options(Repo::list('user', ['use_yn' => 1], 'user_nm'), $u1);
    ok(str_contains($html, '<optgroup label="A&lt;b&gt;팀">'), '부서명 이스케이프');
    ok(str_contains($html, 'value="' . $u1 . '" selected>Zed (zed)</option>'), '선택값 유지');
    ok(!str_contains($html, '<b>'));
    $none = '<optgroup label="' . e(t('common.none')) . '">';
    ok(str_contains($html, $none), '부서 없는 사용자 그룹');
    ok(strpos($html, $none) > strpos($html, 'A&lt;b&gt;팀'), '부서 없음 그룹은 마지막');
});

/** 유효한 구조의 활성 프로세스를 만든다. */
function make_active(string $code, string $from = '2020-01-01'): array
{
    $pid = PS::createProcess($code, $code . ' name', '', []);
    $vid = latest_vid($pid);
    PS::activate($pid, $vid, $from, '');
    return [$pid, $vid];
}

/** 해당 버전을 사용하는 요청 1건을 만든다(사용 이력 시뮬레이션). */
function use_version(int $vid): void
{
    static $n = 0;
    $n++;
    $svc = Repo::insert('service', ['service_cd' => 'UV' . $n, 'service_nm' => 'uv', 'use_yn' => 1]);
    $uid = (int)Repo::find('user', ['login_id' => 'user1'])['user_id'];
    Repo::insert('request', [
        'request_no' => 'SR-TEST-' . $n, 'service_id' => $svc, 'maint_type' => 'MODIFY', 'title' => 't', 'content_html' => 'c',
        'version_id' => $vid, 'requester_id' => $uid, 'status' => 'IN_PROGRESS', 'req_due_date' => day(30),
        'created_at' => now_str(), 'updated_at' => now_str(),
    ]);
}

function step_in(array $o = []): array
{
    return $o + [
        'step_nm' => '검토', 'step_type' => 'REVIEW', 'actor_type' => 'ROLE', 'actor_value' => 'IT_TEAM', 'approval_mode' => 'ANY',
        'allow_reject' => '1', 'reject_to_step' => '', 'due_days' => '2', 'notify_tmpl' => '', 'attach_mode' => 'NONE',
        'attach_max_cnt' => '', 'attach_max_mb' => '', 'attach_exts' => '', 'name_ja' => '', 'name_en' => '',
    ];
}

test('process: 회사 생성 시 기본 프로세스(일반/긴급) 시드, 일정조율은 프로세스가 아님', function () {
    fresh();
    seed_two_companies();
    foreach (['A01', 'B01'] as $cd) {
        Scope::set($cd);
        eq(2, Repo::count('process_master'), "$cd masters");
        eq(null, Repo::find('process_master', ['process_cd' => 'SCHEDULE']));
        foreach (['GENERAL' => 6, 'URGENT' => 5] as $code => $n) {
            $v = PS::resolveVersion(pid_of($code));
            ok($v !== null, "$cd $code active");
            eq($n, count(PS::steps((int)$v['version_id'])), "$cd $code steps");
            eq([], PS::validateVersion((int)$v['version_id']), "$cd $code 구조 검증");
        }
    }
    Scope::set('A01');
    $g = PS::steps((int)PS::resolveVersion(pid_of('GENERAL'))['version_id']);
    eq(['SUBMIT', 'REVIEW', 'APPROVE', 'WORK', 'VERIFY', 'CLOSE'], array_column($g, 'step_type'));
    eq(['REQUESTER', 'ROLE', 'SERVICE_BIZ_OWNER', 'SERVICE_IT_OWNER', 'REQUESTER', 'ROLE'], array_column($g, 'actor_type'), '승인은 서비스 현업 IT 담당자');
    eq([null, 1, 2, null, 4, null], array_map(static fn($s) => $s['reject_to_step'] === null ? null : (int)$s['reject_to_step'], $g), '반려 이동 대상');
    eq('受付', Repo::find('translation', ['entity' => 'STEP', 'entity_key' => (string)$g[0]['step_id'], 'lang' => 'ja'])['text']);
});

test('process: 기본 프로세스 보강은 멱등이며 삭제한 것을 되살리지 않음', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    Seeder::ensureDefaults('A01');
    eq(2, Repo::count('process_master'));
    PS::deleteProcess(pid_of('URGENT'));
    Seeder::ensureDefaults('A01');
    eq(1, Repo::count('process_master'));
    // 플래그가 없는 기존 회사(M1 시점 DB)는 보강됨
    Db::exec("DELETE FROM app_setting WHERE company_cd = 'B01'");
    Db::exec("DELETE FROM process_step WHERE company_cd = 'B01'");
    Db::exec("DELETE FROM process_version WHERE company_cd = 'B01'");
    Db::exec("DELETE FROM process_master WHERE company_cd = 'B01'");
    Seeder::ensureDefaults('B01');
    eq(2, Repo::count('process_master'));
});

test('migration 002: 기존 일정조율(SCHEDULE) 시드만 제거하고 is_system 컨럼 삭제', function () {
    Db::connect(':memory:');
    Db::pdo()->exec((string)file_get_contents(APP_ROOT . '/database/migrations/001_init.sql'));
    Db::pdo()->exec('CREATE TABLE schema_migration (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
    Db::exec("INSERT INTO schema_migration VALUES ('001_init.sql', '2026-01-01')");
    Db::exec("INSERT INTO company (company_cd, company_nm, default_lang, use_yn, created_at) VALUES ('X1', 'x', 'ko', 1, 't'), ('X2', 'y', 'ko', 1, 't')");
    foreach (['X1', 'X2'] as $cd) {
        Db::exec("INSERT INTO process_master (company_cd, process_cd, process_nm, is_system) VALUES ('$cd', 'SCHEDULE', 's', 1), ('$cd', 'GENERAL', 'g', 0)");
        foreach (['SCHEDULE', 'GENERAL'] as $code) {
            $pid = (int)Db::val('SELECT process_id FROM process_master WHERE company_cd = :c AND process_cd = :p', ['c' => $cd, 'p' => $code]);
            $vid = Db::insert("INSERT INTO process_version (company_cd, process_id, version_no, valid_from, status, created_at) VALUES ('$cd', $pid, 1, '2026-01-01', 'ACTIVE', 't')");
            $sid = Db::insert("INSERT INTO process_step (company_cd, version_id, step_no, step_nm, step_type, actor_type) VALUES ('$cd', $vid, 1, 'n', 'SUBMIT', 'REQUESTER')");
            Db::exec("INSERT INTO process_step_cc (company_cd, step_id, cc_type) VALUES ('$cd', $sid, 'REQUESTER')");
            Db::exec("INSERT INTO translation (company_cd, entity, entity_key, lang, text) VALUES ('$cd', 'STEP', '$sid', 'ja', 'x'), ('$cd', 'PROCESS', '$code', 'ja', 'x')");
        }
    }
    eq(['002_remove_schedule_process.sql', '003_request_rules.sql', '004_schedule_agreement.sql', '005_mail_queue.sql', '006_user_theme.sql', '007_worklog.sql', '008_request_split.sql', '009_request_date_submit.sql', '010_request_canceled_at.sql', '011_effort_hours.sql', '012_service_biz_it.sql'], App\Core\Migrator::run());
    eq(0, (int)Db::val("SELECT COUNT(*) FROM process_master WHERE process_cd = 'SCHEDULE'"));
    eq(2, (int)Db::val("SELECT COUNT(*) FROM process_master WHERE process_cd = 'GENERAL'"));
    eq(2, (int)Db::val('SELECT COUNT(*) FROM process_version'));
    eq(2, (int)Db::val('SELECT COUNT(*) FROM process_step'));
    eq(2, (int)Db::val('SELECT COUNT(*) FROM process_step_cc'));
    eq(2, (int)Db::val("SELECT COUNT(*) FROM translation WHERE entity = 'STEP'"));
    eq(2, (int)Db::val("SELECT COUNT(*) FROM translation WHERE entity = 'PROCESS' AND entity_key = 'GENERAL'"));
    eq(0, (int)Db::val("SELECT COUNT(*) FROM translation WHERE entity = 'PROCESS' AND entity_key = 'SCHEDULE'"));
    $cols = array_column(Db::all('PRAGMA table_info(process_master)'), 'name');
    ok(!in_array('is_system', $cols, true));
    eq('company_cd', $cols[0]);
    eq([], Db::all('PRAGMA foreign_key_check'));
    eq([], App\Core\Migrator::run(), '재실행 멱등');
});

test('process: 생성 검증과 초기 초안 버전(접수/종료)', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    perr(fn() => PS::createProcess('bad code', 'x', '', []), 'process.err_code');
    perr(fn() => PS::createProcess('GENERAL', 'x', '', []), 'process.err_duplicate');
    perr(fn() => PS::createProcess('NEWP', '', '', []), 'process.err_name');
    $pid = PS::createProcess('NEWP', '새 프로세스', '설명', ['ja' => '新プロセス', 'en' => 'New Process']);
    $vs = PS::versions($pid);
    eq(1, count($vs));
    eq('DRAFT', $vs[0]['status']);
    eq(['SUBMIT', 'CLOSE'], array_column(PS::steps((int)$vs[0]['version_id']), 'step_type'));
    eq([], PS::validateVersion((int)$vs[0]['version_id']));
    // 같은 코드를 다른 회사에서 쓸 수 있음
    Scope::set('B01');
    ok(PS::createProcess('NEWP', 'B 프로세스', '', []) > 0);
});

test('process: 단계 추가/수정/삭제 및 검증', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $pid = PS::createProcess('STEPS', 's', '', []);
    $vid = latest_vid($pid);
    $sid = PS::addStep($pid, $vid, step_in(['step_nm' => '검토', 'name_ja' => 'レビュー', 'name_en' => 'Review']));
    $s = Repo::find('process_step', ['step_id' => $sid]);
    eq(3, (int)$s['step_no']);
    eq('IT_TEAM', $s['actor_value']);
    eq('レビュー', \App\Services\Translations::get('STEP', (string)$sid)['ja']);
    perr(fn() => PS::addStep($pid, $vid, step_in(['step_nm' => ''])), 'process.err_step_name');
    perr(fn() => PS::addStep($pid, $vid, step_in(['step_type' => 'NOPE'])), 'process.err_step_type');
    perr(fn() => PS::addStep($pid, $vid, step_in(['actor_type' => 'PROPOSER'])), 'process.err_step_type');
    perr(fn() => PS::addStep($pid, $vid, step_in(['actor_value' => 'NO_SUCH_ROLE'])), 'process.err_actor');
    perr(fn() => PS::addStep($pid, $vid, step_in(['actor_type' => 'USER', 'actor_value' => '99999'])), 'process.err_actor');
    perr(fn() => PS::addStep($pid, $vid, step_in(['reject_to_step' => '4'])), 'process.err_reject_to');
    perr(fn() => PS::addStep($pid, $vid, step_in(['due_days' => '0'])), 'process.err_due');
    perr(fn() => PS::addStep($pid, $vid, step_in(['notify_tmpl' => 'bad tmpl'])), 'process.err_notify');
    perr(fn() => PS::addStep($pid, $vid, step_in(['attach_max_cnt' => '99'])), 'process.err_attach');
    $uid = (int)Repo::find('user', ['login_id' => 'admin'])['user_id'];
    PS::updateStep($pid, $vid, $sid, step_in(['step_nm' => '검토2', 'actor_type' => 'USER', 'actor_value' => (string)$uid, 'due_days' => '5']));
    $s = Repo::find('process_step', ['step_id' => $sid]);
    eq('검토2', $s['step_nm']);
    eq((string)$uid, $s['actor_value']);
    eq(5, (int)$s['due_days']);
    PS::updateStep($pid, $vid, $sid, step_in(['actor_type' => 'REQUESTER', 'actor_value' => 'ignored']));
    eq(null, Repo::find('process_step', ['step_id' => $sid])['actor_value'], 'ROLE/USER 가 아니면 값 없음');
    // 다른 버전/프로세스의 단계는 접근 불가
    $pid2 = PS::createProcess('OTHER', 'o', '', []);
    perr(fn() => PS::updateStep($pid2, latest_vid($pid2), $sid, step_in()), 'process.err_not_found');
});

test('process: 첨부 설정 검증(확장자 정규화/차단 목록)', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $pid = PS::createProcess('ATT', 'a', '', []);
    $vid = latest_vid($pid);
    $sid = PS::addStep($pid, $vid, step_in(['attach_mode' => 'REQUIRED', 'attach_max_cnt' => '3', 'attach_max_mb' => '10', 'attach_exts' => 'PDF, .xlsx docx']));
    eq('pdf,xlsx,docx', Repo::find('process_step', ['step_id' => $sid])['attach_exts']);
    foreach (['php', 'exe', 'JS', 'svg', 'html', 'bad/ext'] as $bad) {
        perr(fn() => PS::addStep($pid, $vid, step_in(['attach_exts' => "pdf,$bad"])), 'process.err_ext');
    }
    $sid2 = PS::addStep($pid, $vid, step_in(['attach_exts' => '']));
    eq(null, Repo::find('process_step', ['step_id' => $sid2])['attach_exts']);
});

test('process: 단계 순서 변경 시 반려 이동 단계가 새 번호로 갱신', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $pid = PS::createProcess('ORD', 'o', '', []);
    $vid = latest_vid($pid);
    $a = PS::addStep($pid, $vid, step_in(['step_nm' => 'A']));                       // 3
    $b = PS::addStep($pid, $vid, step_in(['step_nm' => 'B', 'reject_to_step' => '3']));  // 4, 반려→A
    [$submit, $close] = array_map(fn($s) => (int)$s['step_id'], PS::steps($vid));
    PS::reorder($pid, $vid, [$submit, $a, $b, $close]);
    $steps = Repo::list('process_step', ['version_id' => $vid], 'step_no');
    eq(['접수', 'A', 'B', '종료'], array_column($steps, 'step_nm'));
    eq([1, 2, 3, 4], array_map('intval', array_column($steps, 'step_no')));
    eq(2, (int)Repo::find('process_step', ['step_id' => $b])['reject_to_step'], 'A 가 2번이 되었으므로 반려→2');
    // 삭제: A 를 지우면 B 의 반려 이동은 해제되고 번호가 당겨짐
    PS::deleteStep($pid, $vid, $a);
    $steps = Repo::list('process_step', ['version_id' => $vid], 'step_no');
    eq(['접수', 'B', '종료'], array_column($steps, 'step_nm'));
    eq(null, Repo::find('process_step', ['step_id' => $b])['reject_to_step']);
    eq(null, Repo::find('process_step', ['step_id' => $a]));
    // 잘못된 순서 집합은 거부
    perr(fn() => PS::reorder($pid, $vid, [$submit, $close]), 'process.err_order');
    perr(fn() => PS::reorder($pid, $vid, [$submit, $b, $close, 99999]), 'process.err_order');
});

test('process: 구조 검증(제출 첫 단계/종료 마지막/반려 대상)', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $pid = PS::createProcess('VAL', 'v', '', []);
    $vid = latest_vid($pid);
    $keys = fn() => array_column(PS::validateVersion($vid), 0);
    eq([], $keys());
    $x = PS::addStep($pid, $vid, step_in(['step_nm' => 'X']));       // 종료 뒤에 추가됨
    ok(in_array('process.v_last_close', $keys(), true) && in_array('process.v_close_only_last', $keys(), true));
    $steps = PS::steps($vid);
    PS::reorder($pid, $vid, [(int)$steps[0]['step_id'], $x, (int)$steps[1]['step_id']]);
    eq([], $keys());
    $y = PS::addStep($pid, $vid, step_in(['step_nm' => 'Y', 'step_type' => 'SUBMIT', 'actor_type' => 'REQUESTER', 'actor_value' => '']));
    ok(in_array('process.v_submit_only_first', $keys(), true));
    PS::deleteStep($pid, $vid, $y);
    // 제출 단계 처리자가 요청자가 아니면 위반
    $first = (int)PS::steps($vid)[0]['step_id'];
    PS::updateStep($pid, $vid, $first, step_in(['step_nm' => '접수', 'step_type' => 'SUBMIT', 'actor_type' => 'ROLE', 'actor_value' => 'IT_TEAM']));
    ok(in_array('process.v_submit_actor', $keys(), true));
    // 역할 삭제로 처리자가 사라지면 위반
    PS::updateStep($pid, $vid, $first, step_in(['step_nm' => '접수', 'step_type' => 'SUBMIT', 'actor_type' => 'REQUESTER', 'actor_value' => '']));
    Repo::insert('role', ['role_cd' => 'TMP', 'role_nm' => 'tmp']);
    PS::updateStep($pid, $vid, (int)$x, step_in(['step_nm' => 'X', 'actor_value' => 'TMP']));
    eq([], $keys());
    Repo::delete('role', ['role_cd' => 'TMP']);
    ok(in_array('process.v_actor', $keys(), true));
    // 활성화는 검증 실패 시 상세 오류를 던짐
    try {
        PS::activate($pid, $vid, day(0), '');
        throw new RuntimeException('should fail');
    } catch (ProcessError $e) {
        eq('process.err_invalid', $e->key);
        ok($e->params['errors'] !== []);
    }
});

test('process: 활성화 후 수정/단계변경 불가, 새 버전은 초안 1개만', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    [$pid, $v1] = make_active('LOCK');
    $sid = (int)PS::steps($v1)[0]['step_id'];
    perr(fn() => PS::updateStep($pid, $v1, $sid, step_in(['step_type' => 'SUBMIT', 'actor_type' => 'REQUESTER'])), 'process.err_not_draft');
    perr(fn() => PS::addStep($pid, $v1, step_in()), 'process.err_not_draft');
    perr(fn() => PS::deleteStep($pid, $v1, $sid), 'process.err_not_draft');
    perr(fn() => PS::reorder($pid, $v1, [1]), 'process.err_not_draft');
    perr(fn() => PS::addCc($pid, $v1, $sid, 'REQUESTER', '', 'NONE'), 'process.err_not_draft');
    perr(fn() => PS::setDates($pid, $v1, '2021-01-01', ''), 'process.err_not_draft');
    perr(fn() => PS::activate($pid, $v1, day(0), ''), 'process.err_not_draft');
    $v2 = PS::createVersion($pid, $v1);
    eq(2, (int)Repo::find('process_version', ['version_id' => $v2])['version_no']);
    perr(fn() => PS::createVersion($pid, $v1), 'process.err_draft_exists');
    PS::addStep($pid, $v2, step_in());
    eq(2, count(PS::steps($v1)), '원본 버전은 그대로');
    eq(3, count(PS::steps($v2)));
});

test('process: 버전 복제 시 단계/참조자/번역 복사', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $pid = PS::createProcess('CLN', 'c', '', []);
    $v1 = latest_vid($pid);
    $sid = PS::addStep($pid, $v1, step_in(['step_nm' => '검토', 'name_ja' => 'レビュー', 'reject_to_step' => '1']));
    $deptId = (int)Repo::find('dept', ['is_it' => 1])['dept_id'];
    PS::addCc($pid, $v1, $sid, 'DEPT', (string)$deptId, 'BOTH');
    PS::addCc($pid, $v1, $sid, 'REQUESTER', '', 'COMPLETE');
    $steps = PS::steps($v1);
    PS::reorder($pid, $v1, [(int)$steps[0]['step_id'], $sid, (int)$steps[1]['step_id']]);
    PS::activate($pid, $v1, '2020-01-01', '');
    $v2 = PS::createVersion($pid, $v1);
    $new = PS::steps($v2);
    eq(['접수', '검토', '종료'], array_column($new, 'step_nm'));
    $newSid = (int)$new[1]['step_id'];
    ok($newSid !== $sid);
    eq(1, (int)$new[1]['reject_to_step']);
    eq('レビュー', \App\Services\Translations::get('STEP', (string)$newSid)['ja']);
    $cc = PS::ccsByStep($v2)[$newSid];
    eq(['DEPT', 'REQUESTER'], array_column($cc, 'cc_type'));
    eq('BOTH', $cc[0]['notify_on']);
    // 복제본 수정이 원본에 영향 없음
    PS::deleteStep($pid, $v2, $newSid);
    eq(3, count(PS::steps($v1)));
    eq(2, count(PS::ccsByStep($v1)[$sid]));
    // 빈 버전
    PS::deleteVersion($pid, $v2);
    $v3 = PS::createVersion($pid, null);
    eq(0, count(PS::steps($v3)));
});

test('process: 참조자 등록/검증/중복/삭제', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $pid = PS::createProcess('CC', 'c', '', []);
    $vid = latest_vid($pid);
    $sid = (int)PS::steps($vid)[0]['step_id'];
    $uid = (int)Repo::find('user', ['login_id' => 'user1'])['user_id'];
    PS::addCc($pid, $vid, $sid, 'ROLE', 'IT_TEAM', 'ACTIVATE');
    PS::addCc($pid, $vid, $sid, 'USER', (string)$uid, 'NONE');
    PS::addCc($pid, $vid, $sid, 'SERVICE_IT_OWNER', 'ignored', 'NONE');
    perr(fn() => PS::addCc($pid, $vid, $sid, 'ROLE', 'IT_TEAM', 'BOTH'), 'process.err_cc_duplicate');
    perr(fn() => PS::addCc($pid, $vid, $sid, 'ROLE', 'NOPE', 'NONE'), 'process.err_actor');
    perr(fn() => PS::addCc($pid, $vid, $sid, 'USER', 'abc', 'NONE'), 'process.err_actor');
    perr(fn() => PS::addCc($pid, $vid, $sid, 'DEPT', '99999', 'NONE'), 'process.err_actor');
    perr(fn() => PS::addCc($pid, $vid, $sid, 'PROPOSER', '', 'NONE'), 'process.err_cc');
    perr(fn() => PS::addCc($pid, $vid, $sid, 'ROLE', 'IT_TEAM', 'ALWAYS'), 'process.err_cc');
    $list = PS::ccsByStep($vid)[$sid];
    eq(3, count($list));
    PS::deleteCc($pid, $vid, $sid, (int)$list[0]['cc_id']);
    eq(2, count(PS::ccsByStep($vid)[$sid]));
    perr(fn() => PS::deleteCc($pid, $vid, $sid, 99999), 'process.err_not_found');
    // 비활성 사용자는 참조자로 지정 불가
    Repo::update('user', ['user_id' => $uid], ['use_yn' => 0]);
    perr(fn() => PS::addCc($pid, $vid, $sid, 'USER', (string)$uid, 'NONE'), 'process.err_actor');
});

test('process: A1→A2 전환 - 예약 적용(종료일 없는 신규 버전이 기존 버전을 전날로 종료)', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    [$pid, $v1] = make_active('AB', '2020-01-01');
    $v2 = PS::createVersion($pid, $v1);
    perr(fn() => PS::activate($pid, $v2, day(1), day(5)), 'process.err_overlap');
    perr(fn() => PS::activate($pid, $v2, 'not-a-date', ''), 'process.err_date');
    perr(fn() => PS::activate($pid, $v2, '2026-13-40', ''), 'process.err_date');
    perr(fn() => PS::activate($pid, $v2, day(5), day(1)), 'process.err_date_range');
    // 사용 이력이 있는 기존 버전은 과거 시작일로 소급 대체할 수 없음
    use_version($v1);
    perr(fn() => PS::activate($pid, $v2, '2019-01-01', ''), 'process.err_overlap');
    perr(fn() => PS::activate($pid, $v2, '2020-01-01', ''), 'process.err_overlap');
    eq('DRAFT', Repo::find('process_version', ['version_id' => $v2])['status'], '실패 시 상태 불변');
    eq(null, Repo::find('process_version', ['version_id' => $v1])['valid_to'], '실패 시 기존 버전 불변');
    PS::activate($pid, $v2, day(1), '');
    $a = Repo::find('process_version', ['version_id' => $v1]);
    eq(day(0), $a['valid_to']);
    eq('ACTIVE', $a['status']);
    eq('IN_USE', PS::state($a));
    eq('SCHEDULED', PS::state(Repo::find('process_version', ['version_id' => $v2])));
    eq($v1, (int)PS::resolveVersion($pid, day(0))['version_id']);
    eq($v2, (int)PS::resolveVersion($pid, day(1))['version_id']);
    eq($v2, (int)PS::resolveVersion($pid, day(300))['version_id']);
    eq(null, PS::resolveVersion($pid, '2019-12-31'));
    // 아직 사용된 적 없는 예약 버전(v2)은 같은 시작일의 새 버전(v3)이 대체할 수 있음
    $v3 = PS::createVersion($pid, $v2);
    PS::activate($pid, $v3, day(1), '');
    eq('RETIRED', Repo::find('process_version', ['version_id' => $v2])['status']);
    eq($v3, (int)PS::resolveVersion($pid, day(1))['version_id']);
    eq($v1, (int)PS::resolveVersion($pid, day(0))['version_id'], 'v1 유지');
});

test('process: 당일 시작한 버전은 같은 날 신규 버전이 대체(진행 중 건은 기존 버전 고정)', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    [$pid, $v1] = make_active('SD', day(0));
    $v2 = PS::createVersion($pid, $v1);
    PS::activate($pid, $v2, day(0), '');
    eq('RETIRED', Repo::find('process_version', ['version_id' => $v1])['status']);
    eq($v2, (int)PS::resolveVersion($pid)['version_id']);
    $v3 = PS::createVersion($pid, $v2);
    use_version($v2);
    PS::activate($pid, $v3, day(0), '');
    eq('RETIRED', Repo::find('process_version', ['version_id' => $v2])['status']);
    eq($v3, (int)PS::resolveVersion($pid)['version_id']);
    eq(1, Repo::count('request', ['version_id' => $v2]), '기존 요청은 v2 에 고정');
});

test('process: A1→A2 전환 - 즉시 적용(A1 은 어제까지, 종료 상태)', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    [$pid, $v1] = make_active('IM', '2020-01-01');
    $v2 = PS::createVersion($pid, $v1);
    PS::activate($pid, $v2, day(0), '');
    $a = Repo::find('process_version', ['version_id' => $v1]);
    eq(day(-1), $a['valid_to']);
    eq('RETIRED', $a['status']);
    eq($v2, (int)PS::resolveVersion($pid)['version_id']);
    eq(null, PS::resolveVersion($pid, day(-1)), '종료(RETIRED) 버전은 접수 대상에서 제외');
});

test('process: 종료(retire)와 접수 중단, 삭제 규칙', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    [$pid, $v1] = make_active('RT', '2020-01-01');
    perr(fn() => PS::deleteVersion($pid, $v1), 'process.err_delete_active');
    PS::retire($pid, $v1);
    $r = Repo::find('process_version', ['version_id' => $v1]);
    eq('RETIRED', $r['status']);
    eq(day(0), $r['valid_to']);
    eq(null, PS::resolveVersion($pid, day(1)), '종료 후 신규 접수 불가');
    perr(fn() => PS::retire($pid, $v1), 'process.err_not_active');
    // 사용 이력이 있으면 종료 버전도 삭제 불가
    $svc = Repo::insert('service', ['service_cd' => 'SV', 'service_nm' => 'sv', 'use_yn' => 1]);
    $uid = (int)Repo::find('user', ['login_id' => 'user1'])['user_id'];
    Repo::insert('request', [
        'request_no' => 'SR-202611-0001', 'service_id' => $svc, 'maint_type' => 'MODIFY', 'title' => 't', 'content_html' => 'c',
        'version_id' => $v1, 'requester_id' => $uid, 'status' => 'IN_PROGRESS', 'req_due_date' => day(30),
        'created_at' => now_str(), 'updated_at' => now_str(),
    ]);
    perr(fn() => PS::deleteVersion($pid, $v1), 'process.err_used');
    perr(fn() => PS::deleteProcess($pid), 'process.err_used');
    // 초안은 항상 삭제 가능
    $d = PS::createVersion($pid, $v1);
    PS::deleteVersion($pid, $d);
    eq(null, Repo::find('process_version', ['version_id' => $d]));
    // 사용 이력 없는 프로세스는 삭제되며 단계/참조자/번역도 정리
    $pid2 = PS::createProcess('GONE', 'g', '', ['ja' => 'x']);
    $sid = (int)PS::steps(latest_vid($pid2))[0]['step_id'];
    PS::addCc($pid2, latest_vid($pid2), $sid, 'REQUESTER', '', 'NONE');
    PS::deleteProcess($pid2);
    eq(0, Repo::count('process_master', ['process_cd' => 'GONE']));
    eq(0, Repo::count('process_step_cc', ['step_id' => $sid]));
    eq(0, Repo::count('translation', ['entity' => 'PROCESS', 'entity_key' => 'GONE']));
});

test('process: 서비스×타입 매핑 저장/검증/삭제 차단', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $svc = Repo::insert('service', ['service_cd' => 'ERP', 'service_nm' => 'erp', 'use_yn' => 1]);
    $off = Repo::insert('service', ['service_cd' => 'OFF', 'service_nm' => 'off', 'use_yn' => 0]);
    $m = PS::mappingMatrix();
    eq(1, count($m['services']), '미사용 서비스 제외');
    eq(['GENERAL', 'URGENT'], array_column($m['processes'], 'process_cd'), '시스템 프로세스 제외');
    $general = pid_of('GENERAL');
    PS::saveMapping([$svc => ['MODIFY' => (string)$general, 'BUGFIX' => (string)pid_of('URGENT'), 'ETC' => '']]);
    eq(2, Repo::count('process_map'));
    eq($general, (int)Repo::find('process_map', ['service_id' => $svc, 'maint_type' => 'MODIFY'])['process_id']);
    perr(fn() => PS::saveMapping([$svc => ['MODIFY' => '99999']]), 'process.err_map');
    eq(2, Repo::count('process_map'), '실패 시 롤백');
    perr(fn() => PS::deleteProcess($general), 'process.err_mapped');
    PS::saveMapping([$svc => ['MODIFY' => '']]);
    eq(1, Repo::count('process_map'), '해제');
    // 존재하지 않는 서비스/타입 키는 무시
    PS::saveMapping([99999 => ['MODIFY' => (string)$general], $svc => ['NOPE' => (string)$general]]);
    eq(0, Repo::count('process_map', ['service_id' => 99999]));
    // 다른 회사 프로세스 ID 는 선택 불가
    $bGeneral = (function () { Scope::set('B01'); $i = pid_of('GENERAL'); Scope::set('A01'); return $i; })();
    perr(fn() => PS::saveMapping([$svc => ['MODIFY' => (string)$bGeneral]]), 'process.err_map');
});

test('process: 다른 회사 프로세스/버전/단계 접근 불가', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    [$pid, $vid] = make_active('ISO');
    $sid = (int)PS::steps($vid)[0]['step_id'];
    Scope::set('B01');
    perr(fn() => PS::updateProcess($pid, 'x', '', []), 'process.err_not_found');
    perr(fn() => PS::createVersion($pid, null), 'process.err_not_found');
    perr(fn() => PS::deleteProcess($pid), 'process.err_not_found');
    eq(null, PS::version($pid, $vid));
    eq(null, PS::resolveVersion($pid));
    eq([], PS::versions($pid));
});

test('process: 화면 접근 권한(관리자만) 및 전체 화면 200', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    Users::create('itman', 'ITman', 'it@example.com', null, 'ko', 'Itman1234', ['IT_TEAM']);
    $pid = pid_of('GENERAL');
    $vid = latest_vid($pid);
    $urls = ['/admin/processes', "/admin/processes/$pid", "/admin/processes/$pid/versions/$vid", '/admin/process-map'];
    as_admin();
    foreach ($urls as $u) {
        eq(200, http('GET', $u)->status, $u);
    }
    $body = http('GET', "/admin/processes/$pid/versions/$vid")->body;
    ok(str_contains($body, '일반 유지보수') && str_contains($body, 'lock'), '읽기 전용 안내');
    ok(!str_contains($body, 'Sortable.min.js'), '활성 버전은 드래그 UI 없음');
    foreach (['itman' => 'Itman1234', 'user1' => 'User1234'] as $id => $pw) {
        $_SESSION = [];
        login('A01', $id, $pw);
        foreach ($urls as $u) {
            eq(403, http('GET', $u)->status, "$id $u");
        }
        eq(403, http('POST', '/admin/processes', ['process_cd' => 'HACK', 'process_nm' => 'x'])->status);
        eq(403, http('POST', '/admin/process-map', [])->status);
        ok(!str_contains(http('GET', '/')->body, '/admin/processes'), "$id 메뉴 숨김");
    }
});

test('process: 화면 흐름 - 생성→단계 추가→순서변경→활성화→수정 차단', function () {
    fresh();
    seed_two_companies();
    as_admin();
    $r = http('POST', '/admin/processes', ['process_cd' => 'qa_flow', 'process_nm' => '품질 프로세스', 'name_ja' => '品質', 'name_en' => 'Quality', 'description' => 'd']);
    Scope::set('A01');
    $pid = pid_of('QA_FLOW');
    eq('/admin/processes/' . $pid, $r->headers['Location']);
    $vid = latest_vid($pid);
    $page = http('GET', "/admin/processes/$pid/versions/$vid");
    eq(200, $page->status);
    ok(str_contains($page->body, 'id="step-list"') && str_contains($page->body, 'data-order-url'), '초안은 정렬 UI');
    ok(str_contains($page->body, 'Sortable.min.js'));
    // 단계 추가 (종료 뒤에 붙으므로 경고 발생 → 활성화 버튼 비활성)
    http('POST', "/admin/processes/$pid/versions/$vid/steps", [
        'step_nm' => '검토', 'step_type' => 'REVIEW', 'actor_type' => 'ROLE', 'actor_role' => 'IT_TEAM', 'actor_user' => '',
        'approval_mode' => 'ANY', 'allow_reject' => '1', 'due_days' => '2', 'attach_mode' => 'OPTIONAL', 'attach_exts' => 'pdf',
    ]);
    Scope::set('A01');
    $steps = PS::steps($vid);
    eq(3, count($steps));
    $page = http('GET', "/admin/processes/$pid/versions/$vid");
    ok(str_contains($page->body, e(t('process.v_last_close'))), '구조 경고');
    // 드래그 정렬(JSON)
    $ids = [(int)$steps[0]['step_id'], (int)$steps[2]['step_id'], (int)$steps[1]['step_id']];
    $r = http('POST', "/admin/processes/$pid/versions/$vid/steps/order", ['ids' => implode(',', $ids)]);
    eq(200, $r->status);
    eq(['ok' => true], json_decode($r->body, true));
    Scope::set('A01');
    eq(['접수', '검토', '종료'], array_column(PS::steps($vid), 'step_nm'));
    $r = http('POST', "/admin/processes/$pid/versions/$vid/steps/order", ['ids' => '1,2']);
    eq(422, $r->status);
    // 참조자 추가
    $sid = (int)PS::steps($vid)[1]['step_id'];
    http('POST', "/admin/processes/$pid/versions/$vid/steps/$sid/cc", ['cc_type' => 'ROLE', 'cc_role' => 'BIZ_IT', 'cc_user' => '', 'cc_dept' => '', 'notify_on' => 'ACTIVATE']);
    Scope::set('A01');
    eq('BIZ_IT', PS::ccsByStep($vid)[$sid][0]['cc_value']);
    // 잘못된 날짜로 활성화 → 실패, 올바른 날짜 → 성공
    http('POST', "/admin/processes/$pid/versions/$vid/activate", ['valid_from' => 'bad', 'valid_to' => '']);
    eq(t('process.err_date'), last_flash());
    http('POST', "/admin/processes/$pid/versions/$vid/activate", ['valid_from' => day(0), 'valid_to' => '']);
    Scope::set('A01');
    eq('ACTIVE', Repo::find('process_version', ['version_id' => $vid])['status']);
    // 활성 후 수정/삭제 시도는 모두 차단
    http('POST', "/admin/processes/$pid/versions/$vid/steps/$sid", [
        'step_nm' => 'hack', 'step_type' => 'REVIEW', 'actor_type' => 'ROLE', 'actor_role' => 'IT_TEAM', 'approval_mode' => 'ANY',
    ]);
    eq(t('process.err_not_draft'), last_flash());
    Scope::set('A01');
    eq('검토', Repo::find('process_step', ['step_id' => $sid])['step_nm']);
    http('POST', "/admin/processes/$pid/versions/$vid/steps/$sid/delete");
    Scope::set('A01');
    ok(Repo::find('process_step', ['step_id' => $sid]) !== null);
    // 읽기 전용 화면에서도 참조자/첨부 요약 표시
    $page = http('GET', "/admin/processes/$pid/versions/$vid");
    ok(str_contains($page->body, 'BIZ_IT') || str_contains($page->body, '현업 전산담당'));
    ok(!str_contains($page->body, 'name="step_nm"'), '읽기 전용에는 편집 폼 없음');
    // 새 버전(복제) → 초안 편집 가능
    $r = http('POST', "/admin/processes/$pid/versions", ['from_version' => (string)$vid]);
    Scope::set('A01');
    $v2 = latest_vid($pid);
    eq("/admin/processes/$pid/versions/$v2", $r->headers['Location']);
    eq(200, http('GET', "/admin/processes/$pid/versions/$v2")->status);
});

test('process: 화면 - 다른 회사 프로세스 URL 은 404, 수정/삭제 불가', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $pid = pid_of('GENERAL');
    $vid = latest_vid($pid);
    as_admin('B01', 'Admin5678');
    foreach (["/admin/processes/$pid", "/admin/processes/$pid/versions/$vid"] as $u) {
        eq(404, http('GET', $u)->status, $u);
    }
    foreach (["/admin/processes/$pid", "/admin/processes/$pid/delete", "/admin/processes/$pid/versions",
        "/admin/processes/$pid/versions/$vid/retire", "/admin/processes/$pid/versions/$vid/activate"] as $u) {
        eq(404, http('POST', $u, ['process_nm' => 'x'])->status, $u);
    }
    Scope::set('A01');
    eq('일반 유지보수', Repo::find('process_master', ['process_id' => $pid])['process_nm']);
    eq('ACTIVE', Repo::find('process_version', ['version_id' => $vid])['status']);
});

test('process: 화면 - 매핑 저장/검증', function () {
    fresh();
    seed_two_companies();
    as_admin();
    Scope::set('A01');
    $svc = Repo::insert('service', ['service_cd' => 'ERP', 'service_nm' => '전사 ERP', 'use_yn' => 1]);
    $page = http('GET', '/admin/process-map');
    ok(str_contains($page->body, 'ERP') && str_contains($page->body, '일반 유지보수') && str_contains($page->body, '긴급 장애'));
    http('POST', '/admin/process-map', ['map' => [(string)$svc => ['MODIFY' => (string)pid_of('GENERAL')]]]);
    Scope::set('A01');
    eq(1, Repo::count('process_map'));
    http('POST', '/admin/process-map', ['map' => [(string)$svc => ['MODIFY' => '99999']]]);
    eq(t('process.err_map'), last_flash());
    Scope::set('A01');
    eq(pid_of('GENERAL'), (int)Repo::find('process_map', ['service_id' => $svc, 'maint_type' => 'MODIFY'])['process_id']);
});

test('process: 다국어 - 프로세스/단계명 번역 표시', function () {
    fresh();
    seed_two_companies();
    as_admin();
    http('POST', '/lang', ['lang' => 'ja', 'back' => '/']);
    Scope::set('A01');
    $pid = pid_of('GENERAL');
    $body = http('GET', '/admin/processes')->body;
    ok(str_contains($body, '一般保守'), 'ja 프로세스명');
    $body = http('GET', "/admin/processes/$pid/versions/" . latest_vid($pid))->body;
    ok(str_contains($body, '受付') && str_contains($body, '検収'), 'ja 단계명');
    http('POST', '/lang', ['lang' => 'en', 'back' => '/']);
    Scope::set('A01');
    ok(str_contains(http('GET', "/admin/processes/$pid")->body, 'General Maintenance'));
});
