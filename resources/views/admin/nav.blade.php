<nav class="flex flex-wrap gap-2 mb-8 font-sans text-[12px] font-semibold tracking-[0.12em] uppercase">
    <a href="{{ route('admin.queue') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.queue', 'admin.review') ? 'bg-gold/15' : '' }}">Review queue</a>
    <a href="{{ route('admin.horses') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.horses', 'admin.horse') ? 'bg-gold/15' : '' }}">Horses</a>
    <a href="{{ route('admin.messages') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.messages') ? 'bg-gold/15' : '' }}">Messages</a>
</nav>
