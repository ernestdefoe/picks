import Model from 'flarum/common/Model';

export default class Season extends Model {
  name = Model.attribute<string>('name');
  slug = Model.attribute<string>('slug');
  year = Model.attribute<number>('year');
  startDate = Model.attribute<string | null>('startDate');
  endDate = Model.attribute<string | null>('endDate');

  /**
   * Which competition this season is.
   *
   * 🚨 The one control the whole family reads. Game Day takes a recap's
   * vocabulary from it, Fantasy takes its scoring defaults from it, and the
   * sync decides which provider to ask. The server has exposed all three of
   * these since leagues landed and the client read none of them — so every
   * screen showed a season with no competition on it and no way to set one.
   */
  league = Model.attribute<string>('league');

  /** The recap vocabulary — `gridiron`, `soccer`, `hardwood`, `diamond`, `ice`. */
  sport = Model.attribute<string>('sport');

  /** The league's display name, resolved server-side against the registry. */
  leagueName = Model.attribute<string>('leagueName');
}
