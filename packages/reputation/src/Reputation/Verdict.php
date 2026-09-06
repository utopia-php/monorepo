<?php

declare(strict_types=1);

namespace Utopia\Reputation;

enum Verdict: string
{
    case Clean = 'clean';
    case Low = 'low';
    case Suspicious = 'suspicious';
    case Block = 'block';
}
