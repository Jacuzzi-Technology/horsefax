<nav class="flex flex-wrap gap-2 mb-8 font-sans text-[12px] font-semibold tracking-[0.12em] uppercase">
    <a href="{{ route('admin.queue') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.queue', 'admin.review') ? 'bg-gold/15' : '' }}">Review queue</a>
    <a href="{{ route('admin.horses') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.horses', 'admin.horse') ? 'bg-gold/15' : '' }}">Horses</a>
    <a href="{{ route('admin.show-records') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.show-records') ? 'bg-gold/15' : '' }}">Show results index</a>
    <a href="{{ route('admin.offspring') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.offspring') ? 'bg-gold/15' : '' }}">Offspring earnings</a>
    <a href="{{ route('admin.sale-lots') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.sale-lots') ? 'bg-gold/15' : '' }}">Sale history</a>
    <a href="{{ route('admin.names') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.names') ? 'bg-gold/15' : '' }}">Duplicate names</a>
    <a href="{{ route('admin.qr') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.qr') ? 'bg-gold/15' : '' }}">QR codes</a>
    <a href="{{ route('admin.members') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.members', 'admin.member') ? 'bg-gold/15' : '' }}">Members</a>
    <a href="{{ route('admin.messages') }}" class="btn-ghost btn-small {{ request()->routeIs('admin.messages') ? 'bg-gold/15' : '' }}">Messages</a>
</nav>
