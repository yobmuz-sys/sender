<x-layout>
    <x-slot:title>SMTP</x-slot:title>

    <x-page-header
        title="SMTP delivery"
        description="Whether this installation can actually send mail, and exactly what that was established from."
    />

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat label="SMTP capability" :value="$capability->value" />
        <x-stat label="Configured mailer" :value="$mailer" />
        <x-stat label="Last verified"
                :value="$verification === null ? 'never' : $verification->status->value"
                :hint="$verification === null ? 'no verification recorded' : \Carbon\Carbon::createFromTimestamp($verification->verifiedAt)->diffForHumans()" />
    </div>

    <x-alert variant="info" title="What verification does and does not prove" class="mb-6">
        <p>
            Verification can prove that the mail server accepted a message from these credentials.
            It <strong>cannot</strong> prove that a recipient received it — nothing inside the
            application can observe a mailbox. Read a successful verification as "we can submit mail",
            not "mail arrives".
        </p>
        <p class="mt-2">
            Opening this page does not contact the mail server. Verification happens only when you ask
            for it below.
        </p>
    </x-alert>

    @if ($failure)
        <x-alert variant="danger" title="Verification failed" class="mb-6">{{ $errors->first('smtp') }}</x-alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Verification evidence">
                @if ($verification === null)
                    <x-empty-state
                        title="Never verified"
                        description="The capability is unknown until somebody verifies it. Credentials being present in the environment is not evidence that they work."
                    />
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($verification->stages as $stage)
                            <li class="flex items-start justify-between gap-3 py-2">
                                <div>
                                    <p class="text-sm font-medium text-slate-800">{{ $stage['name'] }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $stage['detail'] }}</p>
                                </div>
                                <x-status-badge :status="$stage['passed'] ? 'ok' : 'failed'"
                                               :label="$stage['passed'] ? 'proved' : 'failed'" />
                            </li>
                        @endforeach
                    </ul>

                    <dl class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-600">Result</dt>
                            <dd class="text-slate-800">{{ $verification->summary }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-600">Verified at</dt>
                            <dd class="text-slate-800">
                                {{ \Carbon\Carbon::createFromTimestamp($verification->verifiedAt)->format('j M Y, H:i:s') }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-600">Treated as current for</dt>
                            <dd class="text-slate-800">{{ $freshAfter }}s</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-600">Proves</dt>
                            <dd class="text-slate-800">{{ $verification->toArray()['proves'] }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-600">Does not prove</dt>
                            <dd class="text-rose-700">{{ $verification->toArray()['does_not_prove'] }}</dd>
                        </div>
                    </dl>
                @endif
            </x-card>
        </div>

        <div>
            <x-card title="Run a verification">
                @unless ($canManage)
                    <p class="text-sm text-slate-600">
                        Verification is a mutating operation and needs the
                        <code class="font-mono text-xs">system.manage</code> permission.
                    </p>
                @else
                    <form method="POST" action="{{ route('admin.smtp.verify') }}" class="space-y-3">
                        @csrf
                        <p class="text-xs text-slate-500">
                            Proves the connection only. Credentials are not exercised, so the result is
                            reported as degraded rather than ready.
                        </p>
                        <x-button type="submit" variant="secondary" class="w-full">Verify connection</x-button>
                    </form>

                    <form method="POST" action="{{ route('admin.smtp.send') }}" class="mt-5 space-y-3 border-t border-slate-100 pt-5">
                        @csrf
                        <div>
                            <x-input-label for="smtp-test-address" value="Send a test message to" />
                            <x-text-input id="smtp-test-address" name="email" type="email"
                                          class="mt-1 block w-full" required placeholder="you@example.com" />
                            <x-input-error :messages="$errors->get('email')" class="mt-1" />
                        </div>
                        <x-button type="submit" class="w-full">Send verification message</x-button>
                    </form>

                    @if ($verification !== null)
                        <form method="POST" action="{{ route('admin.smtp.forget') }}"
                              class="mt-5 border-t border-slate-100 pt-5"
                              onsubmit="return confirm('Discard the recorded verification?')">
                            @csrf
                            @method('DELETE')
                            <x-button type="submit" variant="ghost" class="w-full">Forget verification result</x-button>
                        </form>
                    @endif
                @endunless
            </x-card>

            <x-card title="Credentials" class="mt-6">
                <p class="text-sm text-slate-600">
                    Credentials belong in the environment (<code class="font-mono text-xs">.env</code>) and
                    are never displayed or stored by this page. There is deliberately no web form for
                    them: a second place holding a secret is a second thing to leak.
                </p>
            </x-card>
        </div>
    </div>
</x-layout>
