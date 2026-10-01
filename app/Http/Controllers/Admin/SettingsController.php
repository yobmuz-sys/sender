<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Enums\DeploymentLimit;
use App\Domain\Users\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Configuration and limits, read-only.
 *
 * There is deliberately no web editor here. A form that rewrites arbitrary
 * configuration would let a compromised administrator session persist into the
 * next deploy, would need to handle the difference between `.env` and cached
 * config, and would bypass every invariant the platform checks at boot. Changing
 * these values is an operator editing the environment, and the diagnostic pages
 * then confirm the result.
 */
class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->can(Permission::SYSTEM_VIEW) ?? false, 403);

        return view('admin.settings.index', [
            'limits' => collect(DeploymentLimit::cases())->map(
                static fn (DeploymentLimit $limit): array => [
                    'name' => $limit->value,
                    'value' => $limit->value(),
                    'label' => self::labelFor($limit),
                ],
            ),
            'queue' => [
                'driver' => (string) config('queue.default'),
                'retry_after' => (int) config('queue.connections.'.config('queue.default').'.retry_after', 0),
                'reservation_margin' => (int) config('sender.capabilities.queue.reservation_margin_seconds'),
            ],
            'requirements' => self::flattenRequirements((array) config('sender.requirements', [])),
            'capabilities' => collect(CapabilitySubject::cases())
                ->mapWithKeys(fn (CapabilitySubject $subject): array => [
                    $subject->value => $subject->label(),
                ]),
        ]);
    }

    /**
     * Flatten the requirements tree into printable rows.
     *
     * The configuration groups extensions under a key, which is the right shape
     * for `config()` lookups but not for a definition list: iterating it
     * directly yields arrays where the view expects a scalar, which is a
     * rendering failure rather than a formatting one.
     *
     * @param  array<string, mixed>  $requirements
     * @return list<array{label: string, value: string}>
     */
    private static function flattenRequirements(array $requirements): array
    {
        $rows = [];

        foreach ($requirements as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $rows[] = [
                        'label' => self::humanise((string) $name).': '.self::humanise((string) $item),
                        'value' => 'required',
                    ];
                }

                continue;
            }

            $rows[] = [
                'label' => self::humanise((string) $name),
                'value' => is_scalar($value) ? (string) $value : 'set',
            ];
        }

        return $rows;
    }

    private static function humanise(string $value): string
    {
        return ucfirst(strtolower(str_replace(['_', '-'], ' ', $value)));
    }

    private static function labelFor(DeploymentLimit $limit): string
    {
        return match ($limit) {
            DeploymentLimit::MaxUploadBytes => 'Maximum uploaded file size (bytes)',
            DeploymentLimit::MaxTextInputBytes => 'Maximum pasted text (bytes)',
            DeploymentLimit::MaxUrlsPerRequest => 'Maximum seed URLs per request',
            DeploymentLimit::MaxWorkerRuntimeSeconds => 'Maximum worker runtime (seconds)',
            DeploymentLimit::MaxJobBatchSize => 'Maximum records per batch',
            DeploymentLimit::MaxJobAttempts => 'Maximum job attempts',
            DeploymentLimit::MaxStorageBytes => 'Maximum storage used (bytes)',
            DeploymentLimit::MaxApiRequestsPerHour => 'Maximum API requests per hour',
            DeploymentLimit::MaxCampaignRecipients => 'Maximum campaign recipients',
        };
    }
}
