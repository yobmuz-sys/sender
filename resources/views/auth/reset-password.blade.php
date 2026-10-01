<x-layout>
    <x-slot:title>Reset password</x-slot:title>

    <div class="mx-auto max-w-md rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Choose a new password</h1>

        <form method="POST" action="{{ route('password.store') }}" class="mt-6 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <x-input-label for="email" class="mb-1">Email</x-input-label>
                <x-text-input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" />
            </div>

            <div>
                <x-input-label for="password" class="mb-1">New password</x-input-label>
                <x-text-input id="password" name="password" type="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" />
            </div>

            <div>
                <x-input-label for="password_confirmation" class="mb-1">Confirm new password</x-input-label>
                <x-text-input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password_confirmation')" />
            </div>

            <div class="flex justify-end">
                <x-primary-button>Reset password</x-primary-button>
            </div>
        </form>
    </div>
</x-layout>