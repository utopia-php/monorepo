<?php

declare(strict_types=1);

namespace Utopia\Unit;

use Utopia\Platform\Action;

class WorkerStopAction extends Action
{
    public bool $stopped = false;

    public function onWorkerStop(): void
    {
        $this->stopped = true;
    }
}
