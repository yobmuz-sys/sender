<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\System\Enums\Capability;
use App\Domain\System\Services\HostCapabilityInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DiagnosticsController extends Controller
{
    public function __construct(
        private readonly HostCapabilityInspector $inspector,
    ) {}

    /**
     * Liveness and readiness endpoint.
     *
     * Deliberately minimal and public: it exposes only the aggregate verdict,
     * never host paths, versions or dependency detail, so it is safe to leave
     * reachable on a monitoring service.
     *
     * A DEGRADED host still answers correctly (for example a low memory_limit
     * on a small shared plan), so it reports 200. Only a missing required
     * dependency makes the platform genuinely unserviceable and returns 503.
     */
    public function health(): JsonResponse
    {
        $report = $this->inspector->inspect();

        return response()->json([
            'status' => $report->overall->label() === 'Ready' ? 'ok' : strtolower($report->overall->label()),
            'capability' => $report->overall->value,
        ], $report->overall === Capability::Unavailable
            ? Response::HTTP_SERVICE_UNAVAILABLE
            : Response::HTTP_OK);
    }

    /**
     * Full host capability report.
     *
     * Restricted to staff holding `system.view`, because the detail contains
     * host-level information that helps an attacker fingerprint the machine.
     */
    public function index(): View
    {
        abort_unless(config('sender.diagnostics.enabled'), Response::HTTP_NOT_FOUND);

        return view('diagnostics', [
            'report' => $this->inspector->inspect(),
        ]);
    }
}
