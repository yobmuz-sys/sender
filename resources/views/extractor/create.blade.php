<x-layout>
    <x-slot:title>New extraction</x-slot:title>

    <x-page-header
        title="New extraction"
        description="Paste text containing email addresses. Addresses are extracted, normalised and de-duplicated."
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-2">
            <form method="POST" action="{{ route('extractor.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="content" class="block text-sm font-medium text-slate-800">Content</label>
                    <p class="mt-1 text-xs text-slate-500">
                        Plain text or CSV pasted as-is. Up to 20,000 characters.
                    </p>
                    <textarea id="content" name="content" rows="14" required
                              class="mt-2 block w-full rounded-md border border-slate-300 font-mono text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">{{ old('content') }}</textarea>
                    @error('content')
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

            <x-alert variant="info" title="Not available yet">
                URL extraction and file upload are not implemented. This form accepts pasted
                content only, and rejects anything else rather than storing a request the
                platform cannot act on.
            </x-alert>
        </div>
    </div>
</x-layout>
