<?php
declare(strict_types=1);

use App\Core\I18n;
use App\Core\Repo;
use App\Services\Seeder;

test('i18n: 중국어 기본 단계명에 한글이 섞이지 않고 과거 오타는 보정된다', function () {
    fresh();
    App\Services\CompanySetup::provision('A01', 'Alpha', 'ko', 'admin', 'AdminA', 'a@example.com', 'Admin1234');
    App\Core\Scope::set('A01');
    $sid = (string)Repo::find('process_step', ['step_nm' => '검수'])['step_id'];
    Repo::update('translation', ['entity' => 'STEP', 'entity_key' => $sid, 'lang' => 'zh'], ['text' => '验수']);
    Seeder::ensureDefaults('A01');
    eq('验收', App\Services\Translations::get('STEP', $sid)['zh']);
    foreach (Repo::list('translation', ['lang' => 'zh']) as $t) {
        ok(preg_match('/\p{Hangul}/u', (string)$t['text']) !== 1, 'zh 번역에 한글 혼입: ' . $t['text']);
    }
});

test('views: HTML pattern 속성 문자 클래스에 이스케이프 없는 하이픈이 없다(브라우저 v 플래그에서 무효)', function () {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/app/Views', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        preg_match_all('/pattern="([^"]*)"/', (string)file_get_contents($f->getPathname()), $m);
        foreach ($m[1] as $p) {
            ok(preg_match('/(?<!\\\\)-\]/', $p) !== 1, $f->getFilename() . ': ' . $p);
        }
    }
});

test('i18n: ko/ja/en/zh 키 집합 동일, 치환자 동일', function () {
    $ko = I18n::load('ko');
    foreach (['ja', 'en', 'zh'] as $l) {
        $o = I18n::load($l);
        eq([], array_values(array_diff(array_keys($ko), array_keys($o))), "$l 누락 키");
        eq([], array_values(array_diff(array_keys($o), array_keys($ko))), "$l 초과 키");
        foreach ($ko as $k => $v) {
            preg_match_all('/\{[a-z_]+\}/', $v, $a);
            preg_match_all('/\{[a-z_]+\}/', (string)($o[$k] ?? ''), $b);
            eq($a[0], $b[0], "$l placeholder $k");
            ok(trim((string)$o[$k]) !== '', "$l empty $k");
        }
    }
});

test('i18n: 코드/뷰에서 사용하는 모든 번역 키가 ko 에 존재', function () {
    $ko = I18n::load('ko');
    $prefixes = 'app|nav|common|err|auth|pw|home|profile|user|dept|role|perm|code|service|process|pstate|ptype|actor|amode|attach|ccn|cctype|map|request|scope|rstatus|sstate|tl|sched|schst|mailtpl|maillog|mlst|dash|rpt';
    $missing = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php' || str_contains($f->getPathname(), DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        preg_match_all('/[\'"]((?:' . $prefixes . ')\.[A-Za-z0-9_]+)[\'"]/', (string)file_get_contents($f->getPathname()), $m);
        foreach ($m[1] as $key) {
            if (!array_key_exists($key, $ko)) {
                $missing[$key] = basename($f->getPathname());
            }
        }
    }
    eq([], $missing, '정의되지 않은 번역 키');
});

