<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Redis;

/**
 * Перехватывает стрим-задания для гейтвея (Redis setex) — проверить, что именно уйдёт в модель.
 */
trait CapturesStreamJobs
{
    /**
     * @return \Closure(): list<array{0: string, 1: array}> пойманные [ключ, задание]
     */
    protected function captureStreamJobs(): \Closure
    {
        $captured = [];

        Redis::shouldReceive('connection')->with('gateway')->andReturnSelf();
        Redis::shouldReceive('setex')->withArgs(function ($key, $ttl, $payload) use (&$captured) {
            $captured[] = [$key, json_decode($payload, true)];

            return true;
        });

        return function () use (&$captured) {
            return $captured;
        };
    }
}
