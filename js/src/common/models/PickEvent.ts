import Model from 'flarum/common/Model';
import Team from './Team';
import Week from './Week';

export default class PickEvent extends Model {
  weekId       = Model.attribute<number | null>('weekId');
  homeTeamId   = Model.attribute<number>('homeTeamId');
  awayTeamId   = Model.attribute<number>('awayTeamId');
  cfbdId       = Model.attribute<number | null>('cfbdId');
  neutralSite  = Model.attribute<boolean>('neutralSite');
  matchDate    = Model.attribute<string>('matchDate');
  cutoffDate   = Model.attribute<string>('cutoffDate');
  status       = Model.attribute<string>('status');
  homeScore    = Model.attribute<number | null>('homeScore');
  awayScore    = Model.attribute<number | null>('awayScore');
  result       = Model.attribute<string | null>('result');
  // Where each side was ranked going INTO this game; null when unranked.
  homeRank     = Model.attribute<number | null>('homeRank');
  awayRank     = Model.attribute<number | null>('awayRank');
  canPick      = Model.attribute<boolean>('canPick');

  week     = Model.hasOne<Week | false>('week');
  homeTeam = Model.hasOne<Team | false>('homeTeam');
  awayTeam = Model.hasOne<Team | false>('awayTeam');
}
