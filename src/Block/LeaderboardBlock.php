<?php

declare(strict_types=1);

namespace Resofire\Picks\Block;

use Ernestdefoe\PageBuilder\Block\AbstractBlock;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * The pick'em standings, as a Page Builder block.
 *
 * 🚨 Only ever loaded where Page Builder is installed — the extender that
 * registers it is added conditionally in extend.php and nothing else names it.
 * It extends a class from an extension this one does not require, which is safe
 * for exactly that reason and dangerous the moment anything else refers to it.
 */
class LeaderboardBlock extends AbstractBlock
{
    public function __construct(
        protected ConnectionInterface $db,
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * 🚨 Translated here, on the server. Page Builder draws a block's name and
     * settings labels exactly as the schema hands them over, so a string that is
     * not translated before it leaves this class is English on every forum.
     */
    private function t(string $key): string
    {
        return $this->translator->trans('ernestdefoe-picks.admin.block.' . $key);
    }

    public function type(): string
    {
        return 'picks-leaderboard';
    }

    public function name(): string
    {
        return $this->t('name');
    }

    public function icon(): string
    {
        return 'fas fa-trophy';
    }

    public function category(): string
    {
        return 'forum';
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'title', 'type' => 'text', 'label' => $this->t('title'), 'default' => $this->t('name')],
            [
                'key' => 'scope',
                'type' => 'select',
                'label' => $this->t('scope'),
                'default' => 'alltime',
                'options' => [
                    ['value' => 'alltime', 'label' => $this->t('scope_alltime')],
                    ['value' => 'season', 'label' => $this->t('scope_season')],
                ],
            ],
            ['key' => 'limit', 'type' => 'range', 'label' => $this->t('limit'), 'default' => 10, 'min' => 3, 'max' => 25],
            [
                'key' => 'hideWhenEmpty',
                'type' => 'toggle',
                'label' => $this->t('hide_empty'),
                'default' => true,
                'help' => $this->t('hide_empty_help'),
            ],
        ];
    }

    /**
     * 🚨 Resolved server-side, so the standings are drawn with the page.
     *
     * 🚨 Guarded on `picks.view`, the same permission the leaderboard endpoint
     * checks. A block is placed by an admin but READ by whoever loads the page,
     * and a board that keeps its pick'em to members must not publish the table
     * on its front page to everyone who visits.
     */
    public function resolve(array $settings, User $actor): array
    {
        if (! $actor->hasPermission('picks.view')) {
            return ['rows' => []];
        }

        $limit = max(3, min((int) ($settings['limit'] ?? 10), 25));
        $season = ($settings['scope'] ?? 'alltime') === 'season';

        $query = $this->db->table('picks_user_scores as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            /*
             * 🚨 Week rows are excluded on both paths. The table holds a row per
             * week AS WELL as the totals, and a leaderboard that swept them all
             * up would list the same person once per week they played.
             */
            ->whereNull('s.week_id');

        if ($season) {
            $query->whereNotNull('s.season_id');
        } else {
            // All-time is the row belonging to no season in particular.
            $query->whereNull('s.season_id');
        }

        $rows = $query
            ->orderByDesc('s.total_points')
            ->orderByDesc('s.accuracy')
            ->limit($limit)
            ->get([
                'u.id as id',
                'u.username',
                'u.avatar_url',
                's.total_points',
                's.total_picks',
                's.correct_picks',
                's.accuracy',
            ]);

        /*
         * 🚨 `display_name` is NOT a column — it is an accessor, and what backs
         * it depends on which extensions are installed (Nicknames supplies a
         * `nickname`; without it the accessor falls back to the username).
         * Selecting it in SQL is an "Unknown column" the moment this block is
         * placed on a page, which is how it took the whole front page down.
         *
         * One extra query for the whole table, resolved through the model so
         * whatever the site uses for display names is what appears.
         */
        $names = [];

        if (count($rows)) {
            foreach (\Flarum\User\User::query()->whereIn('id', $rows->pluck('id')->all())->get() as $user) {
                $names[(int) $user->id] = (string) $user->display_name;
            }
        }

        $out = [];
        $rank = 0;

        foreach ($rows as $row) {
            $out[] = [
                'rank' => ++$rank,
                'id' => (int) $row->id,
                'username' => (string) $row->username,
                'displayName' => $names[(int) $row->id] ?? (string) $row->username,
                'avatarUrl' => $row->avatar_url
                    ? $this->avatar((string) $row->avatar_url)
                    : null,
                'points' => (int) $row->total_points,
                'correct' => (int) $row->correct_picks,
                'total' => (int) $row->total_picks,
                'accuracy' => (float) $row->accuracy,
            ];
        }

        return ['rows' => $out];
    }

    /**
     * An avatar filename, as the URL the browser needs.
     *
     * 🚨 Core stores only the file name in `avatar_url` and builds the URL from
     * the assets path at serialisation time. This block bypasses the serialiser
     * — it answers with a flat array — so it has to do the same job, and a
     * filename handed to an `<img>` renders as a broken image on every row.
     */
    protected function avatar(string $name): string
    {
        if (preg_match('#^https?://#i', $name)) {
            return $name;
        }

        return rtrim((string) resolve('flarum.config')->url(), '/') . '/assets/avatars/' . $name;
    }
}
