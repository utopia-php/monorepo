<?php

declare(strict_types=1);

use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;

// Keep generic property hints that PHPStan cannot carry through promoted properties.
return (require __DIR__ . '/../../rector.php')->withSkip([
    ClassPropertyAssignToConstructorPromotionRector::class,
    RemoveNonExistingVarAnnotationRector::class,
]);
