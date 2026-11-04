<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Тестам не нужен собранный фронт
        $this->withoutVite();

        // Защита от timing-атак держит каждую неудачную попытку входа 200 мс — в тестах не нужна
        config(['auth.timebox_duration' => 0]);
    }
}
