<?php

use App\Enums\MessageStatus;
use App\Models\Agent;
use App\Models\Principal;
use App\Models\Topic;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('New Message')] class extends Component {
    use WithFileUploads;

    public string $title = '';

    public string $body = '';

    public string $target = 'topic';

    public ?int $topicId = null;

    public ?int $recipientPrincipalId = null;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(): void
    {
        $workspace = Auth::user()->currentWorkspace;

        abort_unless($workspace, 403);
        $topicSlug = request()->query('topic');

        if (! $topicSlug) {
            return;
        }

        $topic = $workspace->topics()->where('slug', $topicSlug)->firstOrFail();

        $this->topicId = $topic->id;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Topic>
     */
    #[Computed]
    public function availableTopics(): \Illuminate\Database\Eloquent\Collection
    {
        $workspace = Auth::user()->currentWorkspace;

        if (! $workspace) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return $workspace->topics()->get();
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

        $users = $team->members()
            ->orderBy('name')
            ->get()
            ->map(fn ($user) => $workspace->principalForUser($user)->load('user'));

        $agents = $workspace->agents()
            ->orderBy('name')
            ->get()
            ->map(fn (Agent $agent) => $workspace->principalForAgent($agent)->load('agent'));

        return $users->merge($agents)->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Principal>
     */
    #[Computed]
    public function availableRecipients(): \Illuminate\Support\Collection
    {
        return $this->availablePrincipals;
    }

    public function create(): void
    {
        $this->createMessage(MessageStatus::Draft);
    }

    public function send(): void
    {
        $this->createMessage(MessageStatus::Published);
    }

    public function updatedTarget(): void
    {
        $this->normalizeRecipient();
    }

    private function createMessage(MessageStatus $status): void
    {
        $workspace = Auth::user()->currentWorkspace;

        abort_unless($workspace, 403);

        $this->normalizeRecipient();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'target' => ['required', 'string', 'in:topic,principal'],
            'topicId' => ['required', 'integer'],
            'recipientPrincipalId' => ['nullable', 'required_if:target,principal', 'integer'],
            'uploads.*' => ['file', 'max:51200'],
        ], [], [
            'target' => __('delivery target'),
            'topicId' => __('topic'),
            'recipientPrincipalId' => __('recipient'),
            'uploads.*' => __('attachment'),
        ]);

        $topic = $workspace->topics()->findOrFail($validated['topicId']);
        $senderPrincipal = $workspace->principalForUser(Auth::user());
        $recipientPrincipalId = null;

        if ($validated['target'] === 'principal') {
            $recipient = $workspace->principals()
                ->whereKey($validated['recipientPrincipalId'])
                ->firstOrFail();

            $recipientPrincipalId = $recipient->id;
        }

        $message = $topic->messages()->create([
            'title' => $validated['title'],
            'body' => $validated['body'] ?: null,
            'status' => $status,
            'sender_principal_id' => $senderPrincipal->id,
            'recipient_principal_id' => $recipientPrincipalId,
        ]);

        foreach ($this->uploads as $upload) {
            $filename = $upload->getClientOriginalName();
            $path = $upload->storeAs(
                'attachments/'.Str::uuid(),
                $filename,
                'public'
            );

            $message->attachments()->create([
                'filename' => $filename,
                'path' => $path,
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
            ]);
        }

        Flux::toast(variant: 'success', text: $status === MessageStatus::Draft ? __('Draft created.') : __('Message added.'));

        $this->redirectRoute('messages.show', ['message' => $message], navigate: true);
    }

    private function normalizeRecipient(): void
    {
        if ($this->target !== 'principal') {
            $this->recipientPrincipalId = null;

            return;
        }

        $this->recipientPrincipalId = $this->recipientPrincipalId ?: $this->availablePrincipals->first()?->id;
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>
            {{ Auth::user()->currentWorkspace?->name }}
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('New message') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <form wire:submit="create" class="flex flex-col gap-6">
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_10rem_16rem]">
            <flux:input wire:model="title" :label="__('Title')" required autofocus />

            <flux:select wire:model.live="target" :label="__('To')" required>
                <flux:select.option value="topic">{{ __('Topic') }}</flux:select.option>
                <flux:select.option value="principal">{{ __('Principal') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model="topicId" :label="__('Topic')" placeholder="{{ __('Select a topic…') }}" required>
                @foreach ($this->availableTopics as $topic)
                    <flux:select.option :value="$topic->id">{{ $topic->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($target === 'principal')
            <flux:select wire:model="recipientPrincipalId" :label="__('Recipient')" placeholder="{{ __('Select a principal…') }}" required>
                @foreach ($this->availableRecipients as $recipient)
                    <flux:select.option :value="$recipient->id">
                        {{ $recipient->label() }} · {{ $recipient->type === \App\Models\Principal::TypeAgent ? __('Agent') : __('User') }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:textarea wire:model="body" :label="__('Body')" :placeholder="__('Write something...')" rows="12" />

        <div class="flex flex-col gap-3">
            <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

            <div x-data="{ uploading: false, progress: 0 }"
                 x-on:livewire-upload-start="uploading = true"
                 x-on:livewire-upload-finish="uploading = false"
                 x-on:livewire-upload-error="uploading = false"
                 x-on:livewire-upload-progress="progress = $event.detail.progress"
                 class="flex flex-col gap-2">
                <flux:input type="file" wire:model="uploads" multiple />

                <div x-show="uploading" class="h-1 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10">
                    <div class="h-full rounded-full bg-blue-500 transition-all" :style="`width: ${progress}%`"></div>
                </div>

                @error('uploads.*')
                    <flux:error>{{ $message }}</flux:error>
                @enderror
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <flux:button :href="request()->query('topic') ? route('topics.show', ['topic' => request()->query('topic')]) : route('dashboard')" wire:navigate variant="filled">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="submit" variant="filled">{{ __('Save draft') }}</flux:button>
            <flux:button wire:click="send" type="button" variant="primary">{{ __('Send') }}</flux:button>
        </div>
    </form>
</div>
