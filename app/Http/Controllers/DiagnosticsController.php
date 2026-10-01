<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\System\Capabilities\AvailabilityResolver;
use App\Domain\System\Capabilities\CapabilityRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DiagnosticsController extends Controller
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly AvailabilityResolver $availability,
    ) {}

    /**
     * Liveness and readiness endpoint.
     *
     * Deliberately minimal and public: it exposes only the aggregate verdict,
     * never host paths, versions or dependency detail, so it is safe to leave
     * reachable on a monitoring service.
     *
     * Only UNAVAILABLE makes the platform genuinely unserviceable. DEGRADED is
     * reported as-is because a host with less headroom still answers
     * correctly, and UNKNOWN is only fatal when a required capability could not
     * be established.
     */
    public function health(): JsonResponse
    {
        $overall = $this->capabilities->overall();

        return response()->json([
            'status' => match ($overall->value) {
                'READY' => 'ok',
                'DEGRADED' => 'degraded',
                'UNKNOWN' => 'unknown',
                default => 'unavailable',
            },
            'capability' => $overall->value,
        ], $overall->isUnavailable()
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
            'report' => $this->capabilities->report(),
            'capabilities' => $this->capabilities,
            'availability' => $this->availability->resolveAll(),
        ]);
    }
}
