<x-layout>
    <x-slot:title>Register</x-slot:title>

    <div class="mx-auto max-w-md rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Create an account</h1>

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <x-input-label for="name" class="mb-1">Name</x-input-label>
                <x-text-input id="name" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" />
                <x-input-error :messages="$errors->get('name')" />
            </div>

            <div>
                <x-input-label for="email" class="mb-1">Email</x-input-label>
                <x-text-input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" />
            </div>

            <div>
                <x-input-label for="password" class="mb-1">Password</x-input-label>
                <x-text-input id="password" name="password" type="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" />
            </div>

            <div>
                <x-input-label for="password_confirmation" class="mb-1">Confirm password</x-input-label>
                <x-text-input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password_confirmation')" />
            </div>

            <div class="flex items-center justify-between">
                <a href="{{ route('login') }}" class="text-sm text-slate-600 underline">Already registered?</a>
                <x-primary-button>Register</x-primary-button>
            </div>
        </form>
    </div>
</x-layout>