@push('head')
    <link rel="stylesheet" href="{{ asset('build/assets/app.css') }}">
@endpush

<x-bfc-layout title="Telltale apps">
<div class="grid gap-8 lg:grid-cols-[18rem_1fr]">
    <aside class="grid content-start gap-6">
        <section data-testid="app-create" class="bfc-panel grid gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-widest text-clay-muted">App management</p>
                <h1 class="mt-1 font-display text-3xl font-normal tracking-tight text-clay-ink">Your apps</h1>
                <p class="mt-2 text-sm leading-6 text-clay-soft">Up to 100 apps are shown. Analytics stay in MCP; this screen only manages ingest and recent health.</p>
            </div>

            <form method="POST" action="{{ route('telltale.apps.store') }}" class="grid gap-3">
                @csrf
                <label for="app-name" class="text-sm font-semibold text-clay-ink">App name</label>
                <input id="app-name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="off" class="min-h-12 w-full rounded-2xl border border-clay-edge bg-clay-slip px-4 py-3 text-base text-clay-ink shadow-clay-inset focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-clay-iron">
                @error('name')
                    <p class="text-sm text-red-700">{{ $message }}</p>
                @enderror
                <button type="submit" class="bfc-button w-fit">Create app</button>
            </form>
        </section>

        <section data-testid="app-list" class="bfc-panel grid gap-3">
            <h2 class="font-display text-2xl font-normal text-clay-ink">Select an app</h2>
            @if ($apps === [])
                <p class="text-sm leading-6 text-clay-soft">No apps yet. Create the first app above.</p>
            @else
                <nav aria-label="Telltale apps" class="grid gap-2">
                    @foreach ($apps as $app)
                        <a href="{{ route('telltale.apps.show', $app['id']) }}"
                           class="rounded-xl border px-4 py-3 text-sm transition {{ $selected?->id === $app['id'] ? 'border-clay-iron bg-clay-slip text-clay-ink' : 'border-clay-edge bg-clay-surface text-clay-soft hover:border-clay-dot' }}">
                            <strong class="block text-clay-ink">{{ $app['name'] }}</strong>
                            <span>{{ $app['last_seen_at'] === null ? 'No ingest yet' : 'Last ingest '.$app['last_seen_at'] }}</span>
                        </a>
                    @endforeach
                </nav>
            @endif
        </section>
    </aside>

    <section class="grid content-start gap-6">
        <nav data-testid="product-navigation" class="bfc-nav">
            <a href="{{ route('bfc.dashboard') }}" aria-current="page">Apps</a>
            <a href="{{ route('telltale.setup') }}">Setup</a>
            <a href="{{ route('bfc.ui.home') }}">Account settings</a>
        </nav>

        @if ($ingestValue !== null)
            <section data-testid="credential-reveal" class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-950 shadow-sm">
                <p class="text-sm font-semibold uppercase tracking-widest">{{ $credentialAction === 'created' ? 'App created' : 'Ingest value rotated' }}</p>
                <h2 class="mt-1 font-display text-2xl font-normal">Copy this value now</h2>
                <p class="mt-2 leading-6">This plaintext value appears in this response only. Refreshing or navigating away cannot recover it.</p>
                <code data-testid="ingest-value" class="mt-4 block overflow-x-auto rounded-xl bg-amber-950 p-4 font-mono text-sm text-amber-50">{{ $ingestValue }}</code>
            </section>
        @endif

        @if ($selected === null)
            <section data-testid="app-empty" class="bfc-panel grid gap-3">
                <h2 class="font-display text-3xl font-normal text-clay-ink">Create an app to begin</h2>
                <p class="max-w-2xl leading-7 text-clay-soft">Each app receives its own public, write-only ingest value. It is safe to ship in the NativePHP build, but it cannot read management data.</p>
            </section>
        @else
            <section data-testid="selected-app" class="bfc-panel grid gap-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-widest text-clay-muted">Selected app</p>
                        <h2 class="mt-1 font-display text-4xl font-normal tracking-tight text-clay-ink">{{ $selected->name }}</h2>
                    </div>
                    <form method="POST" action="{{ route('telltale.apps.rotate', $selected) }}">
                        @csrf
                        <button type="submit" class="bfc-button-soft">Rotate ingest value</button>
                    </form>
                </div>
                <p class="leading-7 text-clay-soft">Rotation immediately invalidates the previous ingest value. The replacement is shown once in the response above.</p>
            </section>

            <section data-testid="ingest-health" class="bfc-panel grid gap-5">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-widest text-clay-muted">Recent ingest health</p>
                    <h2 class="mt-1 font-display text-3xl font-normal text-clay-ink">Last 30 days</h2>
                </div>
                <dl class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl bg-clay-slip p-4">
                        <dt class="text-sm text-clay-muted">Rejections</dt>
                        <dd class="mt-1 text-2xl font-semibold text-clay-ink">{{ $health['rejections'] }}</dd>
                    </div>
                    <div class="rounded-xl bg-clay-slip p-4">
                        <dt class="text-sm text-clay-muted">Rate-limit hits</dt>
                        <dd class="mt-1 text-2xl font-semibold text-clay-ink">{{ $health['rate_limit_hits'] }}</dd>
                    </div>
                    <div class="rounded-xl bg-clay-slip p-4">
                        <dt class="text-sm text-clay-muted">Client-reported drops, all time</dt>
                        <dd class="mt-1 text-2xl font-semibold text-clay-ink">{{ $health['dropped_events_total'] }}</dd>
                    </div>
                </dl>

                <div>
                    <h3 class="font-semibold text-clay-ink">Recently seen client versions</h3>
                    @if ($health['newest_client_versions'] === [])
                        <p class="mt-2 text-sm leading-6 text-clay-soft">No client versions have reported during this window.</p>
                    @else
                        <ul class="mt-3 grid gap-2">
                            @foreach ($health['newest_client_versions'] as $version)
                                <li class="flex flex-wrap justify-between gap-2 rounded-xl border border-clay-edge px-4 py-3 text-sm">
                                    <strong class="text-clay-ink">{{ $version['version'] }}</strong>
                                    <span class="text-clay-muted">{{ $version['last_seen_at'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        @endif
    </section>
</div>
</x-bfc-layout>
