export type User = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    /** A client's own person, linked to their client (the portal shows their figures). */
    client?: boolean;
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
