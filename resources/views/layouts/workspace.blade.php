<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-white dark:bg-zinc-800">
        <main class="flex min-h-dvh w-full flex-col px-2 pt-2 pb-1 sm:px-3 sm:pt-3 sm:pb-2 lg:px-3">
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
