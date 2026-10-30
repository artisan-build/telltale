{{-- Every Telltale page: the apps in the sidebar, and Setup at its foot above the shell's own links. --}}
@props(['title', 'active' => null])

@push('head')
    <link rel="stylesheet" href="{{ asset('build/assets/app.css') }}">
@endpush

<x-bfc-layout :title="$title">
    <x-slot:sidebar>
        <x-app-navigation />
    </x-slot:sidebar>

    <x-slot:sidebarFooter>
        <a href="{{ route('telltale.setup') }}"
           class="bfc-shell-link"
           @if ($active === 'setup') aria-current="page" @endif
           data-testid="setup-navigation">Setup</a>
    </x-slot:sidebarFooter>

    {{ $slot }}
</x-bfc-layout>
