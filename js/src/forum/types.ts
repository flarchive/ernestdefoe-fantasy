/** What the fantasy API sends the draft and lineup screens. */

export interface TeamRef {
  id: number;
  name: string;
  conference?: string;
  logo: string;
}

export interface DraftFranchise {
  id: number;
  name: string;
  member: string;
  picks: number;
}

export interface DraftClock {
  franchise: number;
  round: number;
  pick: number;
  overall: number;
}

export interface DraftPick {
  overall: number;
  franchise: number;
  team: TeamRef;
}

export interface AvailableTeam {
  id: number;
  name: string;
  conference: string | null;
  logo_path: string | null;
}

export interface DraftData {
  league: { name: string; slug: string; status: string; rosterSize: number; starters: number };
  order: DraftFranchise[];
  clock: DraftClock | null;
  history: DraftPick[];
  available: AvailableTeam[];
  viewer: { franchise: number | null; manager: boolean; canPick: boolean };
}

export interface LineupGame {
  home: boolean;
  opponent: { name: string; logo: string };
  kickoff: string | null;
  status: string;
  score: [number, number] | null;
  locked: boolean;
  beforeDraft: boolean;
}

export interface LineupTeam extends TeamRef {
  starting: boolean;
  game: LineupGame | null;
}

export interface LineupData {
  league: { name: string; slug: string; status: string; starters: number };
  franchise: { id: number; name: string };
  weeks: { id: number; name: string }[];
  week: number;
  teams: LineupTeam[];
}
