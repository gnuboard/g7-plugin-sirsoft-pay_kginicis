<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Carbon\Carbon;

/**
 * Signature 요청의 timestamp 신선도(freshness) 검증.
 *
 * KG 이니시스 결제 signature 는 timestamp 를 hash 입력에 포함하지만 PG 측에서
 * timestamp 의 시간상 유효성을 검증하지 않는다. 따라서 공격자가 과거에 캡처한
 * 정상 signature 를 stale timestamp 와 함께 재사용할 위험이 있다 (replay).
 *
 * 본 트레이트는 ±300초(5분) 윈도우로 timestamp 의 freshness 를 검증하여
 * stale signature 의 재사용을 차단한다.
 *
 * 지원 timestamp 포맷:
 *  - yyyyMMddHHmmss (14자리 숫자) — CBT 표준
 *  - epoch milliseconds (13자리 숫자) — PC/모바일 표준 (Math.floor(Date.now()))
 */
trait ValidatesTimestampFreshness
{
    /** 허용 시간 윈도우 (초) — 클라이언트/서버 시간차 흡수 + 정상 사용자 지연 흡수 */
    private const FRESHNESS_WINDOW_SECONDS = 300;

    /**
     * 14자리 yyyyMMddHHmmss 파싱 시 사용할 timezone.
     *
     * 클라이언트(JS Date) 는 getHours() 등으로 로컬 시각을 14자리로 생성하며,
     * gnuboard5 PHP 의 date('YmdHis') 도 서버 로컬(보통 Asia/Seoul) 시각이다.
     * Laravel 의 app.timezone=UTC 환경에서 Carbon::createFromFormat 기본 TZ 가
     * UTC 로 설정되어 KST 클라이언트 timestamp 를 9시간 어긋난 UTC 로 잘못 파싱
     * 하던 회귀를 차단하기 위해 명시적으로 Asia/Seoul 로 파싱.
     */
    private const PARSE_TIMEZONE = 'Asia/Seoul';

    /**
     * timestamp 가 현재 시각 ±300초 윈도우 내에 있는지 검증.
     *
     * 14자리 yyyyMMddHHmmss 또는 13자리 epoch ms 모두 허용. 그 외 포맷은 거부.
     *
     * @param  string  $timestamp  검증할 timestamp (PG signature 요청 페이로드의 값)
     * @return bool 신선하면 true, stale 또는 파싱 실패면 false
     */
    protected function isTimestampFresh(string $timestamp): bool
    {
        $parsed = $this->parseTimestamp($timestamp);
        if ($parsed === null) {
            return false;
        }

        $diffSeconds = abs(time() - $parsed);

        return $diffSeconds <= self::FRESHNESS_WINDOW_SECONDS;
    }

    /**
     * timestamp 문자열을 Unix epoch (초) 로 파싱.
     *
     * @param  string  $timestamp  14자리 yyyyMMddHHmmss 또는 13자리 epoch ms
     * @return int|null Unix epoch (초), 파싱 실패 시 null
     */
    private function parseTimestamp(string $timestamp): ?int
    {
        if (! preg_match('/^\d{13,14}$/', $timestamp)) {
            return null;
        }

        // 13자리: epoch ms → 초로 변환
        if (strlen($timestamp) === 13) {
            return (int) floor((int) $timestamp / 1000);
        }

        // 14자리: yyyyMMddHHmmss → Asia/Seoul 기준으로 파싱
        try {
            return Carbon::createFromFormat('YmdHis', $timestamp, self::PARSE_TIMEZONE)->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
