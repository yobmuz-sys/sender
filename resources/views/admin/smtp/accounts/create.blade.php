<x-layout>
    <x-slot:title>Create a transport</x-slot:title>

    <x-page-header
        title="Create a transport for a tenant"
        description="The credential is stored encrypted and is never rendered back into this form, here or anywhere else."
    />

    <x-flash />

    <x-smtp-account-form
        :action="route('admin.smtp.accounts.store')"
        :account="null"
        :providers="$providers"
        :encryptions="$encryptions"
        :auth-modes="$authModes"
        :management-modes="$managementModes"
        :user-id="$userId"
        :selected-mode="$selectedMode"
    />
</x-layout>