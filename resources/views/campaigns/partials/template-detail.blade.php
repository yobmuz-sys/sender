@use('App\Domain\Templates\MessageRenderer')
@use('App\Domain\Templates\TemplateStatus')

@php
    /**
     * What the chosen template actually holds.
     *
     * Rendered from the template's own API — {@see Template::effectiveStatus()},
     * {@see Template::blockers()} and the renderer's used/unknown tokens — rather
     * than from rules written a second time here. The campaign preflight asks the
     * same questions, so a panel that disagreed with it would be worse than no panel:
     * the customer would be shown a template that looks sendable and then be
     * refused at the start button with no visible reason.
     *
     * The frozen-copy note is not decoration. A campaign copies this content at
     * launch, so everything here describes what the campaign will send *if it is
     * started before the template changes*.
     */
    $renderer = new MessageRenderer;
    $status = $template->effectiveStatus($renderer);
    $blockers = $template->blockers($renderer);
    $used = $renderer->tokensUsed(implode(' ', [
        (string) $template->subject,
        (string) $template->html_body,
        (string) $template->text_body,
    ]));
    $unknown = $renderer->unknownTokens(implode(' ', [
        (string) $template->subject,
        (string) $template->html_body,
        (string) $template->text_body,
    ]));
@endphp

<div class="mt-4 rounded-md bg-slate-50 px-4 py-3 ring-1 ring-inset ring-slate-200">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <p class="text-sm font-semibold text-slate-900">{{ $template->name }}</p>
        <p class="text-xs text-slate-600">
            Version {{ $template->version }}
            <span class="mx-1 text-slate-400">/</span>
            <span class="font-medium {{ $status === TemplateStatus::Ready ? 'text-emerald-700' : 'text-amber-800' }}">
                {{ $status->label() }}
            </span>
        </p>
    </div>

    <p class="mt-2 text-sm text-slate-700">
        <span class="font-medium text-slate-600">Subject:</span>
        <span class="font-mono text-xs">{{ $template->subject }}</span>
    </p>

    <div class="mt-2">
        <p class="text-xs font-medium text-slate-600">Placeholders</p>

        @if ($used === [] && $unknown === [])
            <p class="mt-1 text-xs text-slate-500">None. Every recipient sees the same words.</p>
        @else
            <ul class="mt-1 flex flex-wrap gap-1.5">
                @foreach ($used as $token)
                    <li class="rounded bg-slate-200 px-1.5 py-0.5 font-mono text-xs text-slate-800" title="{{ $token->description() }}">
                        {{ $token->placeholder() }}
                    </li>
                @endforeach

                @foreach ($unknown as $token)
                    <li class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs text-amber-900 ring-1 ring-inset ring-amber-300"
                        title="This platform does not fill this one in, so recipients would see it as written.">
                        {{ '{'.'{'.$token.'}'.'}' }}
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($blockers !== [])
        <ul class="mt-3 space-y-1 border-t border-slate-200 pt-3">
            @foreach ($blockers as $blocker)
                <li class="text-xs text-amber-900">{{ $blocker }}</li>
            @endforeach
        </ul>
    @endif
</div>