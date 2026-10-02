<x-layout>
    <x-slot:title>Edit transport</x-slot:title>

    <x-page-header
        title="Edit transport"
        :description="'Assigned to '.($account->user?->email ?? 'no account').'. Leave the password blank to keep the stored one.'"
    />

    <x-flash />

    <x-smtp-account-form
        :action="route('admin.smtp.accounts.update', $account)"
        method="PUT"
        :account="$account"
        :providers="$providers"
        :encryptions="$encryptions"
        :auth-modes="$authModes"
        :management-modes="$managementModes"
        :selected-mode="$account->management_mode"
    />
</x-layout>