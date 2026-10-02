{{--
    The SMTP transport form, shared by the customer and administrative pages.

    One partial, because the two configure the same thing and must validate
    identically: a tenant able to save a configuration an operator could not
    would make the difference a policy that only exists in one of two templates.

    The secret is a password field that is never populated. Nothing here can
    render a stored credential, because nothing here ever receives one.
--}}
@props(['action', 'method' => 'POST', 'account' => null, 'providers', 'encryptions', 'authModes',
          'managementModes' => null, 'userId' => null, 'selectedMode' => null, 'readonly' => false])

@php
    $isEdit = $account !== null;
    $value = fn (string $field, $fallback = null) => old($field, $account?->{$field} ?? $fallback);
@endphp

<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <x-card title="Identity">
        <div class="space-y-5">
            <div>
                <x-input-label for="label" value="Label" />
                <x-text-input id="label" name="label" type="text" class="mt-1 block w-full"
                              :value="$value('label')" required :disabled="$readonly" />
                <p class="mt-1 text-xs text-slate-500">A name for your own reference. Only you and platform staff see it.</p>
                <x-input-error :messages="$errors->get('label')" class="mt-1" />
            </div>

            @if ($managementModes !== null)
                <div>
                    <x-input-label for="management_mode" value="Who may change this" />
                    <select id="management_mode" name="management_mode"
                            class="mt-1 block w-full rounded-md border-slate-300 shadow-sm">
                        @foreach ($managementModes as $mode)
                            <option value="{{ $mode->value }}"
                                @selected(($value('management_mode', $selectedMode?->value ?? 'admin_managed')) === $mode->value)>
                                {{ $mode->label() }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        Managed by the account owner means they can edit it. Managed by platform staff means they can read
                        it but cannot change it — use this when you supply the transport.
                    </p>
                    <x-input-error :messages="$errors->get('management_mode')" class="mt-1" />
                </div>

                @if (! $isEdit)
                    <div>
                        <x-input-label for="user_id" value="Assign to account" />
                        <x-text-input id="user_id" name="user_id" type="number" class="mt-1 block w-full"
                                      :value="old('user_id', $userId)" required />
                        <p class="mt-1 text-xs text-slate-500">The numeric user id this transport sends on behalf of.</p>
                        <x-input-error :messages="$errors->get('user_id')" class="mt-1" />
                    </div>
                @endif
            @endif
        </div>
    </x-card>

    <x-card title="Provider">
        <div class="space-y-5">
            <div>
                <x-input-label for="provider" value="Provider" />
                <select id="provider" name="provider" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm"
                        :disabled="$readonly">
                    @foreach ($providers as $provider)
                        <option value="{{ $provider->value }}"
                                @selected($value('provider', 'custom') === $provider->value)>
                            {{ $provider->label() }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">
                    A preset only fills in defaults and shows guidance. Every provider uses the same generic SMTP
                    connection below, and your stored host and port are what is actually used.
                </p>
                <x-input-error :messages="$errors->get('provider')" class="mt-1" />
            </div>

            <div class="rounded-md bg-slate-50 p-4 text-sm text-slate-700">
                <p class="font-medium text-slate-900">Setup guidance</p>
                <p class="mt-2 whitespace-pre-line">{{ $value('provider', 'custom') === 'gmail' || $value('provider', 'custom') === 'google_workspace'
                        ? \App\Domain\Mail\SmtpProvider::Gmail->guidance()
                        : (\App\Domain\Mail\SmtpProvider::CPanel->guidance()) }}</p>
            </div>
        </div>
    </x-card>

    <x-card title="Transport">
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-input-label for="host" value="SMTP host" />
                <x-text-input id="host" name="host" type="text" class="mt-1 block w-full"
                              :value="$value('host', 'smtp.gmail.com')" required :disabled="$readonly"
                              placeholder="smtp.gmail.com" />
                <p class="mt-1 text-xs text-slate-500">
                    Must resolve to a publicly routable address. Private, loopback and link-local destinations are
                    refused so this host cannot be used to reach services inside the network it runs on.
                </p>
                <x-input-error :messages="$errors->get('host')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="port" value="Port" />
                <x-text-input id="port" name="port" type="number" class="mt-1 block w-full"
                              :value="$value('port', 587)" required :disabled="$readonly" min="1" max="65535" />
                <x-input-error :messages="$errors->get('port')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="encryption" value="Encryption" />
                <select id="encryption" name="encryption" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm"
                        :disabled="$readonly">
                    @foreach ($encryptions as $encryption)
                        <option value="{{ $encryption->value }}"
                                @selected($value('encryption', 'starttls') === $encryption->value)>
                            {{ $encryption->label() }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">Sending is blocked without TLS. The unencrypted option exists to diagnose a relay.</p>
                <x-input-error :messages="$errors->get('encryption')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="auth_mode" value="Authentication" />
                <select id="auth_mode" name="auth_mode" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm"
                        :disabled="$readonly">
                    @foreach ($authModes as $mode)
                        <option value="{{ $mode->value }}"
                                @selected($value('auth_mode', 'password') === $mode->value)>
                            {{ $mode->label() }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('auth_mode')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="username" value="Username" />
                <x-text-input id="username" name="username" type="text" class="mt-1 block w-full"
                              :value="$value('username')" :disabled="$readonly" autocomplete="off" />
                <p class="mt-1 text-xs text-slate-500">
                    Usually your full email address. This is also the only address this transport may send as.
                </p>
                <x-input-error :messages="$errors->get('username')" class="mt-1" />
            </div>

            <div class="sm:col-span-2">
                <x-input-label for="secret" :value="$isEdit ? 'Password or app password' : 'Password or app password'" />
                <x-text-input id="secret" name="secret" type="password" class="mt-1 block w-full"
                              :disabled="$readonly" autocomplete="new-password" />
                <p class="mt-1 text-xs text-slate-500">
                    @if ($isEdit)
                        Leave blank to keep the stored password. It is encrypted at rest and is never shown again —
                        there is no way to read it back, including by platform staff.
                    @else
                        Stored encrypted with the application key. It is never displayed again after saving.
                    @endif
                    Changing the password invalidates any previous verification.
                </p>
                <x-input-error :messages="$errors->get('secret')" class="mt-1" />
            </div>
        </div>
    </x-card>

    <x-card title="Sender">
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <x-input-label for="from_address" value="From address" />
                <x-text-input id="from_address" name="from_address" type="email" class="mt-1 block w-full"
                              :value="$value('from_address')" required :disabled="$readonly" />
                <p class="mt-1 text-xs text-slate-500">
                    Must be the address the transport authenticates as. This platform will not send mail appearing to
                    come from an address it did not authenticate as.
                </p>
                <x-input-error :messages="$errors->get('from_address')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="from_name" value="From name" />
                <x-text-input id="from_name" name="from_name" type="text" class="mt-1 block w-full"
                              :value="$value('from_name')" :disabled="$readonly" />
                <x-input-error :messages="$errors->get('from_name')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="reply_to" value="Reply-To" />
                <x-text-input id="reply_to" name="reply_to" type="email" class="mt-1 block w-full"
                              :value="$value('reply_to')" :disabled="$readonly" />
                <p class="mt-1 text-xs text-slate-500">Optional. Where replies should be directed.</p>
                <x-input-error :messages="$errors->get('reply_to')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="dkim_selector" value="DKIM selector" />
                <x-text-input id="dkim_selector" name="dkim_selector" type="text" class="mt-1 block w-full"
                              :value="$value('dkim_selector')" :disabled="$readonly" placeholder="selector1" />
                <p class="mt-1 text-xs text-slate-500">
                    Optional, and only if your mail provider tells you which selector it signs with. Without it DKIM
                    is reported as not established rather than guessed at.
                </p>
                <x-input-error :messages="$errors->get('dkim_selector')" class="mt-1" />
            </div>
        </div>
    </x-card>

    @unless ($readonly)
        <div class="flex items-center gap-3">
            <x-primary-button>{{ $isEdit ? 'Save transport' : 'Create transport' }}</x-primary-button>
            <a href="{{ $managementModes !== null ? route('admin.smtp.accounts.index') : route('account.smtp.index') }}"
               class="text-sm text-slate-600 hover:text-slate-900">Cancel</a>
        </div>
    @endunless
</form>