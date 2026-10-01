<x-layout>
    <x-slot:title>Security</x-slot:title>

    <x-page-header
        title="Security"
        description="Change your password. Other sessions on this account are ended when you do."
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Change password">
                <form method="POST" action="{{ route('account.password.update') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="current_password" value="Current password" />
                        <x-text-input id="current_password" name="current_password" type="password"
                                      class="mt-1 block w-full" required autocomplete="current-password" />
                        <x-input-error :messages="$errors->get('current_password')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="password" value="New password" />
                        <x-text-input id="password" name="password" type="password"
                                      class="mt-1 block w-full" required autocomplete="new-password" />
                        <p class="mt-1 text-xs text-slate-500">
                            A strong password you do not use anywhere else. The exact requirement
                            is enforced on submit.
                        </p>
                        <x-input-error :messages="$errors->get('password')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="password_confirmation" value="Confirm new password" />
                        <x-text-input id="password_confirmation" name="password_confirmation" type="password"
                                      class="mt-1 block w-full" required autocomplete="new-password" />
                    </div>

                    <x-button type="submit">Change password</x-button>
                </form>
            </x-card>
        </div>

        <div>
            <x-card title="Signed in as">
                <p class="text-sm text-slate-700">{{ auth()->user()->email }}</p>
                <p class="mt-3 text-sm text-slate-600">
                    Changing your password ends every other session on this account.
                </p>
            </x-card>
        </div>
    </div>
</x-layout>
