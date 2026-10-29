<nav data-testid="product-navigation" aria-label="Telltale" class="bfc-sidebar-links">
    <a href="{{ route('bfc.dashboard') }}" class="bfc-shell-link" @if ($active === 'apps') aria-current="page" @endif>Apps</a>
    <a href="{{ route('telltale.setup') }}" class="bfc-shell-link" @if ($active === 'setup') aria-current="page" @endif>Setup</a>
    <a href="{{ route('bfc.ui.home') }}" class="bfc-shell-link">Account settings</a>
</nav>
