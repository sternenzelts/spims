<?php

namespace App\Enums;

enum IdentificationMethod: string
{
    case BadgeScan = 'badge_scan';
    case SupervisorManual = 'supervisor_manual';
    case PaperForm = 'paper_form';
}
