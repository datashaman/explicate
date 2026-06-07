<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable(['thread_id', 'agent_id', 'task_list'])]
class ThreadAgentState extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'task_list' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return list<array{id: int, text: string, completed: bool, position: int}>
     */
    public function taskListItems(): array
    {
        return $this->normalizeTaskListItems($this->task_list ?? []);
    }

    /**
     * @param  list<array{id?: int, text?: mixed, completed?: mixed, position?: mixed}>  $items
     * @return list<array{id: int, text: string, completed: bool, position: int}>
     */
    public function replaceTaskListItems(array $items): array
    {
        $normalized = $this->normalizeTaskListItems($items);

        $this->forceFill(['task_list' => $normalized])->save();

        return $normalized;
    }

    /**
     * @return list<array{id: int, text: string, completed: bool, position: int}>
     */
    public function addTaskListItem(string $text): array
    {
        $text = $this->normalizeTaskText($text);

        if ($text === '') {
            throw new InvalidArgumentException('Task list item text cannot be empty.');
        }

        $items = $this->taskListItems();
        $items[] = [
            'id' => $this->nextTaskListItemId($items),
            'text' => $text,
            'completed' => false,
            'position' => count($items) + 1,
        ];

        return $this->replaceTaskListItems($items);
    }

    /**
     * @return list<array{id: int, text: string, completed: bool, position: int}>
     */
    public function updateTaskListItem(int $itemId, ?string $text = null, ?bool $completed = null, ?int $position = null): array
    {
        $items = $this->taskListItems();
        $index = $this->taskListItemIndex($items, $itemId);

        if ($index === null) {
            throw new InvalidArgumentException("Task list item {$itemId} not found.");
        }

        if ($text !== null) {
            $text = $this->normalizeTaskText($text);

            if ($text === '') {
                throw new InvalidArgumentException('Task list item text cannot be empty.');
            }

            $items[$index]['text'] = $text;
        }

        if ($completed !== null) {
            $items[$index]['completed'] = $completed;
        }

        if ($position !== null) {
            return $this->moveTaskListItem($itemId, $position, $items);
        }

        return $this->replaceTaskListItems($items);
    }

    /**
     * @return list<array{id: int, text: string, completed: bool, position: int}>
     */
    public function removeTaskListItem(int $itemId): array
    {
        $items = array_values(array_filter(
            $this->taskListItems(),
            fn (array $item): bool => $item['id'] !== $itemId,
        ));

        if (count($items) === count($this->taskListItems())) {
            throw new InvalidArgumentException("Task list item {$itemId} not found.");
        }

        return $this->replaceTaskListItems($items);
    }

    /**
     * @return list<array{id: int, text: string, completed: bool, position: int}>
     */
    public function setTaskListItemCompleted(int $itemId, bool $completed): array
    {
        return $this->updateTaskListItem(itemId: $itemId, completed: $completed);
    }

    /**
     * @return list<array{id: int, text: string, completed: bool, position: int}>
     */
    public function moveTaskListItem(int $itemId, int $position, ?array $items = null): array
    {
        $items ??= $this->taskListItems();
        $index = $this->taskListItemIndex($items, $itemId);

        if ($index === null) {
            throw new InvalidArgumentException("Task list item {$itemId} not found.");
        }

        $item = $items[$index];
        array_splice($items, $index, 1);

        $targetIndex = max(0, min(count($items), $position - 1));
        array_splice($items, $targetIndex, 0, [$item]);

        foreach ($items as $itemIndex => $item) {
            $items[$itemIndex]['position'] = $itemIndex + 1;
        }

        return $this->replaceTaskListItems($items);
    }

    /**
     * @param  list<array{id?: int, text?: mixed, completed?: mixed, position?: mixed}>  $items
     * @return list<array{id: int, text: string, completed: bool, position: int}>
     */
    private function normalizeTaskListItems(array $items): array
    {
        $normalized = array_values(array_filter(array_map(function (array $item): array {
            return [
                'id' => max(1, (int) ($item['id'] ?? 0)),
                'text' => $this->normalizeTaskText((string) ($item['text'] ?? '')),
                'completed' => (bool) ($item['completed'] ?? false),
                'position' => (int) ($item['position'] ?? 0),
            ];
        }, $items), fn (array $item): bool => $item['text'] !== ''));

        usort($normalized, function (array $left, array $right): int {
            return [$left['position'], $left['id']] <=> [$right['position'], $right['id']];
        });

        foreach ($normalized as $index => $item) {
            $normalized[$index]['position'] = $index + 1;
        }

        return $normalized;
    }

    /**
     * @param  list<array{id: int, text: string, completed: bool, position: int}>  $items
     */
    private function nextTaskListItemId(array $items): int
    {
        return (collect($items)->max('id') ?? 0) + 1;
    }

    /**
     * @param  list<array{id: int, text: string, completed: bool, position: int}>  $items
     */
    private function taskListItemIndex(array $items, int $itemId): ?int
    {
        foreach ($items as $index => $item) {
            if ($item['id'] === $itemId) {
                return $index;
            }
        }

        return null;
    }

    private function normalizeTaskText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
