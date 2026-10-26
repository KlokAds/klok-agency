/* Shapes the dashboard gets from DashboardController (cents / poisha). */

export type Platform = {
    id: number;
    code: string;
    name: string;
    spent: number;
    charged: number;
    due: number;
};

export type Summary = {
    card: number;
    holding: number;
    platforms: Platform[];
    platform_due: number;
    meta_live?: {
        total: number;
        accounts: {
            id: number;
            name: string;
            unbilled: number;
            card: string | null;
        }[];
        read_at: string;
    } | null;
    card_after: number;
    business_usd: number;
    usd_check: {
        bought: number;
        consumed: number;
        remaining: number;
        ok: boolean;
    };
    overdrawn: boolean;
    bdt: number;
    usd_at_cost: number;
    receivable_usd: number;
    receivable_cost: number;
    capital: number;
    drawings: number;
    owner_due: number;
    unearned: number;
    assets: number;
    equity: number;
    equity_ok: boolean;
    margin: number;
    net: number;
    review_count: number;
    undated_count: number;
};

export type Fx = {
    remaining: number;
    daily_usd: number;
    days: number | null;
    current: { number: number; rate: number; left: number; usd: number } | null;
    queued: number;
    queued_usd: number;
    low: boolean;
};

export type ClientRow = {
    id: number;
    name: string;
    active: boolean;
    credit_usd: number;
    credit_bdt: number;
    spent_usd: number;
    spent_bdt: number;
    balance_usd: number;
    pending_cost: number;
    unearned: number;
    unbilled_usd: number;
    daily_usd: number;
    days_left: number | null;
};

export type Wallets = {
    places: { key: string; label: string; balance: number }[];
    untagged: number;
    untagged_count: number;
    total: number;
};

export type TrendRow = {
    month: string;
    label: string;
    revenue: number;
    ad_cost: number;
    office: number;
    net: number;
    in_period: boolean;
};
