<x-layout>
    <x-slot:title>Edit transport</x-slot:title>

    <x-page-header
        title="Edit transport"
        :description="'Leave the password blank to keep the stored one. Changing the connection or the password invalidates the previous verification.'"
    />

    <x-flash />

    @if ($managed)
        <x-alert variant="warning" title="This transport is managed by platform staff" class="mb-4">
            You can see these settings, but the host and credentials are theirs to correct. Contact an administrator
            if the transport stops working.
        </x-alert>
    @endif

    <x-smtp-account-form
        :action="route('account.smtp.update', $account)"
        method="PUT"
        :account="$account"
        :providers="$providers"
        :encryptions="$encryptions"
        :auth-modes="$authModes"
        :readonly="$managed"
    />
</x-layout>