<?php
declare(strict_types=1);

namespace LearningCloud;

use RuntimeException;

final class Problem extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        public readonly array $details = [],
    ) {
        parent::__construct($reason);
    }
}
