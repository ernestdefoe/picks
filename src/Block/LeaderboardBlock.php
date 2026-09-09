<?php

declare(strict_types=1);

namespace Resofire\Picks\Block;

use Ernestdefoe\PageBuilder\Block\AbstractBlock;
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
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function type(): string
    {
        return 'picks-leaderboard';
    }

    public function name(): string
    {
        return 'Pick\'em Standings';
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
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => 'Pick\'em Standings'],
            [
                'key' => 'scope',
                'type' => 'select',
                'label' => 'Standings',
                'default' => 'alltime',
                'options' => [
                    ['value' => 'alltime', 'label' => 'All time'],
                    ['value' => 'season', 'label' => 'This season'],
                ],
            ],
            ['key' => 'limit', 'type' => 'range', 'label' => 'How many', 'default' => 10, 'min' => 3, 'max' => 25],
            [
                'key' => 'hideWhenEmpty',
                'type' => 'toggle',
                'label' => 'Hide when nobody has picked',
                'default' => true,
                'help' => 'Before the first week of a season there is nothing to rank.',
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
                'u.display_name',
                'u.avatar_url',
                's.total_points',
                's.total_picks',
                's.correct_picks',
                's.accuracy',
            ]);

        $out = [];
        $rank = 0;

        foreach ($rows as $row) {
            $out[] = [
                'rank' => ++$rank,
                'id' => (int) $row->id,
                'username' => (string) $row->username,
                'displayName' => (string) ($row->display_name ?: $row->username),
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
