export type SubjectStatus = 'approved' | 'in_progress' | 'planned' | 'unassigned' | 'withdrawn';
export type Opportunity = 'ordinary' | 'repetition' | 'special' | 'withdrawn' | null;
export type Severity = 'danger' | 'warning' | 'info';

export type PlannerSubject = {
  code: string;
  name: string;
  credits: number;
  semester: number;
  difficulty: number;
  specialty: boolean;
  min_percent: number | null;
  status: SubjectStatus;
  opportunity: Opportunity;
  approved_via: 'ordinary' | 'repetition' | 'special' | null;
  failed_attempts: number;
  forced_fail: boolean;
  period_id: string | null;
  planned_kind: 'ordinary' | 'summer' | null;
  planned_index: number | null;
  delay: number;
  deferred: boolean;
  beyond_limit: boolean;
  reason: string | null;
};

export type PlannerRelation = { source: string; target: string; type: 'prerequisite' | 'corequisite' };

export type PlannerPeriod = {
  id: string;
  kind: 'ordinary' | 'summer' | 'leave';
  number: number;
  index: number | null;
  label: string;
  calendar: string | null;
  calendar_code: string | null;
  subjects: Array<{ code: string; name: string; credits: number; difficulty: number }>;
  total_credits: number;
  min_credits: number | null;
  max_credits: number | null;
  situation: string | null;
  beyond_limit: boolean;
  needs_group: boolean;
  note: string | null;
};

export type PlannerWarning = { severity: Severity; message: string; subjects: string[] };

export type PlannerMilestone = {
  code: string;
  name: string;
  status: 'done' | 'eligible' | 'future' | 'blocked' | 'pending';
  credits_required: number;
  earliest_number: number | null;
  earliest_index: number | null;
  earliest_label: string | null;
  detail: string;
};

export type PlannerSummary = {
  catalog_total: number;
  approved: number;
  approved_via_repetition: number;
  in_progress: number;
  pending: number;
  credits_approved: number;
  credits_total: number;
  percent: number;
  current_index: number;
  current_period: string | null;
  current_label: string | null;
  max_semesters: number;
  planned_semesters: number;
  last_index: number;
  ordinary_periods: number;
  fits_limit: boolean;
  unassigned: number;
  situation: string;
  load: { min: number | null; max: number | null; only_special: boolean };
};

export type PlannerResult = {
  summary: PlannerSummary;
  subjects: PlannerSubject[];
  relations: PlannerRelation[];
  periods: PlannerPeriod[];
  milestones: PlannerMilestone[];
  warnings: PlannerWarning[];
  comparison: {
    base: { last_index: number; ordinary_periods: number; fits: boolean };
    scenario: { last_index: number; ordinary_periods: number; fits: boolean };
    delta: number;
  } | null;
  options: {
    summer_candidates: Array<{ code: string; name: string; credits: number }>;
    deferrable: Array<{ code: string; name: string; credits: number; locked: boolean }>;
    failable: Array<{ code: string; name: string; opportunity: Opportunity }>;
    periods: Array<{ number: number; label: string; summer_ok: boolean }>;
  };
};

export type PlannerInput = {
  summer: Array<{ code: string; after: number }>;
  deferred: Array<{ code: string; periods: number }>;
  leave: number[];
  fail: string[];
};

export type PlannerActionState = { data?: PlannerResult; error?: string };