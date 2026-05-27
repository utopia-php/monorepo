<?php

declare(strict_types=1);

namespace Nats\Protocol;

enum ServerOp: string
{
    case Info = 'INFO';
    case Msg = 'MSG';
    case HMsg = 'HMSG';
    case Ping = 'PING';
    case Pong = 'PONG';
    case Ok = '+OK';
    case Err = '-ERR';
}
