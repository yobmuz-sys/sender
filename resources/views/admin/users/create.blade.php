<x-layout>
    <x-slot:title>Create user</x-slot:title>

    <x-page-header
        title="Create a user"
        description="Roles assigned here are the platform's administrative roles. Product entitlements are resolved separately and are not set here."
    />

    <div class="max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-5">
                @csrf

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                  :value="old('name')" required autofocus />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="email" value="Email address" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                                  :value="old('email')" required />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                    <p class="mt-1 text-xs text-slate-500">
                        A confirmation email is sent to this address. Until it is confirmed the account
                        can sign in but cannot reach the administration area.
                    </p>
                </div>

                <div>
                    <x-input-label for="role" value="Role" />
                    <select id="role" name="role" class="mt-1 block rounded-md border-slate-300 text-sm shadow-sm" required>
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" @selected(old('role') === $role->value)>
                                {{ $role->label() }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('role')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="password" value="Password" />
                    <x-text-input id="password" name="password" type="password" class="mt-1 block w-full"
                                  required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="password_confirmation" value="Confirm password" />
                    <x-text-input id="password_confirmation" name="password_confirmation" type="password"
                                  class="mt-1 block w-full" required autocomplete="new-password" />
                </div>

                <div class="flex items-center gap-3">
                    <x-button type="submit">Create user</x-button>
                    <a href="{{ route('admin.users.index') }}" class="text-sm text-slate-600 hover:text-slate-900">Cancel</a>
                </div>
            </form>
        </x-card>
    </div>
</x-layout>
