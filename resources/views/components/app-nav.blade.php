@props(['navigation', 'url' => null, 'pendingRoutes' => []])

@php
    /*
     * The navigation, drawn once and used twice: in the desktop sidebar and in the
     * mobile panel. Duplicating it by hand would guarantee the two drift apart, and
     * a menu that differs by screen width is a menu somebody cannot trust.
     *
     * The entries and the decision about who may see them both arrive already
     * resolved from NavigationBuilder. This component draws; it never decides.
     */
    $url ??= request()->path();
    $pending = array_flip($pendingRoutes);

    /*
     * Icons are a scanning aid and nothing more: every one of them is decorative,
     * and each label says the same thing in words.
     */
    $icons = [
        'grid' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
        'download' => '<path d="M12 3.5v10m0 0 4-4m-4 4-4-4"/><path d="M4.5 16.5v1.75a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25V16.5"/>',
        'server' => '<rect x="3.5" y="4" width="17" height="6" rx="2"/><rect x="3.5" y="14" width="17" height="6" rx="2"/><path d="M7 7h.01M7 17h.01"/>',
        'gauge' => '<path d="M4.5 18a8.5 8.5 0 1 1 15 0"/><path d="m12 14.5 3.5-3.5"/><circle cx="12" cy="18" r="1.25"/>',
        'file' => '<path d="M13.5 3.5H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9l-5.5-5.5Z"/><path d="M13.5 3.5V9H19"/>',
        'list' => '<path d="M8.5 7h11M8.5 12h11M8.5 17h11"/><path d="M4.25 7h.01M4.25 12h.01M4.25 17h.01"/>',
        'send' => '<path d="M20.5 3.5 3.5 10.5l6 2.5 2.5 6 8.5-15.5Z"/><path d="m9.5 13 3.5-3.5"/>',
        'ban' => '<circle cx="12" cy="12" r="8.5"/><path d="m6 6 12 12"/>',
        'chart' => '<path d="M4 20V10m5 10V4m5 16v-7m5 7V8"/>',
        'users' => '<circle cx="9.5" cy="8.5" r="3.5"/><path d="M3.5 19.5a6 6 0 0 1 12 0"/><path d="M16 5.5a3.5 3.5 0 0 1 0 6.5M17.5 19.5a6 6 0 0 0-2-4.5"/>',
        'shield' => '<path d="M12 3.25 5 6v5.5c0 4 2.9 7.4 7 9.25 4.1-1.85 7-5.25 7-9.25V6l-7-2.75Z"/><path d="M9.5 9.5h5v5h-5z"/>',
        'card' => '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="M3 10h18"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'history' => '<path d="M3.5 12a8.5 8.5 0 1 0 2.5-6"/><path d="M3.5 4.5V9H8"/><path d="M12 7.5V12l3 1.5"/>',
        'cog' => '<circle cx="12" cy="12" r="3"/><path d="M12 3.5v2M12 18.5v2M20.5 12h-2M5.5 12h-2M18 6l-1.5 1.5M7.5 16.5 6 18M18 18l-1.5-1.5M7.5 7.5 6 6"/>',
        'code' => '<path d="m9 8-5 4 5 4M15 8l5 4-5 4"/>',
        'check' => '<circle cx="12" cy="12" r="8.5"/><path d="m8.75 12.25 2.25 2.25 4.25-4.5"/>',
        'toggle' => '<rect x="3" y="7" width="18" height="10" rx="5"/><circle cx="16" cy="12" r="2.25"/>',
        'user' => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 19.5a7 7 0 0 1 14 0"/>',
        'lock' => '<rect x="4.5" y="10.5" width="15" height="9.5" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
    ];

    $iconNames = [
        'dashboard' => 'grid',
        'extractor' => 'download',
        'deliverability' => 'gauge',
        'smtp' => 'server',
        'files' => 'file',
        'templates' => 'file',
        'lists' => 'list',
        'campaigns' => 'send',
        'suppression' => 'ban',
        'analytics' => 'chart',
        'audience' => 'users',
        'users' => 'users',
        'roles' => 'shield',
        'features' => 'toggle',
        'plans' => 'card',
        'billing' => 'card',
        'jobs' => 'clock',
        'runs' => 'history',
        'validation' => 'check',
        'system' => 'cog',
        'api' => 'code',
        'profile' => 'user',
        'security' => 'lock',
    ];

    $iconFor = static function (string $route) use ($iconNames, $icons): ?string {
        foreach ($iconNames as $needle => $name) {
            if (str_contains($route, $needle)) {
                return '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
                    .'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
                    .$icons[$name].'</svg>';
            }
        }

        return null;
    };

    $sections = [
        ['label' => 'Workspace', 'items' => $navigation['product'] ?? [], 'badges' => true],
        ['label' => 'Administration', 'items' => $navigation['admin'] ?? [], 'badges' => false],
        ['label' => 'Account', 'items' => $navigation['account'] ?? [], 'badges' => false],
    ];
@endphp

<nav {{ $attributes->class('space-y-6') }} aria-label="Main">
    @foreach ($sections as $section)
        @if ($section['items'] !== [])
            <div>
                <h2 class="px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                    {{ $section['label'] }}
                </h2>

                <ul class="mt-2 space-y-1">
                    @foreach ($section['items'] as $item)
                        <li>
                            @if ($item->children === [])
                                <x-nav-link
                                    :item="$item"
                                    :url="$url"
                                    :icon="$iconFor($item->route)"
                                    :pending="$section['badges'] && isset($pending[$item->route])"
                                />
                            @else
                                @php
                                    // The section opens itself when one of its pages is the page
                                    // being viewed, so a nested page is never hidden behind a
                                    // closed heading the visitor has no reason to open.
                                    $sectionActive = collect($item->children)->contains(
                                        static fn ($child): bool => $child->isActive($url, route($child->route)),
                                    ) || $item->isActive($url, route($item->route));
                                @endphp

                                <details @if ($sectionActive) open @endif class="group/section">
                                    <summary @class([
                                        'flex min-h-11 cursor-pointer list-none items-center gap-3 rounded-lg px-3 text-sm font-medium transition',
                                        'text-indigo-900' => $sectionActive,
                                        'text-slate-700 hover:bg-slate-100 hover:text-slate-900' => ! $sectionActive,
                                    ])>
                                        <span @class([
                                            'shrink-0',
                                            'text-indigo-700' => $sectionActive,
                                            'text-slate-400' => ! $sectionActive,
                                        ]) aria-hidden="true">
                                            {!! $iconFor($item->route) !!}
                                        </span>

                                        <span class="min-w-0 flex-1 truncate">{{ $item->label }}</span>

                                        <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open/section:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="m9 5 7 7-7 7"/>
                                        </svg>
                                    </summary>

                                    <ul class="mt-1 space-y-1 border-l border-slate-200 pl-2">
                                        @foreach ($item->children as $child)
                                            <li>
                                                <x-nav-link
                                                    :item="$child"
                                                    :url="$url"
                                                    :icon="$iconFor($child->route)"
                                                    :pending="$section['badges'] && isset($pending[$child->route])"
                                                    nested
                                                />
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endforeach
</nav>