test('i18n: 동적 키(권한명/오류 코드) 모두 정의', function () {
    $ko = I18n::load('ko');
    foreach (array_keys(Seeder::PERMISSIONS) as $p) {
        ok(isset($ko['perm.' . $p]), "perm.$p");
    }
    foreach ([403, 404, 405, 500] as $s) {
        ok(isset($ko['err.' . $s]), "err.$s");
    }
    $dyn = [
        'pstate' => ['DRAFT', 'SCHEDULED', 'IN_USE', 'EXPIRED', 'RETIRED'],
        'ptype' => App\Services\ProcessService::STEP_TYPES,
        'actor' => App\Services\ProcessService::ACTOR_TYPES,
        'amode' => App\Services\ProcessService::APPROVAL_MODES,
        'attach' => App\Services\ProcessService::ATTACH_MODES,
        'ccn' => App\Services\ProcessService::NOTIFY_ON,
        'cctype' => App\Services\ProcessService::CC_TYPES,
        'rstatus' => App\Services\RequestService::STATUSES,
        'sstate' => ['DONE', 'ACTIVE', 'WAITING'],
        'scope' => ['mine', 'inbox', 'dept', 'all', 'cc'],
        'tl' => ['CREATED', 'SUBMIT', 'APPROVE', 'AGREE', 'COMPLETE', 'REJECT', 'CANCEL', 'COMMENT', 'ATTACH', 'SCHEDULE_PROPOSE', 'SCHEDULE_AGREE', 'SCHEDULE_REJECT', 'SCHEDULE_COUNTER', 'SCHEDULE_WITHDRAW'],
        'schst' => App\Services\ScheduleService::STATUSES,
        'sched' => ['agree', 'reject', 'counter'],
        'mlst' => ['QUEUED', 'SENDING', 'SENT', 'FAILED'],
        'dash.action' => ['step', 'return', 'schedule'],
        'rpt.sec' => App\Services\ReportService::SECTIONS,
        'rpt.grain' => App\Services\ReportService::GRAINS,
        'maillog.driver' => ['smtp', 'file', 'off'],
        'act.pass' => App\Services\ProcessService::STEP_TYPES,
    ];
    foreach ($dyn as $prefix => $codes) {
        foreach ($codes as $c) {
            ok(isset($ko[$prefix . '.' . $c]), "$prefix.$c");
        }
    }
});

test('i18n: 언어 협상/활성 언어/치환/폴백', function () {
    reset_state();
    eq('ja', I18n::negotiate('ja-JP,ja;q=0.9,en;q=0.8'));
    eq('en', I18n::negotiate('fr-FR,en-US;q=0.8'));
    eq('ko', I18n::negotiate('fr-FR'));
    \App\Core\Env::set('ENABLED_LANGS', 'ko,en');
    eq('en', I18n::negotiate('ja,en;q=0.5'));
    I18n::set('ja');
    eq('ko', I18n::lang(), '비활성 언어는 기본 언어로');
    \App\Core\Env::set('ENABLED_LANGS', 'ko,ja,en,zh');
    I18n::set('en');
    eq('Welcome, Kim.', t('home.welcome', ['name' => 'Kim']));
    eq('no.such.key', t('no.such.key'));
    I18n::set('ko');
});

test('i18n: 마스터 명칭 번역 및 ko 폴백', function () {
    fresh();
    seed_two_companies();
    App\Core\Scope::set('A01');
    I18n::set('ja');
    eq('システム管理者', master_name('ROLE', 'SYS_ADMIN', '시스템관리자'));
    eq('기본', master_name('ROLE', 'UNKNOWN', '기본'), '번역 없으면 기본 명칭');
    I18n::set('en');
    eq('New Development', master_name('CODE', 'MAINT_TYPE:NEW_DEV', '신규개발'));
    I18n::set('zh');
    eq('系统管理员', master_name('ROLE', 'SYS_ADMIN', '시스템관리자'));
    eq('新开发', master_name('CODE', 'MAINT_TYPE:NEW_DEV', '신규개발'));
    eq('常规运维', master_name('PROCESS', 'GENERAL', '일반 유지보수'));
    eq('审批', master_name('STEP', (string)Repo::find('process_step', ['step_nm' => '승인'])['step_id'], '승인'));
    eq('zh', I18n::negotiate('zh-CN,zh;q=0.9'));
    eq('2026-11-06', fmt_date('2026-11-06'));
    I18n::set('ko');
    eq('신규개발', master_name('CODE', 'MAINT_TYPE:NEW_DEV', '신규개발'));
});

test('i18n: 날짜 형식', function () {
    I18n::set('ko');
    eq('2026-11-06', fmt_date('2026-11-06'));
    I18n::set('ja');
    eq('2026/11/06', fmt_date('2026-11-06'));
    I18n::set('en');
    eq('06 Nov 2026 09:30', fmt_date('2026-11-06 09:30:00'));
    eq('', fmt_date(null));
    I18n::set('ko');
});
