<?php

namespace App\Enums;

/**
 * Коды тарифов, на которые опирается код (дефолтный тариф, тариф суперюзера).
 * Сами тарифы и их лимиты живут в таблице plans — там могут появиться и другие коды.
 */
enum PlanCode: string
{
    case Guest = 'guest';
    case Promo = 'promo';
    case Pro = 'pro';
    case Unlimited = 'unlimited';
}
