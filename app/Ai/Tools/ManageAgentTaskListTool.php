<?php

namespace App\Ai\Tools;

use App\Models\AgentTask;
use App\Models\ThreadAgentState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ManageAgentTaskListTool implements Tool
{
    public function __construct(private readonly AgentTask $task) {}

    public function name(): string
    {
        return 'task-list';
    }

    public function description(): Stringable|string
    {
        return 'Manage the agent\'s private task list for the current thread. Use it to list, add, update, remove, check, uncheck, or reorder steps.';
    }

    public function handle(Request $request): Stringable|string
    {
        $state = $this->state();
        $action = strtolower((string) ($request['action'] ?? 'list'));

        return match ($action) {
            'list' => $this->payload($state, 'listed'),
            'add' => $this->addItem($state, $request),
            'update' => $this->updateItem($state, $request),
            'remove' => $this->removeItem($state, $request),
            'check' => $this->setCompleted($state, $request, true),
            'uncheck' => $this->setCompleted($state, $request, false),
            'move' => $this->moveItem($state, $request),
            default => $this->errorPayload($state, "Unknown task list action [{$action}]."),
        };
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'add', 'update', 'remove', 'check', 'uncheck', 'move'])
                ->required(),
            'item_id' => $schema->integer()->min(1),
            'text' => $schema->string(),
            'position' => $schema->integer()->min(1),
        ];
    }

    private function state(): ThreadAgentState
    {
        $thread = $this->task->thread();

        return $thread->agentStateFor($this->task->agent);
    }

    private function addItem(ThreadAgentState $state, Request $request): string
    {
        $text = trim((string) ($request['text'] ?? ''));

        if ($text === '') {
            return $this->errorPayload($state, 'Task text is required when adding an item.');
        }

        $items = $state->addTaskListItem($text);

        return $this->payload($state, 'added', [
            'item' => $items[array_key_last($items)],
        ]);
    }

    private function updateItem(ThreadAgentState $state, Request $request): string
    {
        $itemId = $this->itemId($request);
        $text = $request->offsetExists('text') ? trim((string) $request['text']) : null;
        $position = $request->offsetExists('position') ? (int) $request['position'] : null;

        if ($text === null && $position === null && ! $request->offsetExists('completed')) {
            return $this->errorPayload($state, 'Provide text, position, or completed when updating an item.');
        }

        $items = $state->updateTaskListItem(
            itemId: $itemId,
            text: $text,
            completed: $request->offsetExists('completed') ? (bool) $request['completed'] : null,
            position: $position,
        );

        return $this->payload($state, 'updated', [
            'item' => $this->findItem($items, $itemId),
        ]);
    }

    private function removeItem(ThreadAgentState $state, Request $request): string
    {
        $itemId = $this->itemId($request);
        $items = $state->removeTaskListItem($itemId);

        return $this->payload($state, 'removed', [
            'removed_item_id' => $itemId,
            'items' => $items,
        ]);
    }

    private function setCompleted(ThreadAgentState $state, Request $request, bool $completed): string
    {
        $itemId = $this->itemId($request);
        $items = $state->setTaskListItemCompleted($itemId, $completed);

        return $this->payload($state, $completed ? 'checked' : 'unchecked', [
            'item' => $this->findItem($items, $itemId),
        ]);
    }

    private function moveItem(ThreadAgentState $state, Request $request): string
    {
        $itemId = $this->itemId($request);
        $position = $this->position($request);
        $items = $state->moveTaskListItem($itemId, $position);

        return $this->payload($state, 'moved', [
            'item' => $this->findItem($items, $itemId),
        ]);
    }

    private function itemId(Request $request): int
    {
        $itemId = (int) ($request['item_id'] ?? 0);

        if ($itemId < 1) {
            throw new InvalidArgumentException('Task list item_id is required.');
        }

        return $itemId;
    }

    private function position(Request $request): int
    {
        $position = (int) ($request['position'] ?? 0);

        if ($position < 1) {
            throw new InvalidArgumentException('Task list position is required.');
        }

        return $position;
    }

    /**
     * @param  list<array{id: int, text: string, completed: bool, position: int}>  $items
     * @return array{id: int, text: string, completed: bool, position: int}
     */
    private function findItem(array $items, int $itemId): array
    {
        foreach ($items as $item) {
            if ($item['id'] === $itemId) {
                return $item;
            }
        }

        throw new InvalidArgumentException("Task list item {$itemId} not found.");
    }

    private function payload(ThreadAgentState $state, string $action, array $extra = []): string
    {
        return json_encode(array_merge([
            'action' => $action,
            'thread_id' => $state->thread_id,
            'agent_slug' => $state->agent->slug,
            'items' => $state->taskListItems(),
            'counts' => [
                'total' => count($state->taskListItems()),
                'completed' => collect($state->taskListItems())->where('completed', true)->count(),
                'open' => collect($state->taskListItems())->where('completed', false)->count(),
            ],
        ], $extra), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function errorPayload(ThreadAgentState $state, string $message): string
    {
        return json_encode([
            'error' => $message,
            'thread_id' => $state->thread_id,
            'agent_slug' => $state->agent->slug,
            'items' => $state->taskListItems(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
