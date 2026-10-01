<x-layout>
    <x-slot:title>Profile</x-slot:title>

    <x-page-header
        title="Profile"
        description="Your name and email address. Changing your address requires confirming it again."
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card>
                <form method="POST" action="{{ route('account.profile.update') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                      :value="old('name', $user->name)" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="email" value="Email address" />
                        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                                      :value="old('email', $user->email)" required />
                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                    </div>

                    <div class="flex items-center gap-3">
                        <x-button type="submit">Save changes</x-button>
                        <a href="{{ route('dashboard') }}" class="text-sm text-slate-600 hover:text-slate-900">Cancel</a>
                    </div>
                </form>
            </x-card>
        </div>

        <div>
            <x-card title="Account">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Role</dt>
                        <dd class="mt-0.5 text-slate-800">{{ $user->role->label() }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Email confirmation</dt>
                        <dd class="mt-1">
                            @if ($user->hasVerifiedEmail())
                                <x-status-badge status="active" label="Confirmed" />
                            @else
                                <x-status-badge status="pending" label="Not confirmed" />
                                <form method="POST" action="{{ route('verification.send') }}" class="mt-2">
                                    @csrf
                                    <button type="submit" class="text-xs font-medium text-slate-700 underline hover:text-slate-900">
                                        Send a new link
                                    </button>
                                </form>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Joined</dt>
                        <dd class="mt-0.5 text-slate-800">{{ $user->created_at?->format('j M Y') }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>
    </div>
</x-layout>
