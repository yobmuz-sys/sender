<x-layout>
    <x-slot:title>Sign in</x-slot:title>

    <div class="mx-auto max-w-md rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Sign in</h1>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <x-input-label for="email" class="mb-1">Email</x-input-label>
                <x-text-input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" />
            </div>

            <div>
                <x-input-label for="password" class="mb-1">Password</x-input-label>
                <x-text-input id="password" name="password" type="password" required autocomplete="current-password" />
                <x-input-error :messages="$errors->get('password')" />
            </div>

            <div class="flex items-center gap-2">
                <input id="remember" name="remember" type="checkbox" value="1" class="rounded border-slate-300" />
                <x-input-label for="remember" class="text-sm font-normal text-slate-600">Remember me</x-input-label>
            </div>

            <div class="flex items-center justify-between">
                <a href="{{ route('password.request') }}" class="text-sm text-slate-600 underline">Forgot password?</a>
                <x-primary-button>Log in</x-primary-button>
            </div>
        </form>
    </div>
</x-layout>