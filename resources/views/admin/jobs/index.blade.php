<x-layout>
    <x-slot:title>Jobs</x-slot:title>

    <x-page-header
        title="Jobs"
        description="The Laravel queue as infrastructure. This is not yet a job-management system, and the queue tables hold no product data."
    />

    <x-alert variant="info" class="mb-6">
        No workload is queued yet. The processing engine is derived from the first real workload —
        extraction or SMTP delivery — rather than built speculatively. Nothing here can retry, cancel or
        pause a job, because no product job model exists to do that to.
    </x-alert>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat label="Queue driver" :value="$driver" />
        <x-stat label="Pending" :value="$pending" hint="rows in the jobs table" />
        <x-stat label="Failed" :value="$failed" hint="rows in failed_jobs" />
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <x-card title="Reservation safety"
               description="A job shorter than its reservation can be processed twice by two workers.">
            <dl class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-600">Queue capability</dt>
                    <dd><x-status-badge :status="$queueCapability->value" /></dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-600">retry_after</dt>
                    <dd class="font-mono text-slate-800">{{ $retryAfter }}s</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-600">Maximum worker runtime</dt>
                    <dd class="font-mono text-slate-800">{{ $maxRuntime }}s</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-600">Reservation margin</dt>
                    <dd class="font-mono text-slate-800">{{ $margin }}s</dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-slate-500">
                Requires <span class="font-mono">{{ $retryAfter }} &ge; {{ $maxRuntime }} + {{ $margin }}</span>.
                A violation is reported as <strong>Unavailable</strong>, because a supported driver
                configured unsafely is not ready.
            </p>
        </x-card>

        <x-card title="Processing limits">
            <dl class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-600">Reserved (in flight)</dt>
                    <dd class="font-mono text-slate-800">{{ $reserved }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-600">Maximum batch size</dt>
                    <dd class="font-mono text-slate-800">{{ $maxBatchSize }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-600">Maximum attempts</dt>
                    <dd class="font-mono text-slate-800">{{ $maxAttempts }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-slate-500">
                No worker process is expected to run on ordinary shared hosting. These figures are
                enforced by whichever workload the next stage selects.
            </p>
        </x-card>
    </div>

    <p class="mt-6 text-sm">
        <a href="{{ route('admin.runs.index') }}" class="font-medium text-slate-700 underline">
            View scheduled run evidence
        </a>
    </p>
</x-layout>
