<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audience\ConsentLedger;
use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\UnsubscribeLink;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The page a recipient reaches by clicking unsubscribe in a message.
 *
 * This is the only page in the product that a person who has never heard of it
 * will ever see, and it is the one with the least room to get anything wrong.
 *
 *   - **No authentication, ever.** The recipient has no account and will never
 *     have one. A link that requires signing in is a link that does not work, and
 *     an unsubscribe requirement that is quietly not met is worse than none at
 *     all, because it looks met.
 *   - **Immediate, and synchronous.** The suppression is written during the
 *     request. There is no worker between the click and the record, so the next
 *     campaign cannot be assembled from an audience that still contains this
 *     person.
 *   - **Idempotent, and always says the same thing.** A second click, a
 *     forwarded link, a link from a campaign sent last year: all of them end in
 *     the same sentence. An error page for a recipient who has already unsubscribed
 *     would be telling somebody they did not succeed at something they succeeded
 *     at.
 *   - **No contact identity in the URL.** The token is opaque and identifies
 *     nobody. The page therefore never echoes an address back, not even to
 *     confirm which one — a confirmation is a disclosure, and this link travels
 *     through proxies, logs and history on its way to the recipient.
 *
 * `GET` renders a confirmation and `POST` acts, which is both the conventional
 * pattern and the one that keeps a link preview, a chat client's URL unfurler or
 * a security scanner from unsubscribing somebody on their behalf. Those clients
 * follow redirects and fetch links automatically; making the change require an
 * explicit click is what stops a mail client deciding for the recipient.
 */
class UnsubscribeController extends Controller
{
    /**
     * Show what the link will do, and ask.
     */
    public function show(UnsubscribeLink $links, string $token): View
    {
        $contact = $links->resolve($token);

        return view('unsubscribe.show', [
            // False rather than aborting: an unresolvable token gets the same
            // page as a resolvable one. A 404 here would tell a well-meaning
            // recipient that their link is broken, and would tell an attacker
            // probing tokens which of them are real.
            'resolvable' => $contact !== null,
            'alreadyUnsubscribed' => $contact !== null && app(SuppressionList::class)->isSuppressed($contact),
            // Passed back so the confirmation form can post to the same URL. The
            // token is opaque and identifies nobody, so returning it discloses
            // nothing the recipient did not already have.
            'token' => $token,
        ]);
    }

    /**
     * Act on it.
     *
     * @return View The same page, reporting an outcome. Always 200.
     */
    public function store(
        Request $request,
        string $token,
        UnsubscribeLink $links,
        SuppressionList $suppressions,
        ConsentLedger $consents,
    ): View {
        // Applied immediately, with no queue in the path. See the class note.
        $links->unsubscribe($token, $suppressions, $consents);

        return view('unsubscribe.show', [
            'resolvable' => true,
            'confirmed' => true,
            'alreadyUnsubscribed' => false,
            'token' => $token,
        ]);
    }
}
