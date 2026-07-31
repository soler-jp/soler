<?php

namespace App\Services;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Contracts\ResolvesBusinessUnit;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\Todo;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TodoService
{
    use AuthorizesBusinessUnitAccess;

    public function register(
        BusinessUnit $businessUnit,
        string $title,
        User $actor,
        ?FiscalYear $fiscalYear = null,
        ?string $body = null,
        ?CarbonInterface $dueOn = null,
        string $priority = Todo::PRIORITY_NORMAL,
        string $sourceType = Todo::SOURCE_TYPE_MANUAL,
        ?Model $sourceModel = null,
    ): Todo {
        $this->authorizeBusinessUnitAccess($businessUnit, $actor, 'この事業体の Todo を登録する権限がありません。');

        $this->assertPriorityIsSupported($priority);
        $this->assertFiscalYearBelongsToBusinessUnit($businessUnit, $fiscalYear);
        $this->assertSourceModelIsValid($businessUnit, $sourceType, $sourceModel);

        $todo = new Todo([
            'title' => $title,
            'body' => $body,
            'due_on' => $dueOn,
            'priority' => $priority,
            'source_type' => $sourceType,
            'status' => Todo::STATUS_PENDING,
        ]);

        $todo->businessUnit()->associate($businessUnit);
        $todo->fiscalYear()->associate($fiscalYear);

        if ($sourceModel !== null) {
            $todo->source()->associate($sourceModel);
        }

        $todo->save();

        return $todo;
    }

    public function complete(Todo $todo, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($todo, $actor, 'この Todo を完了する権限がありません。');
        $this->assertTodoIsPending($todo);

        $todo->forceFill([
            'status' => Todo::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();
    }

    public function dismiss(Todo $todo, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($todo, $actor, 'この Todo を却下する権限がありません。');
        $this->assertTodoIsPending($todo);

        $todo->forceFill([
            'status' => Todo::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ])->save();
    }

    /**
     * @return Collection<int, Todo>
     */
    public function listPending(
        BusinessUnit $businessUnit,
        User $actor,
        ?FiscalYear $fiscalYear = null,
    ): Collection {
        $this->authorizeBusinessUnitAccess($businessUnit, $actor, 'この事業体の Todo を参照する権限がありません。');
        $this->assertFiscalYearBelongsToBusinessUnit($businessUnit, $fiscalYear);

        return Todo::query()
            ->whereBelongsTo($businessUnit)
            ->where('status', Todo::STATUS_PENDING)
            ->when(
                $fiscalYear !== null,
                fn($query) => $query->where(function ($query) use ($fiscalYear): void {
                    $query
                        ->whereBelongsTo($fiscalYear)
                        ->orWhereNull('fiscal_year_id');
                })
            )
            ->orderByRaw(
                'CASE priority
                    WHEN ? THEN 0
                    WHEN ? THEN 1
                    WHEN ? THEN 2
                    ELSE 3
                END',
                [Todo::PRIORITY_HIGH, Todo::PRIORITY_NORMAL, Todo::PRIORITY_LOW]
            )
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->orderBy('created_at')
            ->get();
    }

    protected function assertPriorityIsSupported(string $priority): void
    {
        if (! in_array($priority, Todo::PRIORITIES, true)) {
            throw new DomainException('未対応の Todo priority です。');
        }
    }

    protected function assertFiscalYearBelongsToBusinessUnit(
        BusinessUnit $businessUnit,
        ?FiscalYear $fiscalYear,
    ): void {
        if ($fiscalYear !== null && ! $fiscalYear->resolveBusinessUnit()->is($businessUnit)) {
            throw new DomainException('指定された会計年度は対象の事業体に属していません。');
        }
    }

    protected function assertSourceModelIsValid(
        BusinessUnit $businessUnit,
        string $sourceType,
        ?Model $sourceModel,
    ): void {
        if (! array_key_exists($sourceType, Todo::$allowedSourceModels)) {
            throw new DomainException('未対応の Todo source_type です。');
        }

        $allowedSourceModels = Todo::$allowedSourceModels[$sourceType];

        if ($allowedSourceModels === []) {
            if ($sourceModel !== null) {
                throw new DomainException('この source_type では発生源モデルを指定できません。');
            }

            return;
        }

        if ($sourceModel === null) {
            throw new DomainException('この source_type では発生源モデルが必須です。');
        }

        $isAllowedClass = collect($allowedSourceModels)
            ->contains(fn(string $allowedClass): bool => $sourceModel instanceof $allowedClass);

        if (! $isAllowedClass) {
            throw new DomainException('この source_type では指定された発生源モデルを使用できません。');
        }

        if (! $sourceModel instanceof ResolvesBusinessUnit) {
            throw new DomainException('発生源モデルは事業体を解決できる必要があります。');
        }

        if (! $sourceModel->exists) {
            throw new DomainException('発生源モデルは保存済みでなければなりません。');
        }

        if (! $sourceModel->resolveBusinessUnit()->is($businessUnit)) {
            throw new DomainException('指定された発生源モデルは対象の事業体に属していません。');
        }
    }

    protected function assertTodoIsPending(Todo $todo): void
    {
        if ($todo->status !== Todo::STATUS_PENDING) {
            throw new DomainException('pending の Todo のみ状態変更できます。');
        }
    }
}
