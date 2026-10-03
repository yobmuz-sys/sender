<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Domain\Templates\MessageRenderer;
use App\Domain\Templates\PersonalisationToken;
use App\Domain\Templates\Template;
use App\Domain\Templates\TemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\StoreTemplateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Reusable message content belonging to one tenant.
 *
 * A template is not a campaign and never becomes one. Nothing here sends mail,
 * schedules anything, or decides who receives it; it stores a document, versions
 * it, and renders it for preview. The campaign stage will copy this content at
 * launch rather than read it while it runs — {@see Template::snapshot()} is that
 * contract — so editing a template here can never change a message already in
 * flight.
 *
 * Ownership is enforced by scoping every lookup to the signed-in account, and
 * another tenant's template is a 404 rather than a 403. A 403 confirms the record
 * exists, and template identifiers are sequential.
 *
 * The create and edit forms are one view, so the fields and their help text cannot
 * drift apart; the difference between the two screens is the heading and which
 * record was loaded.
 */
class TemplateController extends Controller
{
    /**
     * Templates per page.
     */
    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        return view('templates.index', [
            'templates' => Template::query()
                ->ownedBy((int) $request->user()->id)
                ->orderByDesc('updated_at')
                ->orderBy('id')
                ->paginate(self::PER_PAGE),
        ]);
    }

    public function create(): View
    {
        // One view renders both forms and reads `$template` unconditionally.
        // Passing null rather than omitting the key keeps the view free of an
        // `isset` guard that could drift out of step with this controller.
        return view('templates.create', [
            'template' => null,
            'tokens' => PersonalisationToken::cases(),
        ]);
    }

    public function store(StoreTemplateRequest $request, MessageRenderer $renderer): RedirectResponse
    {
        $template = new Template([
            'user_id' => $request->user()->id,
        ]);

        // `applyContent` rather than `fill()->save()`, because it is what keeps the
        // version counter and the derived status honest.
        $template->applyContent($request->safe()->only(Template::CONTENT_ATTRIBUTES));
        $template->save();

        return redirect()->route('templates.show', $template)
            ->with('status', 'Template created.');
    }

    /**
     * One template: what it is, and what it will look like.
     *
     * The preview is rendered with example values and shown inside a sandboxed
     * frame, so a customer's markup is displayed exactly as written without being
     * able to run anything in this page. The sandbox is the actual guarantee; the
     * renderer's own markup stripping is a second layer, not the boundary.
     */
    public function show(Request $request, MessageRenderer $renderer, mixed $template = null): View
    {
        $record = $this->owned($request, $template);

        $blockers = $record->blockers($renderer);
        $samples = $this->samples();

        return view('templates.show', [
            'template' => $record,
            'blockers' => $blockers,
            'status' => $blockers === [] ? TemplateStatus::Ready : TemplateStatus::Draft,
            'previewHtml' => $renderer->forPreview(
                $renderer->substitute((string) $record->html_body, $samples),
            ),
            'previewText' => $renderer->substitute((string) $record->text_body, $samples, false),
            'tokens' => PersonalisationToken::cases(),
            'usedTokens' => $renderer->tokensUsed(
                implode(' ', array_map(
                    static fn (string $field): string => (string) $record->{$field},
                    Template::CONTENT_ATTRIBUTES,
                )),
            ),
        ]);
    }

    public function edit(Request $request, mixed $template = null): View
    {
        return view('templates.create', [
            'template' => $this->owned($request, $template),
            'tokens' => PersonalisationToken::cases(),
        ]);
    }

    public function update(StoreTemplateRequest $request, mixed $template = null): RedirectResponse
    {
        $record = $this->owned($request, $template);

        $record->applyContent($request->safe()->only(Template::CONTENT_ATTRIBUTES));
        $record->save();

        return redirect()->route('templates.show', $record)
            ->with('status', 'Template updated.');
    }

    /**
     * Delete a template.
     *
     * A template is content, so deleting it removes words the customer wrote and
     * nothing else. There is no contact, no consent record and no suppression
     * attached to it: those belong to people, not to documents, and are not
     * reachable from here.
     */
    public function destroy(Request $request, mixed $template = null): RedirectResponse
    {
        $this->owned($request, $template)->delete();

        return redirect()->route('templates.index')
            ->with('status', 'Template deleted.');
    }

    /**
     * Copy a template into a new one.
     *
     * Version 1 rather than the source's version, because this is a different
     * document: it starts its own history, and claiming it is version 7 would make
     * the counter describe something that did not happen. The copy gets a name
     * that does not collide, because the customer cannot rename it here and
     * "October update" is not something to refuse a duplicate over.
     */
    public function duplicate(Request $request, mixed $template = null): RedirectResponse
    {
        $record = $this->owned($request, $template);

        $copy = new Template(['user_id' => $record->user_id]);

        $copy->applyContent([
            'name' => $this->copyNameFor($record),
            'subject' => $record->subject,
            'preheader' => $record->preheader,
            'html_body' => $record->html_body,
            'text_body' => $record->text_body,
        ]);
        $copy->save();

        return redirect()->route('templates.edit', $copy)
            ->with('status', 'Template copied. Give it a name of its own, then save.');
    }

    /**
     * Example values for the preview.
     *
     * @return array<string, string>
     */
    private function samples(): array
    {
        $samples = [];

        foreach (PersonalisationToken::cases() as $token) {
            $samples[$token->value] = $token->sample();
        }

        return $samples;
    }

    /**
     * A copy name that is free for this tenant.
     */
    private function copyNameFor(Template $template): string
    {
        $base = Str::limit($template->name, 100, '').' (copy)';

        $candidate = $base;

        for ($attempt = 2; $attempt <= 50; $attempt++) {
            $taken = Template::query()
                ->ownedBy((int) $template->user_id)
                ->where('name', $candidate)
                ->exists();

            if (! $taken) {
                return $candidate;
            }

            $candidate = $base.' '.$attempt;
        }

        // Fifty copies of one template is not a naming decision any more.
        return $base.' '.substr((string) Str::uuid(), 0, 8);
    }

    /**
     * A template belonging to this account, or 404.
     */
    private function owned(Request $request, mixed $template): Template
    {
        if (! is_numeric((string) $template)) {
            abort(404);
        }

        $record = Template::query()->find((int) $template);

        if ($record === null || (int) $record->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        return $record;
    }
}
