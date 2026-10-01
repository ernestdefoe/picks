<?php

namespace Resofire\Picks\Console;

use Carbon\Carbon;
use Flarum\Console\AbstractCommand;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Resofire\Picks\Season;
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
    public const SETTING_TAG = 'ernestdefoe-picks.standings_tag';
    public const SETTING_AUTHOR = 'ernestdefoe-picks.standings_author_id';

    /** How many places the post lists before it stops being readable. */
    private const PLACES = 15;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db
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
            $this->info('Nobody has a scored pick for ' . $week->name . '. Nothing published.');

            return 0;
        }

        $title = trim($week->name) . ' pick\'em standings';
        $body = $this->body($week, $rows);

        if ($this->input->getOption('dry-run')) {
            $this->info($title);
            $this->line('');
            $this->line($body);

            return 0;
        }

        $author = $this->author();

        if ($author === null) {
            $this->error('No author to post as. Set ' . self::SETTING_AUTHOR . ' or give an admin account.');

            return 1;
        }

        $existing = Discussion::query()->where('title', $title)->first();

        if ($existing !== null) {
            $post = CommentPost::query()->find($existing->first_post_id);

            if ($post !== null) {
                $post->setContentAttribute($body, $author);
                $post->edited_at = Carbon::now();
                $post->edited_user_id = $author->id;
                $post->save();
                $this->info("Updated “{$title}” (discussion #{$existing->id}).");

                return 0;
            }
        }

        $discussion = Discussion::start($title, $author);
        $discussion->save();

        /*
         * 🚨 Built by hand, not CommentPost::reply() — that is a Flarum 1.x
         * static that no longer exists, and calling it throws a
         * BadMethodCallException rather than anything the signature warns of.
         */
        $post = new CommentPost();
        $post->discussion_id = $discussion->id;
        $post->user_id = $author->id;
        $post->type = CommentPost::$type;
        $post->created_at = Carbon::now();
        $post->setContentAttribute($body, $author);
        $post->save();

        $discussion->setFirstPost($post);
        $discussion->setLastPost($post);
        $discussion->save();

        $this->tag($discussion);

        $this->info("Published “{$title}” as discussion #{$discussion->id}.");

        return 0;
    }

    /** The most recent week that actually has scored picks in it. */
    protected function week(): ?Week
    {
        $given = $this->input->getOption('week');

        if ($given) {
            return Week::query()->find((int) $given);
        }

        return Week::query()
            ->whereHas('events', fn ($q) => $q->whereHas('picks', fn ($p) => $p->whereNotNull('is_correct')))
            ->orderByDesc('week_number')
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
                'name' => (string) ($score->user?->display_name ?? $score->user?->username ?? ''),
                'points' => (int) $score->total_points,
                'record' => $score->correct_picks . '-' . max(0, $score->total_picks - $score->correct_picks),
                'accuracy' => (float) $score->accuracy,
                'week' => $w ? $w->correct_picks . '-' . max(0, $w->total_picks - $w->correct_picks) : null,
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
                $r['movement'] > 0 => '  ▲' . $r['movement'],
                $r['movement'] < 0 => '  ▼' . abs($r['movement']),
                default => '  –',
            };

            $lines[] = sprintf(
                '%d. %s — %s (%d%%)%s%s',
                $r['place'],
                $r['name'],
                $r['record'],
                round($r['accuracy']),
                $r['week'] !== null ? '  ·  this week ' . $r['week'] : '',
                $move
            );
        }

        $best = $this->bestOfWeek($week);

        $blocks = [
            'Standings after ' . trim($week->name) . '.',
            implode("\n", $lines),
        ];

        if ($best !== null) {
            $blocks[] = 'Best of the week: ' . $best;
        }

        $blocks[] = 'Picks for the next round are open — ' . $this->url() . '/picks';
        $blocks[] = 'Argue with the table below.';

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

        $name = (string) ($top->user?->display_name ?? $top->user?->username ?? '');

        return $name === '' ? null : sprintf(
            '%s, %d-%d.',
            $name,
            $top->correct_picks,
            max(0, $top->total_picks - $top->correct_picks)
        );
    }

    protected function url(): string
    {
        return rtrim((string) $this->settings->get('forum_url', ''), '/');
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

    protected function tag(Discussion $discussion): void
    {
        $slug = trim((string) ($this->input->getOption('tag') ?: $this->settings->get(self::SETTING_TAG, '')));

        if ($slug === '' || ! $this->db->getSchemaBuilder()->hasTable('tags')) {
            return;
        }

        $tagId = $this->db->table('tags')->where('slug', $slug)->value('id');

        if ($tagId === null) {
            $this->error('No tag with slug "' . $slug . '" — the standings were posted untagged.');

            return;
        }

        $this->db->table('discussion_tag')->insertOrIgnore([
            'discussion_id' => $discussion->id,
            'tag_id' => $tagId,
        ]);
    }
}
