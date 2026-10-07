<?php

declare(strict_types=1);

namespace IdempotencyLinter\Analysis\Matching;

enum CallKind
{
    case StaticCall;
    case Function;
    case Method;
    case ArrayLiteral;
}
