<nav class="mb-5 flex flex-wrap gap-1.5" aria-label="{{ __('Platform admin') }}">
    @foreach (['admin.index' => 'Companies', 'admin.plans' => 'Plans and prices', 'admin.payments' => 'Payments', 'admin.email' => 'Email', 'admin.integrations' => 'Integrations', 'sso.settings' => 'Single sign-on', 'admin.website' => 'Website', 'admin.texts' => 'Website text', 'admin.seo' => 'Search and analytics', 'admin.admins' => 'Admins', 'agents.admin.edit' => 'AI agents', 'price-watch.admin.edit' => 'Material prices'] as $route => $label)
        <a href="{{ route($route) }}" class="btn {{ request()->routeIs($route) ? 'btn-ink' : 'btn-ghost' }}">{{ __($label) }}</a>
    @endforeach
</nav>
