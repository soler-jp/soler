<?php

namespace App\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class TransactionDiffResult implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $subjectChanges
     * @param  array<string, array{0: mixed, 1: mixed}>  $derivedChanges
     * @param  array<string, array{created: list<array<string, mixed>>, updated: list<array<string, mixed>>, deleted: list<array<string, mixed>>}>  $relatedChanges
     */
    public function __construct(
        public array $subjectChanges,
        public array $derivedChanges,
        public array $relatedChanges,
    ) {}

    public function hasChanges(): bool
    {
        if ($this->subjectChanges !== []) {
            return true;
        }

        foreach ($this->relatedChanges as $changes) {
            if ($changes['created'] !== [] || $changes['updated'] !== [] || $changes['deleted'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function subjectChanges(): array
    {
        return $this->subjectChanges;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function derivedChanges(): array
    {
        return $this->derivedChanges;
    }

    /**
     * @return array<string, array{created: list<array<string, mixed>>, updated: list<array<string, mixed>>, deleted: list<array<string, mixed>>}>
     */
    public function relatedChanges(): array
    {
        return $this->relatedChanges;
    }

    /**
     * @return array{
     *     subject: array<string, array{0: mixed, 1: mixed}>,
     *     derived: array<string, array{0: mixed, 1: mixed}>,
     *     related: array<string, array{created: list<array<string, mixed>>, updated: list<array<string, mixed>>, deleted: list<array<string, mixed>>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subjectChanges,
            'derived' => $this->derivedChanges,
            'related' => $this->relatedChanges,
        ];
    }

    /**
     * @return array{
     *     subject: array<string, array{0: mixed, 1: mixed}>,
     *     derived: array<string, array{0: mixed, 1: mixed}>,
     *     related: array<string, array{created: list<array<string, mixed>>, updated: list<array<string, mixed>>, deleted: list<array<string, mixed>>}>
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
