<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="h-dvh overflow-hidden bg-white dark:bg-zinc-800">
        <main class="flex h-dvh min-h-0 w-full flex-col gap-2 overflow-hidden p-2">
            <header class="flex items-center justify-between gap-3 rounded-lg border border-neutral-300 bg-white px-3 py-2 shadow-sm shadow-black/[0.04] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none">
                <div class="flex min-w-0 items-center gap-4">
                    <a href="{{ route('dashboard') }}" wire:navigate class="flex min-w-0 items-center gap-2 text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                        <flux:icon name="layout-grid" class="size-4 shrink-0 text-neutral-500 dark:text-neutral-400" />
                        <span class="truncate">{{ __('Explicate') }}</span>
                    </a>

                    <nav class="hidden items-center gap-1 sm:flex">
                        <flux:button :href="route('dashboard')" wire:navigate size="xs" :variant="request()->routeIs('dashboard') ? 'primary' : 'ghost'">
                            {{ __('Dashboard') }}
                        </flux:button>
                        <flux:button :href="route('briefs')" wire:navigate size="xs" :variant="request()->routeIs('briefs') ? 'primary' : 'ghost'" data-test="briefs-nav-link">
                            {{ __('Briefs') }}
                        </flux:button>
                    </nav>
                </div>

                <div class="flex min-w-0 items-center gap-2">
                    <livewire:workspace-switcher />
                    <x-desktop-user-menu :showTeam="false" />
                </div>
            </header>

            {{ $slot }}
        </main>

        <livewire:create-team-modal />
        <livewire:create-workspace-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
