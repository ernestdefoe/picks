# Picks

A [Flarum](https://flarum.org) 2.x extension that adds a college-football
**pick'em** game to your forum. Members predict the winners of each week's games,
earn points, and compete on a season leaderboard. Schedules and teams are synced
from [CollegeFootballData](https://collegefootballdata.com) (CFBD), and team
logos / live scores come from ESPN.

## Features

- **Weekly picks** — members pick winners for each game in a week; picks lock at
  kickoff (with an optional offset).
- **Live scoring** — a scheduled task polls ESPN for in-progress scores and grades
  picks automatically as games finish.
- **Leaderboard** — season standings with per-week history and per-user pick
  history (`/u/{username}/picks-history`).
- **Confidence mode (optional)** — members rank their picks by confidence for
  weighted scoring, with a configurable penalty for missed high-confidence picks.
- **Admin management** — sync teams/schedule/scores, open/unlock weeks, enter or
  override results, refresh team logos, and reset data, all from the admin panel.
- **Per-permission access** — separate abilities for viewing, making picks,
  viewing history, and managing.

## Requirements

- Flarum `^2.0`
- A free **CollegeFootballData API key** — get one at
  <https://collegefootballdata.com/key> (used for team and schedule syncing).

## Installation

```bash
composer require ernestdefoe/picks
php flarum cache:clear
```

Then enable **Picks** in the admin panel under Extensions.

## Setup

1. Open the **Picks** page in the admin panel.
2. Paste your **CFBD API key** and set the **season year** (and optionally a
   conference filter).
3. **Sync Teams**, then **Sync Schedule** to pull the season's games.
4. **Open** the week(s) you want members to pick.
5. Grant the picks permissions to the appropriate groups (see below).

To keep live scores updating, make sure Flarum's scheduler is running (see
**Scheduler** below) and enable **ESPN polling** in the settings.

## Permissions

Set these under **Admin → Permissions**:

| Ability | Lets a user… |
|---|---|
| `picks.view` | See the Picks page and leaderboard. |
| `picks.makePicks` | Submit and change their own picks. |
| `picks.viewHistory` | View pick history for users. |
| `picks.manage` | Manage the game (sync, results, weeks, settings). Admins always have this. |

## Settings

Configured on the admin **Picks** page (stored under the `ernestdefoe-picks.*`
namespace):

- **CFBD API key**, **season year**, **conference filter**
- **Sync regular season / postseason**, **auto-sync**
- **Picks lock offset** (minutes before kickoff)
- **Confidence mode** + **confidence penalty** (`none` / `half` / `full`)
- **Auto-unlock weeks**, **default week view**
- **ESPN polling** + **poll interval**
- **Nav label** (the forum nav link text)

## Scheduler

Live-score polling runs as a scheduled command (`PollLiveScoresCommand`, every 5
minutes). For it to fire, Flarum's scheduler must be invoked once a minute by
cron:

```cron
* * * * * cd /path/to/forum && php flarum schedule:run >> /dev/null 2>&1
```

You can also run syncs manually:

```bash
php flarum picks:sync-teams      # sync the FBS team list from CFBD
php flarum picks:poll-scores     # poll ESPN for live scores once
```

## Data sources

- **CollegeFootballData (CFBD)** — teams and game schedules (API key required).
- **ESPN** — team logos and live in-game scores (public endpoints, no key).

Team names, logos, and data are the property of their respective owners and the
providers above. This is an unofficial fan tool and is not affiliated with or
endorsed by the NCAA, CFBD, ESPN, or any team.

## Credits

Originally created by [resofire](https://github.com/resofire); now maintained by
[ernestdefoe](https://github.com/ernestdefoe) as `ernestdefoe/picks`.

## License

MIT
