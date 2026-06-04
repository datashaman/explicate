<?php

use App\Enums\MessageStatus;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Topic;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(Topic $topic, Message $message): void
    {
        abort_unless(
            Auth::user()->currentWorkspace?->id === $topic->workspace_id,
            403
        );

        abort_unless($message->topic_id === $topic->id, 404);

        $this->title = $message->title;
        $this->body = $message->body ?? '';
    }

    public function save(): void
    {
        abort_unless($this->message->status === MessageStatus::Draft, 403);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ]);

        $this->message->update($validated);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function publish(): void
    {
        abort_unless($this->message->status === MessageStatus::Draft, 403);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ]);

        $this->message->update([...$validated, 'status' => MessageStatus::Published]);
    }

    public function unpublish(): void
    {
        $this->message->update(['status' => MessageStatus::Draft]);

        $this->title = $this->message->title;
        $this->body = $this->message->body ?? '';
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
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    {{-- Breadcrumbs --}}
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>
            {{ Auth::user()->currentWorkspace?->name }}
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('topics.show', ['topic' => $topic->slug])" wire:navigate>
            {{ $topic->name }}
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $message->title }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    @if ($message->status === App\Enums\MessageStatus::Draft)
        {{-- Draft: editable --}}
        <form wire:submit="save" class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-4">
                <flux:input wire:model="title" class="flex-1" required />

                <div class="flex shrink-0 items-center gap-2">
                    <flux:badge :color="$message->status->color()" size="sm">{{ $message->status->label() }}</flux:badge>
                    <flux:button wire:click="archive" type="button" size="sm" icon="archive-box">{{ __('Archive') }}</flux:button>
                    <flux:button wire:click="publish" type="button" size="sm" variant="primary" icon="arrow-up-circle">{{ __('Publish') }}</flux:button>
                </div>
            </div>

            <flux:textarea wire:model="body" :placeholder="__('Write something...')" rows="12" />

            <div class="flex justify-end">
                <flux:button type="submit" size="sm" variant="filled">{{ __('Save draft') }}</flux:button>
            </div>
        </form>
    @else
        {{-- Published / Archived: read-only --}}
        <div class="flex items-start justify-between gap-4">
            <flux:heading size="xl" class="min-w-0 flex-1 truncate">{{ $message->title }}</flux:heading>

            <div class="flex shrink-0 items-center gap-2">
                <flux:badge :color="$message->status->color()" size="sm">{{ $message->status->label() }}</flux:badge>

                @if ($message->status === App\Enums\MessageStatus::Published)
                    <flux:button wire:click="unpublish" size="sm" icon="arrow-down-circle">{{ __('Unpublish') }}</flux:button>
                    <flux:button wire:click="archive" size="sm" icon="archive-box">{{ __('Archive') }}</flux:button>
                @elseif ($message->status === App\Enums\MessageStatus::Archived)
                    <flux:button wire:click="unarchive" size="sm" icon="archive-box-x-mark">{{ __('Unarchive') }}</flux:button>
                @endif
            </div>
        </div>

        <div>
            @if ($message->body)
                <flux:text class="whitespace-pre-wrap text-sm leading-relaxed text-neutral-700 dark:text-neutral-300">{{ $message->body }}</flux:text>
            @else
                <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No content.') }}</flux:text>
            @endif
        </div>
    @endif

    {{-- Attachments --}}
    <div class="flex flex-col gap-3">
        <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

        @if ($message->attachments->isNotEmpty())
            <div class="divide-y divide-neutral-100 dark:divide-white/5 rounded-lg border border-neutral-200 dark:border-white/10">
                @foreach ($message->attachments as $attachment)
                    <div class="flex items-center gap-3 px-3 py-2">
                        <flux:icon name="paper-clip" class="size-4 shrink-0 text-neutral-400" />
                        <a href="{{ $attachment->url() }}" target="_blank"
                           class="flex-1 truncate text-sm text-neutral-700 hover:underline dark:text-neutral-300">
                            {{ $attachment->filename }}
                        </a>
                        <flux:text class="shrink-0 text-xs text-neutral-400">{{ $attachment->formattedSize() }}</flux:text>
                        @if ($message->status === App\Enums\MessageStatus::Draft)
                            <flux:button
                                wire:click="deleteAttachment({{ $attachment->id }})"
                                wire:confirm="{{ __('Delete this attachment?') }}"
                                icon="trash"
                                variant="ghost"
                                size="xs"
                                class="text-neutral-400 hover:text-red-500"
                            />
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($message->status === App\Enums\MessageStatus::Draft)
        <form wire:submit="uploadAttachments"
              x-data="{ uploading: false, progress: 0 }"
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

            <div class="flex justify-end">
                <flux:button type="submit" size="sm" icon="arrow-up-tray">{{ __('Upload') }}</flux:button>
            </div>
        </form>
        @endif
    </div>
</div>
