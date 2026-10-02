<x-layout>
    <x-slot:title>New task</x-slot:title>

    <x-page-header
        title="New task"
        description="Paste a list of addresses or point us at a page that contains them. We find the addresses, then check which of them can actually receive email."
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-2">
            <form method="POST" action="{{ route('extractor.store') }}" class="space-y-5"
                  x-data="{ source: @js(old('source_type', 'paste')) }">
                @csrf

                <div>
                    <label for="name" class="block text-sm font-medium text-slate-800">Name this task</label>
                    <p class="mt-1 text-xs text-slate-500">
                        Optional. Useful when you have more than one — it appears on the task badge.
                    </p>
                    <input id="name" name="name" type="text" maxlength="120" value="{{ old('name') }}"
                           placeholder="Leads from the October newsletter"
                           class="mt-2 block w-full rounded-md border border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <fieldset>
                    <legend class="block text-sm font-medium text-slate-800">Where are the addresses?</legend>
                    <div class="mt-2 space-y-2">
                        <label class="flex items-start gap-2 text-sm text-slate-700">
                            <input type="radio" name="source_type" value="paste" x-model="source" class="mt-1">
                            <span>
                                <span class="font-medium text-slate-800">Paste a list</span>
                                <span class="block text-xs text-slate-500">
                                    Plain text or CSV pasted as-is.
                                </span>
                            </span>
                        </label>

                        <label class="flex items-start gap-2 text-sm text-slate-700">
                            <input type="radio" name="source_type" value="url" x-model="source" class="mt-1">
                            <span>
                                <span class="font-medium text-slate-800">One web page</span>
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

                <x-button type="submit">Start task</x-button>
            </form>
        </x-card>

        <div class="space-y-6">
            <x-card title="What happens next">
                <ul class="space-y-1.5 text-sm text-slate-700">
                    <li>We find every address in your input</li>
                    <li>Duplicates within the input are removed</li>
                    <li>Each address is checked with the server that would receive its mail</li>
                    <li>You get a report saying which ones can receive email and which cannot</li>
                </ul>
                <p class="mt-3 text-xs text-slate-500">
                    This runs in the background. You can leave the page and come back.
                </p>
            </x-card>

            <x-card title="What &ldquo;can receive email&rdquo; actually means">
                <p class="text-sm text-slate-700">
                    We ask the receiving server whether it will accept the address. When it does,
                    we report it as &ldquo;likely active&rdquo;. That is not a promise of delivery:
                    the message can still be filtered after acceptance.
                </p>
                <p class="mt-2 text-sm text-slate-700">
                    When a server will not tell us — which most large providers now refuse to do —
                    we report the address as &ldquo;unknown&rdquo; rather than guessing. We never
                    mark an address inactive because a server would not answer.
                </p>
            </x-card>

            <x-card title="About web page extraction">
                <ul class="space-y-1.5 text-sm text-slate-700">
                    <li>One page per task</li>
                    <li>Reads HTML and plain text only</li>
                    <li>Refuses private and reserved destinations</li>
                    <li>Stops after a bounded number of redirects</li>
                    <li>Abandons a response larger than 2 MiB</li>
                </ul>
            </x-card>

            <x-alert variant="info" title="Not available yet">
                File uploads are not available yet, and neither are multiple pages per task,
                XLSX, DOCX, PDF or XML documents. This form accepts a pasted list or one page,
                and rejects anything else rather than storing a request the platform cannot act on.
            </x-alert>
        </div>
    </div>
</x-layout>