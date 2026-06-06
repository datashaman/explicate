<?php

use App\Enums\MessageStatus;
use App\Models\Attachment;
use App\Models\Agent;
use App\Models\Message;
use App\Models\Principal;
use App\Models\Topic;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::workspace'), Title('Message')] class extends Component {
    use WithFileUploads;

    public Topic $topic;

    public Message $message;

    public string $title = '';

    public string $body = '';

    public string $target = 'topic';

    public ?int $recipientPrincipalId = null;

    /** @var list<int> */
    public array $agentIds = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(Message $message): void
    {
        $topic = $message->topic;

        abort_unless(
            Auth::user()->currentWorkspace?->id === $topic->workspace_id,
            403
        );

        $this->topic = $topic;
        $this->title = $message->title;
        $this->body = $message->body ?? '';
        $this->target = $message->recipient_principal_id ? 'principal' : 'topic';
        $this->recipientPrincipalId = $message->recipient_principal_id;
        $this->agentIds = $message->agentTasks()
            ->where('event_type', \App\Models\AgentTask::EventMessageAssigned)
            ->pluck('agent_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Principal>
     */
    #[Computed]
    public function availablePrincipals(): \Illuminate\Support\Collection
    {
        $workspace = Auth::user()->currentWorkspace;
        $team = Auth::user()->currentTeam;

        if (! $workspace || ! $team) {
            return collect();
        }

        return $workspace->availablePrincipalsForTeam($team);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Principal>
     */
    #[Computed]
    public function availableRecipients(): \Illuminate\Support\Collection
    {
        return $this->availablePrincipals
            ->where('type', Principal::TypeUser)
            ->values();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Agent>
     */
    #[Computed]
    public function availableAgents(): \Illuminate\Database\Eloquent\Collection
    {
        $workspace = Auth::user()->currentWorkspace;

        if (! $workspace) {
            return Agent::query()->whereNull('id')->get();
        }

        return $this->topic->agents()->get();
    }

    public function save(): void
    {
        abort_unless($this->message->status === MessageStatus::Draft, 403);

        $workspace = Auth::user()->currentWorkspace;

        abort_unless($workspace, 403);

        $this->normalizeRecipient();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'target' => ['required', 'string', 'in:topic,principal'],
            'recipientPrincipalId' => ['nullable', 'required_if:target,principal', 'integer', $this->userRecipientRule($workspace->id)],
            'agentIds' => ['array'],
            'agentIds.*' => ['integer'],
        ], [], [
            'target' => __('delivery target'),
            'recipientPrincipalId' => __('recipient'),
            'agentIds' => __('requested agents'),
        ]);

        $this->message->update([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'recipient_principal_id' => $this->resolvedRecipientPrincipalId($validated),
        ]);
        $this->message->assignAgents($validated['agentIds']);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function updatedTarget(): void
    {
        $this->normalizeRecipient();
    }

    public function publish(): void
    {
        abort_unless($this->message->status === MessageStatus::Draft, 403);

        $workspace = Auth::user()->currentWorkspace;

        abort_unless($workspace, 403);

        $this->normalizeRecipient();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'target' => ['required', 'string', 'in:topic,principal'],
            'recipientPrincipalId' => ['nullable', 'required_if:target,principal', 'integer', $this->userRecipientRule($workspace->id)],
            'agentIds' => ['array'],
            'agentIds.*' => ['integer'],
        ], [], [
            'target' => __('delivery target'),
            'recipientPrincipalId' => __('recipient'),
            'agentIds' => __('requested agents'),
        ]);

        $this->message->update([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'recipient_principal_id' => $this->resolvedRecipientPrincipalId($validated),
            'sender_principal_id' => $this->message->sender_principal_id ?: $workspace->principalForUser(Auth::user())->id,
            'status' => MessageStatus::Published,
        ]);
        $this->message->assignAgents($validated['agentIds']);
    }

    public function unpublish(): void
    {
        $this->message->update(['status' => MessageStatus::Draft]);

        $this->title = $this->message->title;
        $this->body = $this->message->body ?? '';
        $this->target = $this->message->recipient_principal_id ? 'principal' : 'topic';
        $this->recipientPrincipalId = $this->message->recipient_principal_id;
        $this->agentIds = $this->message->agentTasks()
            ->where('event_type', \App\Models\AgentTask::EventMessageAssigned)
            ->pluck('agent_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function archive(): void
    {
        $this->message->update(['status' => MessageStatus::Archived]);
    }

    public function unarchive(): void
    {
        $this->message->update(['status' => MessageStatus::Draft]);

        $this->title = $this->message->title;
        $this->body = $this->message->body ?? '';
        $this->target = $this->message->recipient_principal_id ? 'principal' : 'topic';
        $this->recipientPrincipalId = $this->message->recipient_principal_id;
        $this->agentIds = $this->message->agentTasks()
            ->where('event_type', \App\Models\AgentTask::EventMessageAssigned)
            ->pluck('agent_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function uploadAttachments(): void
    {
        abort_unless($this->message->status === MessageStatus::Draft, 403);

        $this->validate([
            'uploads.*' => ['file', 'max:51200'],
        ]);

        foreach ($this->uploads as $upload) {
            $filename = $upload->getClientOriginalName();
            $path = $upload->storeAs(
                'attachments/'.Str::uuid(),
                $filename,
                'public'
            );

            $this->message->attachments()->create([
                'filename' => $filename,
                'path' => $path,
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
            ]);
        }

        $this->reset('uploads');

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function deleteAttachment(int $attachmentId): void
    {
        abort_unless($this->message->status === MessageStatus::Draft, 403);

        $attachment = $this->message->attachments()->findOrFail($attachmentId);

        Storage::disk('public')->delete($attachment->path);

        $attachment->delete();

        Flux::toast(variant: 'success', text: __('Attachment deleted.'));
    }

    /**
     * @param  array{target: string, recipientPrincipalId: int|null}  $validated
     */
    private function resolvedRecipientPrincipalId(array $validated): ?int
    {
        if ($validated['target'] !== 'principal') {
            return null;
        }

        $workspace = Auth::user()->currentWorkspace;

        abort_unless($workspace, 403);

        return $workspace->principals()
            ->where('type', Principal::TypeUser)
            ->whereKey($validated['recipientPrincipalId'])
            ->firstOrFail()
            ->id;
    }

    private function normalizeRecipient(): void
    {
        if ($this->target !== 'principal') {
            $this->recipientPrincipalId = null;

            return;
        }

        $this->recipientPrincipalId = $this->recipientPrincipalId ?: $this->availableRecipients->first()?->id;
    }

    private function userRecipientRule(int $workspaceId): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('principals', 'id')
            ->where('workspace_id', $workspaceId)
            ->where('type', Principal::TypeUser);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-3 xl:flex-1">
    <section class="flex min-h-[calc(100dvh-4rem)] flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none" data-test="message-panel">
        <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
            <flux:heading size="sm" class="min-w-0 flex-1 truncate">{{ $message->title }}</flux:heading>
        </div>

        <div class="flex flex-1 flex-col gap-6 overflow-auto px-4 py-4 xl:min-h-0">
            @if ($message->status === App\Enums\MessageStatus::Draft)
                {{-- Draft: editable --}}
                <form wire:submit="save" class="flex flex-col gap-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <flux:input wire:model="title" class="flex-1" required />

                        <div class="flex shrink-0 items-center gap-2">
                            <flux:badge :color="$message->status->color()" size="sm">{{ $message->status->label() }}</flux:badge>
                            <flux:button wire:click="archive" type="button" size="sm" icon="archive-box">{{ __('Archive') }}</flux:button>
                            <flux:button wire:click="publish" type="button" size="sm" variant="primary" icon="paper-airplane">{{ __('Send') }}</flux:button>
                        </div>
                    </div>

                    @include('partials.message-routing-fields', [
                        'targetModel' => 'target',
                        'targetValue' => $target,
                        'topicName' => $topic->name,
                        'recipientModel' => 'recipientPrincipalId',
                        'agentIdsModel' => 'agentIds',
                        'availableRecipients' => $this->availableRecipients,
                        'availableAgents' => $this->availableAgents,
                        'canChangeTopic' => false,
                        'testPrefix' => 'message',
                    ])

                    <flux:textarea wire:model="body" :placeholder="__('Write something...')" rows="12" />

                    <div class="flex justify-end">
                        <flux:button type="submit" size="sm" variant="filled">{{ __('Save draft') }}</flux:button>
                    </div>
                </form>
            @else
                {{-- Non-draft messages are read-only. --}}
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <flux:heading size="xl" class="min-w-0 flex-1 truncate">{{ $message->title }}</flux:heading>

                    <div class="flex shrink-0 items-center gap-2">
                        @if ($message->status === App\Enums\MessageStatus::Published)
                            <flux:button wire:click="unpublish" size="sm" icon="arrow-uturn-left">{{ __('Return to draft') }}</flux:button>
                            <flux:button wire:click="archive" size="sm" icon="archive-box">{{ __('Archive') }}</flux:button>
                        @elseif ($message->status === App\Enums\MessageStatus::Archived)
                            <flux:badge :color="$message->status->color()" size="sm">{{ $message->status->label() }}</flux:badge>
                            <flux:button wire:click="unarchive" size="sm" icon="archive-box-x-mark">{{ __('Unarchive') }}</flux:button>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($message->sender)
                        <flux:badge color="zinc" size="sm">{{ __('From') }}: {{ $message->sender->label() }}</flux:badge>
                    @endif

                    <flux:badge color="zinc" size="sm">
                        {{ __('To') }}:
                        {{ $message->recipient ? $message->recipient->label() : $topic->name }}
                    </flux:badge>

                    @foreach ($message->assignedAgents as $agent)
                        <flux:badge color="amber" size="sm">{{ __('Assigned') }}: {{ $agent->name }}</flux:badge>
                    @endforeach
                </div>

                <div>
                    @if ($message->body)
                        <flux:text class="whitespace-pre-wrap text-sm leading-relaxed text-neutral-700 dark:text-neutral-300">{{ $message->body }}</flux:text>
                    @else
                        <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No content.') }}</flux:text>
                    @endif
                </div>
            @endif

            @include('partials.message-attachments', [
                'message' => $message,
                'uploadAction' => 'uploadAttachments',
                'uploadModel' => 'uploads',
                'uploadError' => 'uploads.*',
                'deleteAction' => 'deleteAttachment',
            ])
        </div>
    </section>
</div>
