<?php

declare(strict_types=1);

namespace Utopia\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Platform\Action;

final class ActionTest extends TestCase
{
    public function testOnWorkerStopDefaultsToNoOp(): void
    {
        $this->expectNotToPerformAssertions();

        $action = new class extends Action {};

        $action->onWorkerStop();
    }

    public function testOnWorkerStopOverrideIsInvoked(): void
    {
        $action = new WorkerStopAction();

        $this->assertFalse($action->stopped, 'Override must not run before the worker stops');

        $action->onWorkerStop();

        $this->assertTrue($action->stopped, 'Overridden onWorkerStop() must be invoked');
    }
}
