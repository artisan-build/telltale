@push('head')
    <link rel="stylesheet" href="{{ asset('build/assets/app.css') }}">
@endpush

<x-bfc-layout title="Telltale setup">
<div class="mx-auto grid max-w-4xl gap-8">
    <nav data-testid="product-navigation" class="bfc-nav">
        <a href="{{ route('bfc.dashboard') }}">Apps</a>
        <a href="{{ route('telltale.setup') }}" aria-current="page">Setup</a>
        <a href="{{ route('bfc.ui.home') }}">Account settings</a>
    </nav>

    <header data-testid="setup-introduction" class="grid gap-4">
        <p class="text-sm font-semibold uppercase tracking-widest text-clay-muted">NativePHP integration</p>
        <h1 class="font-display text-4xl font-normal tracking-tight text-clay-ink sm:text-5xl">Set up Telltale in this order</h1>
        <p class="max-w-2xl text-lg leading-8 text-clay-soft">Start with the permanent domain. Neither Telltale nor Scalpels changes your repository, build environment, or app configuration.</p>
    </header>

    <ol data-testid="setup-steps" class="grid gap-4">
        @foreach ($steps as $index => $step)
            <li class="bfc-panel grid gap-3 sm:grid-cols-[3rem_1fr]">
                <span class="flex size-10 items-center justify-center rounded-full bg-clay-ink font-semibold text-clay-surface">{{ $index + 1 }}</span>
                <div>
                    <h2 class="font-display text-2xl font-normal text-clay-ink">{{ $step['title'] }}</h2>
                    <p class="mt-2 leading-7 text-clay-soft">{{ $step['body'] }}</p>
                    @if ($step['code'] !== null)
                        <pre class="mt-4 overflow-x-auto rounded-xl bg-clay-ink p-4 text-sm text-clay-surface"><code>{{ $step['code'] }}</code></pre>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>

    <aside data-testid="automation-boundary" class="rounded-2xl border border-clay-edge bg-clay-slip p-5 leading-7 text-clay-soft">
        <strong class="text-clay-ink">Provisioning is the boundary.</strong>
        {{ $automationBoundary }}
    </aside>

    <aside data-testid="v1-boundaries" class="rounded-2xl border border-clay-edge bg-clay-surface p-5 leading-7 text-clay-soft">
        <strong class="text-clay-ink">Know the v1 boundaries.</strong>
        {{ $v1LimitSummary }}
    </aside>

    <section data-testid="documentation-links" class="bfc-panel grid gap-3">
        <h2 class="font-display text-2xl font-normal text-clay-ink">Release declarations</h2>
        <p class="leading-7 text-clay-soft">The checked-in documentation includes a usable Apple privacy manifest, exact App Store and Google Play answers, the platform data reference, trace-header format, and every v1 limit.</p>
        <p class="text-sm text-clay-muted">See <code>docs/privacy.md</code> and <code>docs/data-reference.md</code> in the Telltale repository.</p>
    </section>
</div>
</x-bfc-layout>
