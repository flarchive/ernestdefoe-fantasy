import Model from 'flarum/common/Model';

/** A fantasy league (`fantasy-leagues`). `page` is only sent when one league is shown. */
export default class League extends Model {
  name = Model.attribute<string>('name');
  description = Model.attribute<string | null>('description');
  slug = Model.attribute<string>('slug');
  status = Model.attribute<string>('status');
  sport = Model.attribute<string>('sport');
  franchiseCount = Model.attribute<number>('franchiseCount');
  maxFranchises = Model.attribute<number>('maxFranchises');
  rosterSize = Model.attribute<number>('rosterSize');
  starters = Model.attribute<number>('starters');
  page = Model.attribute<LeaguePageData | undefined>('page');
}

export interface LeaguePageData {
  rules: Record<string, number | null>;
  table: {
    id: number;
    name: string;
    member: string;
    points: number;
    wins: number;
    games: number;
    roster: { id: number; name: string; logo: string }[];
  }[];
  viewer: { franchise: number | null; manager: boolean; canJoin: boolean };
}
