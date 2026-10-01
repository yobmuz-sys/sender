<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A page for a part of the platform that does not exist yet.
 *
 * This exists so the navigation can describe the whole product without any
 * region pretending to work. The alternative — omitting a section until it is
 * built — makes a deliberately staged platform look accidentally incomplete, and
 * leaves nowhere to explain what a section is for.
 *
 * The rules this class exists to enforce:
 *
 *   - it returns a successful response, because the page is intentional;
 *   - it states plainly that the feature is pending and which stage supplies it;
 *   - it touches no domain data, so it cannot invent rows or look operational.
 */
abstract class StagedPageController extends Controller
{
    abstract protected function stage(): string;

    abstract protected function permission(): string;

    /**
     * @return array<string, string>
     */
    abstract protected function plannedCapabilities(): array;

    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->can($this->permission()) ?? false, 403);

        return view('admin.staged', [
            'title' => $this->title(),
            'description' => $this->description(),
            'stage' => $this->stage(),
            'planned' => $this->plannedCapabilities(),
        ]);
    }

    abstract protected function title(): string;

    abstract protected function description(): string;
}
