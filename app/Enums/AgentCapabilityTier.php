<?php

namespace App\Enums;

enum AgentCapabilityTier: string
{
    case Junior = 'junior';
    case Intermediate = 'intermediate';
    case Senior = 'senior';
    case Principal = 'principal';
}
