export * from './auth';

export type Option = {
    value: string | number;
    label: string;
    hint?: string | null;
    [key: string]: unknown;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};

export type Toast = { type: 'success' | 'error' | 'warning' | 'info'; message: string };

export type Appearance = 'light' | 'dark' | 'system';

export type ResolvedAppearance = 'light' | 'dark';
