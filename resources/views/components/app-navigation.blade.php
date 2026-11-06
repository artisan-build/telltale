<nav aria-label="Telltale apps"
     class="bfc-sidebar-links"
     data-testid="app-list"
     x-data="{ more: false }">
    @foreach ($apps as $app)
        <a href="{{ route('telltale.apps.show', $app) }}"
           class="bfc-shell-link"
           @if ($currentAppId === $app->id) aria-current="page" @endif
           @if ($loop->index >= $visible) x-show="more" x-cloak @endif
           data-testid="app-list-item">
            <span class="truncate">{{ $app->name }}</span>
        </a>
    @endforeach

    @if ($apps->count() > $visible)
        <button type="button"
                class="bfc-shell-link text-clay-muted"
                x-on:click="more = ! more"
                x-text="more ? @js(__('Show fewer')) : @js(trans_choice('Show :count more|Show :count more', $apps->count() - $visible))"
                data-testid="app-list-more">
            {{ trans_choice('Show :count more|Show :count more', $apps->count() - $visible) }}
        </button>
    @endif
</nav>
