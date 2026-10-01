<button {{ $attributes->merge(['type' => 'submit', 'class' => 'rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 disabled:opacity-50']) }}>
    {{ $slot }}
</button>