@php
    $editing = $template !== null;
@endphp

<x-layout>
    <x-slot:title>{{ $editing ? $template->name : 'New template' }}</x-slot:title>

    <x-page-header
        :title="$editing ? $template->name : 'New template'"
        :description="$editing
            ? 'Editing version '.$template->version.'. Saving different content starts a new version, and campaigns that already copied this template keep the content they were sent.'
            : 'Write the message once and use it in campaigns. Nothing sends from a template on its own.'"
    >
        <x-slot:actions>
            <x-button :href="route('templates.index')">All templates</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="POST"
          action="{{ $editing ? route('templates.update', $template) : route('templates.store') }}">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <div class="space-y-6">
            <x-card title="What recipients see first">
                <div class="max-w-xl space-y-4">
                    <div>
                        <x-input-label for="name">Template name</x-input-label>
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                      :value="old('name', $template?->name)"
                                      placeholder="October update" required autofocus />
                        <x-input-error class="mt-1" :messages="$errors->get('name')" />
                        <p class="mt-1 text-xs text-slate-500">Only you see this. Recipients never see the template's name.</p>
                    </div>

                    <div>
                        <x-input-label for="subject">Subject line</x-input-label>
                        <x-text-input id="subject" name="subject" type="text" class="mt-1 block w-full"
                                      :value="old('subject', $template?->subject)"
                                      placeholder="What is in this month's update" required />
                        <x-input-error class="mt-1" :messages="$errors->get('subject')" />
                        <p class="mt-1 text-xs text-slate-500">
                            The one line a recipient reads before deciding to open. Placeholders work here.
                        </p>
                    </div>

                    <div>
                        <x-input-label for="preheader">Preheader</x-input-label>
                        <input id="preheader" name="preheader" type="text"
                               value="{{ old('preheader', $template?->preheader) }}"
                               class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500"
                               placeholder="Optional. A short line shown under the subject." />
                        <x-input-error class="mt-1" :messages="$errors->get('preheader')" />
                        <p class="mt-1 text-xs text-slate-500">Optional. Some mail apps show this instead of the first line of the message.</p>
                    </div>
                </div>
            </x-card>

            <x-card title="HTML message"
                    description="What a recipient sees in a mail app that renders HTML.">
                <div>
                    <label for="html_body" class="sr-only">HTML message</label>
                    <textarea id="html_body" name="html_body" rows="16" required
                              class="block w-full rounded-md border-slate-300 font-mono text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                              placeholder="<p>Hello @{{first_name}},</p>">{{ old('html_body', $template?->html_body) }}</textarea>
                    <x-input-error class="mt-1" :messages="$errors->get('html_body')" />
                    <p class="mt-1 text-xs text-slate-500">
                        Ordinary HTML, written by hand or pasted from your editor. Scripts and embedded
                        frames are removed before the preview is shown, and are not supported in email.
                    </p>
                </div>
            </x-card>

            <x-card title="Plain-text message"
                    description="What a recipient sees in a mail app that cannot show HTML, or has HTML turned off.">
                <div>
                    <label for="text_body" class="sr-only">Plain-text message</label>
                    <textarea id="text_body" name="text_body" rows="12" required
                              class="block w-full rounded-md border-slate-300 font-mono text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                              placeholder="Hello @{{first_name}},

…">{{ old('text_body', $template?->text_body) }}</textarea>
                    <x-input-error class="mt-1" :messages="$errors->get('text_body')" />
                    <p class="mt-1 text-xs text-slate-500">
                        Required, not optional. Some recipients can only read this version, and sending
                        HTML alone means they receive nothing.
                    </p>
                </div>
            </x-card>

            <x-card title="Personalisation"
                    description="These are the only placeholders the platform fills in. Anything else written like a placeholder is refused when you save.">
                <ul class="divide-y divide-slate-100">
                    @foreach ($tokens as $token)
                        <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-baseline sm:gap-4">
                            <code class="shrink-0 font-mono text-sm text-slate-900">{{ $token->placeholder() }}</code>

                            <span class="text-sm text-slate-700">{{ $token->label() }}</span>

                            <span class="text-xs text-slate-500 sm:ml-auto sm:text-right">
                                {{ $token->description() }}
                            </span>
                        </li>
                    @endforeach
                </ul>

                <p class="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-inset ring-amber-200">
                    Campaign messages must contain a working unsubscribe mechanism, and that link is
                    supplied per recipient by the platform. Add
                    <code class="font-mono">@{{unsubscribe_url}}</code>
                    where it belongs in the message; a campaign will not start without one.
                </p>
            </x-card>

            <div class="flex flex-wrap items-center gap-3">
                <x-primary-button>{{ $editing ? 'Save changes' : 'Create template' }}</x-primary-button>

                <a href="{{ $editing ? route('templates.show', $template) : route('templates.index') }}"
                   class="text-sm text-slate-600 hover:underline">Cancel</a>
            </div>
        </div>
    </form>

    @if ($editing)
        <x-card title="Delete this template" class="mt-6">
            <p class="text-sm text-slate-600">
                The template is removed. This deletes words you wrote and nothing else — no contact,
                consent record or suppression is attached to a template.
            </p>

            <form method="POST" action="{{ route('templates.destroy', $template) }}" class="mt-4">
                @csrf
                @method('DELETE')
                <x-button variant="secondary" type="submit">Delete template</x-button>
            </form>
        </x-card>
    @endif
</x-layout>