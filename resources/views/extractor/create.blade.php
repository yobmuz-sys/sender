<x-layout>
    <x-slot:title>New extraction</x-slot:title>

    <x-page-header
        title="New extraction"
        description="Extract email addresses from pasted text, or from a single web address."
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-2">
            <form method="POST" action="{{ route('extractor.store') }}" class="space-y-5"
                  x-data="{ source: @js(old('source_type', 'paste')) }">
                @csrf

                <fieldset>
                    <legend class="block text-sm font-medium text-slate-800">Source</legend>
                    <div class="mt-2 space-y-2">
                        <label class="flex items-start gap-2 text-sm text-slate-700">
                            <input type="radio" name="source_type" value="paste" x-model="source" class="mt-1">
                            <span>
                                <span class="font-medium text-slate-800">Paste text</span>
                                <span class="block text-xs text-slate-500">
                                    Plain text or CSV pasted as-is.
                                </span>
                            </span>
                        </label>

                        <label class="flex items-start gap-2 text-sm text-slate-700">
                            <input type="radio" name="source_type" value="url" x-model="source" class="mt-1">
                            <span>
                                <span class="font-medium text-slate-800">Single URL</span>
                                <span class="block text-xs text-slate-500">
                                    One http or https address. The page is fetched, read as text,
                                    and searched for addresses.
                                </span>
                            </span>
                        </label>
                    </div>
                </fieldset>

                {{-- Both fields stay in the DOM so a browser validation message
                     still names the field the user needs to correct; Alpine only
                     decides which one is on screen. --}}
                <div x-show="source === 'paste'">
                    <label for="content" class="block text-sm font-medium text-slate-800">Content</label>
                    <p class="mt-1 text-xs text-slate-500">
                        Plain text or CSV pasted as-is. Up to 1 MiB.
                    </p>
                    <textarea id="content" name="content" rows="14"
                              class="mt-2 block w-full rounded-md border border-slate-300 font-mono text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">{{ old('content') }}</textarea>
                    @error('content')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div x-show="source === 'url'">
                    <label for="url" class="block text-sm font-medium text-slate-800">Web address</label>
                    <p class="mt-1 text-xs text-slate-500">
                        Exactly one address, beginning with http:// or https://.
                        Only ports 80 and 443 are fetched.
                    </p>
                    <input id="url" name="url" type="url" value="{{ old('url') }}"
                           placeholder="https://example.com/contact"
                           class="mt-2 block w-full rounded-md border border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
                    @error('url')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <x-button type="submit">Extract</x-button>
            </form>
        </x-card>

        <div class="space-y-6">
            <x-card title="What this does">
                <ul class="space-y-1.5 text-sm text-slate-700">
                    <li>Finds address-shaped text</li>
                    <li>Lower-cases and trims each address</li>
                    <li>Discards anything that is not a valid address</li>
                    <li>Removes duplicates within one extraction</li>
                </ul>
            </x-card>

            <x-card title="About URL extraction">
                <ul class="space-y-1.5 text-sm text-slate-700">
                    <li>Fetches one address per extraction</li>
                    <li>Reads HTML and plain text only</li>
                    <li>Refuses private and reserved destinations</li>
                    <li>Stops after a bounded number of redirects</li>
                    <li>Abandons a response larger than 2 MiB</li>
                </ul>
            </x-card>

            <x-alert variant="info" title="Not available yet">
                File uploads are not available yet, and neither are multiple URLs per extraction,
                XLSX, DOCX, PDF or XML documents. This form accepts pasted content or one URL,
                and rejects anything else rather than storing a request the platform cannot act on.
            </x-alert>
        </div>
    </div>
</x-layout>