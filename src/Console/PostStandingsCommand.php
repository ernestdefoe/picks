<?php

namespace Resofire\Picks\Console;

use Carbon\Carbon;
use Flarum\Console\AbstractCommand;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Foundation\Config;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Resofire\Picks\Season;
use Resofire\Picks\Standings\StandingsTags;
use Resofire\Picks\UserScore;
use Resofire\Picks\Week;
use Symfony\Component\Console\Input\InputOption;

/**
 * Publishes the week's pick'em standings as a discussion.
 *
 * 🚨 A post, not a page. The standings already exist on /picks, and nobody who
 * is not already in the habit goes and looks. A thread arrives in the feed, in
 * the digest and in notifications, and can be replied to — which is the point:
 * the table is the excuse, the argument underneath it is the thing.
 *
 * 🚨 One thread per week, and a rerun EDITS it. Keyed on the week, so a cron
 * that fires twice does not produce two of them — and a correction after a
 * result is fixed lands on the post people already replied to.
 */
class PostStandingsCommand extends AbstractCommand
{
    use DispatchEventsTrait;

    public const SETTING_TAG = 'ernestdefoe-picks.standings_tag';
    public const SETTING_AUTHOR = 'ernestdefoe-picks.standings_author_id';

    /** How many places the post lists before it stops being readable. */
    private const PLACES = 15;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Config $config,
        protected TranslatorInterface $translator,
        protected JsonApi $api,
        protected StandingsTags $tags,
        protected Dispatcher $events
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('picks:post-standings')
            ->setDescription("Publish the week's pick'em standings as a discussion.")
            ->addOption('week', null, InputOption::VALUE_REQUIRED, 'Week id. Defaults to the last week with a scored pick.')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Tag slug to post into.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the post and write nothing.');
    }

    protected function fire(): int
    {
        $week = $this->week();

        if ($week === null) {
            $this->info('No scored week to report on yet.');

            return 0;
        }

        $rows = $this->standings($week);

        if ($rows === []) {
            /*
             * 🚨 Nothing is published when nobody played. An empty standings
             * post every week is a board advertising that no one is here, which
             * is the opposite of what it is for.
             */
            $this->info('Nobody has a scored pick for '.$week->name.'. Nothing published.');

            return 0;
        }

        /*
         * 🚨 Translated in the forum's default locale — the console has no
         * visitor to take one from.
         */
        $title = $this->trans('title', ['week' => trim($week->name)]);
        $body = $this->body($week, $rows);

        if ($this->input->getOption('dry-run')) {
            // 🚨 writeln, not line(): Flarum's AbstractCommand swallowed a
            // multi-line body and printed only the title, with no error.
            $this->output->writeln($title);
            $this->output->writeln('');
            $this->output->writeln($body);

            return 0;
        }

        return $this->publish($week, $title, $body);
    }

    /** Edit the week's thread if it was published already, or start it. */
    protected function publish(Week $week, string $title, string $body): int
    {
        $author = $this->author();

        if ($author === null) {
            $this->error('No author to post as. Set '.self::SETTING_AUTHOR.' or give an admin account.');

            return 1;
        }

        $slug = trim((string) ($this->input->getOption('tag') ?: $this->settings->get(self::SETTING_TAG, '')));

        if ($this->tags->missing($slug)) {
            $this->error('No tag with slug "'.$slug.'" — the standings will be posted untagged.');
        }

        $existing = $this->thread($week, $author, $title);

        $post = $existing === null ? null : CommentPost::query()->find($existing->first_post_id);

        if ($post !== null) {
            // Core's own edit, so Revised fires as it does for any edited post.
            $post->revise($body, $author)->save();
            $this->dispatchEventsFor($post, $author);
            $this->info("Updated “{$title}” (discussion #{$existing->id}).");

            return 0;
        }

        /*
         * 🚨 Through the JSON:API, as the author, so core and every extension
         * see an ordinary new discussion: Started and Posted fire, followers are
         * notified, search indexes it and the tag counters move.
         */
        $tags = $this->tags->relationship($slug);
        $discussion = $this->api->forResource(DiscussionResource::class)->forEndpoint('create')->process(
            ['data' => array_filter([
                'attributes' => ['title' => $title, 'content' => $body],
                'relationships' => $tags === null ? null : ['tags' => $tags],
            ])],
            options: ['actor' => $author]
        );

        $week->standings_discussion_id = $discussion->id;
        $week->save();

        $this->info("Published “{$title}” as discussion #{$discussion->id}.");

        return 0;
    }

    /**
     * The week's standings thread, if it was already published.
     *
     * 🚨 By the id the week remembers, and only while the author still owns
     * it. "Week 1" names a week in every season, and any member can start a
     * thread with the same title: matching on the title alone edited last
     * season's post, or somebody else's.
     */
    protected function thread(Week $week, User $author, string $title): ?Discussion
    {
        if ($week->standings_discussion_id) {
            $found = Discussion::query()->whereKey($week->standings_discussion_id)->where('user_id', $author->id)->first();

            if ($found !== null && $found->first_post_id) {
                return $found;
            }
        }

        /*
         * A board that published this week before the id was kept: the
         * author's own thread with this title, started once the week was under
         * way. Adopted once, then remembered.
         */
        $since = $week->events()->min('match_date');
        $legacy = $since === null ? null : Discussion::query()
            ->where('title', $title)
            ->where('user_id', $author->id)
            ->where('created_at', '>=', Carbon::parse((string) $since, 'UTC')->subDays(7))
            ->whereNotNull('first_post_id')
            ->orderByDesc('id')
            ->first();

        if ($legacy !== null) {
            $week->standings_discussion_id = $legacy->id;
            $week->save();
        }

        return $legacy;
    }

    /**
     * The most recent week with scored picks, in the most recent season that
     * has any.
     *
     * 🚨 Season first. Week numbers start again every season, so the highest
     * week number anywhere was last season's final week all through the next
     * one.
     */
    protected function week(): ?Week
    {
        $given = $this->input->getOption('week');

        if ($given) {
            return Week::query()->find((int) $given);
        }

        $scored = fn ($q) => $q->whereHas('events', fn ($e) => $e->whereHas('picks', fn ($p) => $p->whereNotNull('is_correct')));

        $season = Season::query()
            ->whereHas('weeks', $scored)
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->first();

        return $season === null ? null : Week::query()
            ->where('season_id', $season->id)
            ->where($scored)
            // Postseason after the regular season, then the latest week.
            ->orderByRaw(Week::seasonTypeOrder('postseason'))
            ->orderByDesc('week_number')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The season table, with this week's record beside it.
     *
     * 🚨 Season order, not the week's. A weekly post that ranks on the week
     * alone tells somebody who has played all season that a newcomer with one
     * good Saturday is ahead of them, which is true of nothing anybody cares
     * about.
     *
     * @return list<array<string, mixed>>
     */
    protected function standings(Week $week): array
    {
        $seasonId = (int) $week->season_id;

        $season = UserScore::query()
            ->with('user')
            ->whereNull('week_id')
            ->where('season_id', $seasonId)
            ->where('total_picks', '>', 0)
            ->orderByDesc('total_points')
            ->orderByDesc('accuracy')
            ->limit(self::PLACES)
            ->get();

        $weekly = UserScore::query()
            ->where('week_id', $week->id)
            ->get()
            ->keyBy('user_id');

        $rows = [];
        $place = 0;

        foreach ($season as $score) {
            $place++;
            $w = $weekly->get($score->user_id);

            $rows[] = [
                'place' => $place,
                'name' => (string) ($score->user->display_name ?? $score->user->username ?? ''),
                'points' => (int) $score->total_points,
                'record' => $score->correct_picks.'-'.max(0, $score->total_picks - $score->correct_picks),
                'accuracy' => (float) $score->accuracy,
                'week' => $w ? $w->correct_picks.'-'.max(0, $w->total_picks - $w->correct_picks) : null,
                'movement' => $score->previous_rank !== null ? ((int) $score->previous_rank - $place) : null,
            ];
        }

        return array_values(array_filter($rows, fn ($r) => $r['name'] !== ''));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    protected function body(Week $week, array $rows): string
    {
        $lines = [];

        foreach ($rows as $r) {
            /*
             * 🚨 A plain arrow rather than a coloured badge. This is post text,
             * parsed by whichever formatter the board has, and anything cleverer
             * renders as literal markup on a site without it.
             */
            $move = match (true) {
                $r['movement'] === null => '',
                $r['movement'] > 0 => '  ▲'.$r['movement'],
                $r['movement'] < 0 => '  ▼'.abs($r['movement']),
                default => '  –',
            };

            $lines[] = sprintf(
                '%d. %s — %s (%d%%)%s%s',
                $r['place'],
                $r['name'],
                $r['record'],
                round($r['accuracy']),
                $r['week'] !== null ? '  ·  '.$this->trans('row_week', ['record' => $r['week']]) : '',
                $move
            );
        }

        $best = $this->bestOfWeek($week);

        $blocks = [
            $this->trans('intro', ['week' => trim($week->name)]),
            implode("\n", $lines),
        ];

        if ($best !== null) {
            $blocks[] = $best;
        }

        $blocks[] = $this->trans('open', ['url' => $this->url().'/picks']);
        $blocks[] = $this->trans('outro');

        return implode("\n\n", $blocks);
    }

    /** Who had the best week, named only when somebody clearly did. */
    protected function bestOfWeek(Week $week): ?string
    {
        $top = UserScore::query()
            ->with('user')
            ->where('week_id', $week->id)
            ->where('total_picks', '>', 0)
            ->orderByDesc('correct_picks')
            ->first();

        if ($top === null || $top->correct_picks < 1) {
            return null;
        }

        $name = (string) ($top->user->display_name ?? $top->user->username ?? '');

        return $name === '' ? null : $this->trans('best', [
            'name' => $name,
            'record' => $top->correct_picks.'-'.max(0, $top->total_picks - $top->correct_picks),
        ]);
    }

    /** @param array<string, string|int> $params */
    protected function trans(string $key, array $params = []): string
    {
        return $this->translator->trans('ernestdefoe-picks.api.standings_post.'.$key, $params);
    }

    /**
     * 🚨 From the application's own config, not the `forum_url` setting.
     *
     * That setting is empty on a perfectly healthy install — it was on fbsfb —
     * and the post then invited everybody to visit "/picks", which is not a
     * link anywhere outside the site and is useless in an email.
     */
    protected function url(): string
    {
        return rtrim((string) $this->config->url(), '/');
    }

    protected function author(): ?User
    {
        $id = (int) $this->settings->get(self::SETTING_AUTHOR, 0);

        if ($id > 0) {
            $user = User::query()->find($id);

            if ($user !== null) {
                return $user;
            }
        }

        // Fall back to the first administrator, so a board that never set this
        // still gets its standings rather than silently getting nothing.
        return User::query()
            ->whereIn('id', fn ($q) => $q->select('user_id')->from('group_user')->where('group_id', 1))
            ->orderBy('id')
            ->first();
    }
}
