<?php

use App\Enums\MessageStatus;
use App\Models\Topic;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    public string $topicName = '';

    public bool $showArchived = false;

    public function workspace(): ?\App\Models\Workspace
    {
        return Auth::user()->currentWorkspace;
    }

    /**
     * @return list<array{href: string, name: string, date: string}>
     */
    public function items(): array
    {
        $workspace = $this->workspace();

        if (! $workspace) {
            return [];
        }

        return $workspace->topics()
            ->withCount([
                'messages as draft_count' => fn ($q) => $q->where('status', MessageStatus::Draft),
                'messages as published_count' => fn ($q) => $q->where('status', MessageStatus::Published),
                'messages as archived_count' => fn ($q) => $q->where('status', MessageStatus::Archived),
            ])
            ->get()
            ->map(fn (Topic $topic) => [
                'href' => route('topics.show', ['topic' => $topic->slug]),
                'name' => $topic->name,
                'counts' => array_filter([
                    ['label' => 'Draft', 'color' => 'zinc', 'value' => $topic->draft_count],
                    ['label' => 'Published', 'color' => 'green', 'value' => $topic->published_count],
                    ...($this->showArchived ? [['label' => 'Archived', 'color' => 'yellow', 'value' => $topic->archived_count]] : []),
                ], fn ($c) => $c['value'] > 0),
            ])
            ->all();
    }

    public function createTopic(): void
    {
        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $validated = $this->validate([
            'topicName' => ['required', 'string', 'max:255'],
        ]);

        $workspace->topics()->create(['name' => $validated['topicName']]);

        $this->reset('topicName');

        Flux::modal('new-topic')->close();

        Flux::toast(variant: 'success', text: __('Topic created.'));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    @if ($this->workspace())
        @include('partials.folder-view', [
            'breadcrumbs' => [
                ['label' => $this->workspace()->name],
            ],
            'items' => collect($this->items()),
            'icon' => 'hashtag',
            'iconClass' => 'size-12 text-blue-400 group-hover:text-blue-300',
            'emptyText' => __('No topics'),
            'createModal' => 'new-topic',
            'createLabel' => __('New topic'),
            'showArchivedModel' => 'showArchived',
        ])

        <flux:modal name="new-topic" :show="$errors->isNotEmpty()" focusable class="max-w-sm">
            <form wire:submit="createTopic" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('New topic') }}</flux:heading>
                    <flux:subheading>{{ __('Give your topic a name.') }}</flux:subheading>
                </div>

                <flux:input wire:model="topicName" :label="__('Name')" type="text" required autofocus />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Create') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @else
        <div class="flex flex-1 flex-col items-center justify-center gap-4">
            <div class="text-center">
                <flux:heading>{{ __('No workspace selected') }}</flux:heading>
                <flux:subheading>{{ __('Select or create a workspace to get started.') }}</flux:subheading>
            </div>
            <flux:modal.trigger name="create-workspace-switcher">
                <flux:button variant="primary" icon="plus">{{ __('New workspace') }}</flux:button>
            </flux:modal.trigger>
        </div>
    @endif
</div>
