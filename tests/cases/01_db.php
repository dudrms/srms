<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Repo;
use App\Core\Scope;
use App\Services\CompanySetup;
use App\Services\Seeder;

test('schema: company_cd 가 모든 업무 테이블의 첫 컬럼', function () {
    fresh();
    $exempt = ['company', 'permission', 'schema_migration'];
    $tables = array_column(Db::all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"), 'name');
    ok(count($tables) > 25, 'tables created');
    foreach ($tables as $t) {
        if (in_array($t, $exempt, true)) {
            continue;
        }
        $first = Db::all('PRAGMA table_info("' . $t . '")')[0]['name'] ?? '';
        eq('company_cd', $first, "table $t first column");
    }
});

test('schema: 업무 인덱스는 company_cd 선두', function () {
    fresh();
    foreach (Db::all("SELECT name, tbl_name FROM sqlite_master WHERE type='index' AND name LIKE 'ix_%'") as $ix) {
        $cols = Db::all('PRAGMA index_info("' . $ix['name'] . '")');
        eq('company_cd', $cols[0]['name'], 'index ' . $ix['name']);
    }
});

test('migrator: 재실행 시 추가 적용 없음', function () {
    fresh();
    eq([], App\Core\Migrator::run());
});

test('repo: 회사 스코프 미설정 시 예외', function () {
    fresh();
    throws(fn() => Repo::list('dept'), LogicException::class);
});

test('repo: 다른 회사 데이터는 조회/수정/삭제 불가', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $id = Repo::insert('dept', ['dept_nm' => 'OnlyA', 'is_it' => 0, 'use_yn' => 1]);
    ok(Repo::find('dept', ['dept_id' => $id]) !== null);
    Scope::set('B01');
    eq(null, Repo::find('dept', ['dept_id' => $id]), 'B 에서 A 부서 조회');
    eq(0, Repo::update('dept', ['dept_id' => $id], ['dept_nm' => 'hack']), 'B 에서 A 부서 수정');
    eq(0, Repo::delete('dept', ['dept_id' => $id]), 'B 에서 A 부서 삭제');
    Scope::set('A01');
    eq('OnlyA', Repo::find('dept', ['dept_id' => $id])['dept_nm']);
});

test('repo: company_cd 직접 전달/조건 없는 쿼리/식별자 인젝션 차단', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    throws(fn() => Repo::find('dept', ['company_cd' => 'B01']), InvalidArgumentException::class);
    throws(fn() => Repo::insert('dept', ['company_cd' => 'B01', 'dept_nm' => 'x']), InvalidArgumentException::class);
    throws(fn() => Repo::query('SELECT * FROM dept'), InvalidArgumentException::class);
    throws(fn() => Repo::list('dept; DROP TABLE dept'), InvalidArgumentException::class);
    throws(fn() => Repo::list('dept', [], 'dept_nm; DROP TABLE dept'), InvalidArgumentException::class);
    throws(fn() => Repo::delete('dept', []), InvalidArgumentException::class);
});

test('db: 복합 FK 로 타 회사 부모 참조 불가', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $deptA = (int)Repo::find('dept', ['is_it' => 1])['dept_id'];
    Scope::set('B01');
    throws(fn() => Repo::insert('user', [
        'login_id' => 'cross', 'user_nm' => 'X', 'email' => 'x@example.com', 'dept_id' => $deptA,
        'pw_hash' => 'x', 'use_yn' => 1, 'lang' => 'ko', 'created_at' => now_str(),
    ]), PDOException::class);
});

test('seed: 회사별 롤/권한/코드/번역', function () {
    fresh();
    seed_two_companies();
    foreach (['A01', 'B01'] as $cd) {
        Scope::set($cd);
        eq(4, Repo::count('role'), "$cd roles");
        eq(count(Seeder::PERMISSIONS), Repo::count('role_permission', ['role_cd' => 'SYS_ADMIN']), "$cd admin perms");
        eq(0, Repo::count('role_permission', ['role_cd' => 'REQUESTER', 'perm_cd' => 'USER_EDIT']));
        eq(0, Repo::count('role_permission', ['role_cd' => 'REQUESTER', 'perm_cd' => 'PROCESS_EDIT']));
        eq(5, Repo::count('code', ['group_cd' => 'MAINT_TYPE']));
        eq(7, Repo::count('code', ['group_cd' => 'SCHEDULE_REASON']));
        eq('新規開発', Repo::find('translation', ['entity' => 'CODE', 'entity_key' => 'MAINT_TYPE:NEW_DEV', 'lang' => 'ja'])['text']);
    }
});

test('seed: 회사 중복/잘못된 코드/관리자 오류 시 롤백', function () {
    fresh();
    CompanySetup::provision('A01', 'Alpha', 'ko', 'admin', 'AdminA', 'a@example.com', 'Admin1234');
    throws(fn() => CompanySetup::provision('A01', 'Dup', 'ko', 'admin', 'x', 'x@example.com', 'Admin1234'), InvalidArgumentException::class);
    throws(fn() => CompanySetup::provision('bad code!', 'X', 'ko', 'admin', 'x', 'x@example.com', 'Admin1234'), InvalidArgumentException::class);
    throws(fn() => CompanySetup::provision('C01', 'Gamma', 'ko', 'admin', 'x', 'not-an-email', 'Admin1234'), InvalidArgumentException::class);
    eq(null, Db::one("SELECT 1 FROM company WHERE company_cd = 'C01'"), '실패한 회사 생성은 롤백');
    eq(1, (int)Db::val('SELECT COUNT(*) FROM company'));
});

test('db: foreign_key_check 위반 없음', function () {
    fresh();
    seed_two_companies();
    eq([], Db::all('PRAGMA foreign_key_check'));
});

test('env: .env 파싱(주석/따옴표)', function () {
    $f = sys_get_temp_dir() . '/srms_env_' . bin2hex(random_bytes(4));
    file_put_contents($f, "# c\nA_KEY=plain\nB_KEY=\"quoted value\"\nC_KEY='single'\n\nBADLINE\nD_KEY = spaced \n");
    App\Core\Env::load($f);
    unlink($f);
    eq('plain', env('A_KEY'));
    eq('quoted value', env('B_KEY'));
    eq('single', env('C_KEY'));
    eq('spaced', env('D_KEY'));
    eq('dflt', env('NOPE_KEY', 'dflt'));
});
