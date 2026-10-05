<?php

namespace Resofire\Picks\Frontend;

use Flarum\Frontend\Document;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A title and a description for the pick'em pages, server side.
 *
 * 🚨 Without this every custom route serves a page titled with the forum's own
 * name and nothing else. Measured on fbsfb: `/picks`, `/roster`, `/fantasy` and
 * `/gallery` all answered 200 with `<title>FBSFB</title>` — four pages a search
 * engine cannot tell apart, and the one a reader would most want to land on
 * among them.
 *
 * 🚨 Set on the SERVER's document, not in the browser. Mithril changes the
 * title after the page loads, which is right for a reader and invisible to a
 * crawler: what gets indexed is what came down the wire.
 */
class PicksPageContent
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    /**
     * 🚨 The TITLE only. The description is fof/seo's to write.
     *
     * Setting one here left TWO `<meta name="description">` tags on the page —
     * measured on the demo, both served — because fof/seo appends the
     * forum-wide one AFTER a route's own content callable runs, so filtering
     * first removes nothing. A crawler then picks one, usually the first, and
     * the specific description loses to the generic one silently.
     *
     * A per-page description belongs in a fof/seo page driver
     * (`FoF\Seo\Extend\SEO::addExtender`), which is a larger piece of work and
     * a hard coupling to that extension. The title is the stronger signal and
     * carries no such conflict.
     */
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $document->title = $this->translator->trans('ernestdefoe-picks.api.page_title');
    }

    /**
     * Replace the page's description rather than adding a second one.
     *
     * 🚨 Appending leaves TWO `<meta name="description">` tags on the page —
     * fof/seo has already written the forum-wide one by the time this runs, and
     * measured on the demo both were served. A crawler picks one, usually the
     * first, so the specific description silently loses to the generic one and
     * every page still describes the whole site.
     */
}
