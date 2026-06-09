<div class="grid min-h-0 flex-1 grid-rows-[auto_minmax(0,1fr)] items-stretch gap-3 lg:grid-cols-[16rem_minmax(0,1fr)] lg:grid-rows-none">
    <aside class="h-full overflow-hidden rounded-lg border border-neutral-300 bg-white shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
        <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
            <flux:heading size="sm">{{ __('Settings') }}</flux:heading>
            <flux:subheading>{{ __('Profile, teams, and account preferences') }}</flux:subheading>
        </div>

        <flux:navlist class="p-2" aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" icon="user" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" icon="lock-closed" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('teams.index')" :current="request()->routeIs('teams.*')" icon="users" wire:navigate>{{ __('Teams') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" icon="swatch" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            <flux:navlist.item :href="route('workspace.edit')" icon="building-office" wire:navigate>{{ __('Workspace') }}</flux:navlist.item>
        </flux:navlist>
    </aside>

    <section class="flex min-w-0 flex-col overflow-hidden rounded-lg border border-neutral-300 bg-white shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
        <header class="border-b border-neutral-200 bg-emerald-50 px-4 py-3 dark:border-neutral-800 dark:bg-emerald-950/30">
            <flux:heading size="sm">{{ $heading ?? '' }}</flux:heading>
            <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>
        </header>

        <div class="w-full max-w-3xl flex-1 overflow-auto p-4 sm:p-6">
            {{ $slot }}
        </div>
    </section>
</div>
