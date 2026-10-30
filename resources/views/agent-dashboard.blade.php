<x-telltale-layout title="Telltale">
<section data-testid="agent-dashboard" class="bfc-dashboard">
    <header>
        <h1 class="bfc-dashboard-title">Ask Telltale</h1>
        <p class="bfc-dashboard-lede">Your apps are reporting in. Telltale answers through your agent; copy a prompt into your agent chat to get started.</p>
    </header>

    @include('bfc::shell.agent-prompts', ['prompts' => $prompts])
</section>
</x-telltale-layout>
