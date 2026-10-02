{{--
    The unsubscribe page.

    Deliberately not the application shell. This is the only page a recipient who
    has never used the product will see, and they arrive from a mail client, on a
    phone, following a link from somebody they do not know. A signed-in
    application's navigation, account menu and breadcrumb trail would be a page
    full of links that either do nothing or go somewhere they have no account for.

    Deliberately no address. The link is opaque and this page never echoes back
    which contact it acts on. A confirmation is a disclosure, and this URL has
    already travelled through web servers, proxies, mail scanners and browser
    history to get here.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Unsubscribe</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col justify-center bg-slate-50 px-4 py-12">
    <main class="mx-auto w-full max-w-lg">
        <div class="rounded-lg border border-slate-200 bg-white px-6 py-8 shadow-sm">
            @if (($confirmed ?? false))
                <h1 class="text-xl font-semibold text-slate-900">You will not be contacted again</h1>

                <p class="mt-3 text-sm leading-relaxed text-slate-600">
                    This address has been removed from every campaign from this sender.
                    It stays removed even if the sender imports their list again.
                </p>

                <p class="mt-3 text-sm leading-relaxed text-slate-600">
                    You can close this page. If you did not expect to receive this, or if you
                    think this was a mistake, you can contact the sender directly &mdash; their
                    address is in the message itself.
                </p>
            @elseif ($alreadyUnsubscribed)
                <h1 class="text-xl font-semibold text-slate-900">Already unsubscribed</h1>

                <p class="mt-3 text-sm leading-relaxed text-slate-600">
                    This address was already removed from this sender's campaigns, so nothing
                    has changed and no further action is needed.
                </p>
            @elseif ($resolvable)
                <h1 class="text-xl font-semibold text-slate-900">Stop receiving email from this sender?</h1>

                <p class="mt-3 text-sm leading-relaxed text-slate-600">
                    Confirming will remove this address from every campaign this sender sends.
                    It is recorded immediately, and it stays removed even if the sender imports
                    their list again.
                </p>

                <form method="POST" action="{{ route('unsubscribe.store', $token) }}" class="mt-6">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2">
                        Unsubscribe
                    </button>
                </form>
            @else
                {{-- Unresolvable and already-unsubscribed both land here, and
                     both are reported the same way on purpose. A distinct error
                     for a bad link would tell a recipient their link is broken —
                     which is often true after a mail forward — and would tell
                     anyone probing tokens which of them are real. --}}
                <h1 class="text-xl font-semibold text-slate-900">This link is not active</h1>

                <p class="mt-3 text-sm leading-relaxed text-slate-600">
                    If you still want to stop receiving email, contact the sender directly
                    using the address in their message and ask them to remove you. They are
                    required to act on that request.
                </p>
            @endif
        </div>
    </main>
</body>
</html>