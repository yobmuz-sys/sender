<x-layout>
    <x-slot:title>Forgot password</x-slot:title>

    <div class="mx-auto max-w-md rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Forgot your password?</h1>

        <p class="mt-2 text-sm text-slate-600">
            Enter your email address and we will send a link to choose a new password.
        </p>

        <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <x-input-label for="email" class="mb-1">Email</x-input-label>
                <x-text-input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus />
                <x-input-error :messages="$errors->get('email')" />
            </div>

            <div class="flex items-center justify-between">
                <a href="{{ route('login') }}" class="text-sm text-slate-600 underline">Back to sign in</a>
                <x-primary-button>Email reset link</x-primary-button>
            </div>
        </form>
    </div>
</x-layout>