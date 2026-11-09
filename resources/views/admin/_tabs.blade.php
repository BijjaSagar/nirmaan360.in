<nav class="mb-5 flex flex-wrap gap-1.5" aria-label="{{ __('Platform admin') }}">
    @foreach (['admin.index' => 'Companies', 'admin.plans' => 'Plans and prices', 'admin.payments' => 'Payments', 'admin.email' => 'Email', 'sso.settings' => 'Single sign-on', 'admin.website' => 'Website', 'admin.texts' => 'Website text', 'admin.admins' => 'Admins'] as $route => $label)
        <a href="{{ route($route) }}" class="btn {{ request()->routeIs($route) ? 'btn-ink' : 'btn-ghost' }}">{{ __($label) }}</a>
    @endforeach
</nav>
