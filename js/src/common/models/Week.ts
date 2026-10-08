import Model from 'flarum/common/Model';
import Season from './Season';

export default class Week extends Model {
  name = Model.attribute<string>('name');
  seasonId = Model.attribute<number | null>('seasonId');
  isOpen = Model.attribute<boolean>('isOpen');
  isCurrent = Model.attribute<boolean>('isCurrent');
  weekNumber = Model.attribute<number | null>('weekNumber');
  seasonType = Model.attribute<string>('seasonType');
  startDate = Model.attribute<string | null>('startDate');
  endDate = Model.attribute<string | null>('endDate');
  season = Model.hasOne<Season>('season');
}
