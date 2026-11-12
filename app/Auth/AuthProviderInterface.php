<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * 외부(사내 SSO) 인증 어댑터. SSO 문서/라이브러리를 받으면 이 인터페이스를 구현한 클래스를 만들어
 * `Providers::setSso()` 호출(또는 `Providers::sso()` 의 기본 구현 교체)만 하면 된다.
 * 인증 성공 후 세션 생성·권한 판정·감사 기록은 `Auth::attemptSso()` 가 공통으로 처리한다.
 */
interface AuthProviderInterface
{
    public function name(): string;

    /**
     * IdP 로그인 화면으로 보낼 URL. `$state` 는 콜백에서 그대로 돌려받아야 하는 CSRF 방지 값이다.
     * 아직 연동되지 않았으면 null.
     */
    public function loginUrl(string $state): ?string;

    /**
     * IdP 콜백 요청을 검증(서명/토큰/state)하고 사용자를 식별한다.
     * @param array<string,mixed> $query 콜백 요청의 GET 파라미터
     * @return array{company_cd:string,login_id:string}|null 검증 실패면 null. login_id 는 사번과 매핑되는 `user.login_id`
     */
    public function identify(array $query, string $expectedState): ?array;
}
