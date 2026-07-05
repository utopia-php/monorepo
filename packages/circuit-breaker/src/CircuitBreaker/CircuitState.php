<?php

namespace Utopia\CircuitBreaker;

enum CircuitState: string
{
    case CLOSED = 'closed';
    case OPEN = 'open';
    case HALF_OPEN = 'half_open';
}
