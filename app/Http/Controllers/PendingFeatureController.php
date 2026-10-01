<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A page for the customer's product surface that has not been built yet.
 *
 * The page shells exist so the navigation can describe the whole product. They
 * return a successful response, because the page is intentional, and they state
 * plainly that the feature is pending — a shell that pretended to be a working
 * extractor or campaign would be worse than no page at all.
 *
 * Nothing here reads or writes domain data. That is the whole constraint: a
 * shell must not be able to present invented records as real ones.
 */
class PendingFeatureController extends Controller
{
    /**
     * Product sections that share this behaviour, declared once so the route
     * file and the template cannot disagree about what exists.
     *
     * @var array<string, array{title: string, description: string, stage: string, planned: list<string>}>
     */
    private const SECTIONS = [
        'extractor' => [
            'title' => 'Extractor',
            'description' => 'Turn a web page or pasted content into a clean list of email addresses.',
            'stage' => 'Stage 4 — email extraction engine',
            'planned' => [
                'Paste a URL or upload a file to extract addresses from',
                'Review, correct and deduplicate before saving',
                'Extract a list into files and lists',
            ],
        ],
        'files' => [
            'title' => 'Files',
            'description' => 'Extracted lists held as files you can reuse across campaigns.',
            'stage' => 'Stage 4 — email extraction engine',
            'planned' => [
                'Upload a file of addresses directly',
                'Name, describe and delete files',
                'Use a file as the source of a campaign',
            ],
        ],
        'lists' => [
            'title' => 'Lists',
            'description' => 'Curated collections of addresses, cleaned and kept current.',
            'stage' => 'Stage 4 — email extraction engine',
            'planned' => [
                'Create a list from an extraction or a file',
                'Review and correct extracted addresses',
                'Merge, rename and delete lists',
            ],
        ],
        'templates' => [
            'title' => 'Templates',
            'description' => 'Reusable message templates for your campaigns.',
            'stage' => 'Stage 5 — SMTP campaign engine',
            'planned' => [
                'Write and edit a message template',
                'Reuse a template across campaigns',
                'Personalise fields from the recipient list',
            ],
        ],
        'campaigns' => [
            'title' => 'Campaigns',
            'description' => 'Send a message to a list over SMTP.',
            'stage' => 'Stage 5 — SMTP campaign engine',
            'planned' => [
                'Create a campaign from a list and a template',
                'Review recipients before sending',
                'Pause, resume and inspect send progress',
            ],
        ],
        'suppression' => [
            'title' => 'Suppression',
            'description' => 'Addresses that must never be contacted again.',
            'stage' => 'Stage 5 — SMTP campaign engine',
            'planned' => [
                'Automatic suppression on hard bounce or complaint',
                'Manual suppression list',
                'Exclusion applied before every send',
            ],
        ],
        'analytics' => [
            'title' => 'Analytics',
            'description' => 'What has been extracted and what has been sent.',
            'stage' => 'Stage 5 — SMTP campaign engine',
            'planned' => [
                'Extraction totals over time',
                'Campaign delivery and bounce reporting',
                'List growth and suppression totals',
            ],
        ],
    ];

    public function __invoke(Request $request): View
    {
        // Read from the route rather than from the signature. The record
        // parameter carries a different name on each section (`{extraction}`,
        // `{file}`, `{list}`), and relying on positional injection would bind it
        // to the wrong argument and silently 404.
        $section = (string) $request->route()->parameter('section', 'extractor');

        abort_unless(isset(self::SECTIONS[$section]), 404);

        $config = self::SECTIONS[$section];

        return view('pending', [
            'title' => $config['title'],
            'description' => $config['description'],
            'stage' => $config['stage'],
            'planned' => $config['planned'],
            'section' => $section,
            'record' => $request->route()->parameter('record'),
        ]);
    }
}
