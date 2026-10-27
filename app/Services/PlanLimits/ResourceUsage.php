<?php

namespace App\Services\PlanLimits;

use App\Enums\PlanResource;
use App\Enums\UsageLevel;

final readonly class ResourceUsage
{
    public float $percentage;

    public UsageLevel $level;

    public function __construct(
        public PlanResource $resource,
        public float $used,
        public ?int $limit,
    ) {
        $this->percentage = match (true) {
            $limit === null => 0.0,
            $limit <= 0 => 100.0,
            default => round($used / $limit * 100, 2),
        };

        $this->level = UsageLevel::fromPercentage($this->percentage);
    }

    public function isUnlimited(): bool
    {
        return $this->limit === null;
    }

    public function remaining(): ?float
    {
        return $this->limit === null ? null : max(0.0, $this->limit - $this->used);
    }

    /**
     * @return array{resource: string, used: float, limit: int|null, percentage: float, level: string}
     */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource->value,
            'used' => $this->used,
            'limit' => $this->limit,
            'percentage' => $this->percentage,
            'level' => $this->level->value,
        ];
    }
}
