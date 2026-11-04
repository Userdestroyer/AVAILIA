<?php

use Illuminate\Support\Facades\Schedule;

// ЦБ публикует курс на завтра около 15:30 МСК; open.er-api обновляется раз в сутки
Schedule::command('exchange-rates:update')->dailyAt('16:00')->timezone('Europe/Moscow');
