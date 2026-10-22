export type User = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    two_factor_enabled: boolean;
    must_change_password: boolean;
};

export type Auth = {
    user: User;
    can: Record<string, true>;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
