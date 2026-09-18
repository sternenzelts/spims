<?php

namespace App\Enums;

enum Criticality: string
{
    case Critical = 'critical';
    case Important = 'important';
    case Normal = 'normal';
}
