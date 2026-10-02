<x-layout>
    <x-slot:title>Add a transport</x-slot:title>

    <x-page-header
        title="Add a transport"
        description="Your password is encrypted at rest and never displayed again. Changing the password or the connection settings invalidates any previous verification."
    />

    <x-flash />

    <x-smtp-account-form
        :action="route('account.smtp.store')"
        :account="null"
        :providers="$providers"
        :encryptions="$encryptions"
        :auth-modes="$authModes"
    />
</x-layout>