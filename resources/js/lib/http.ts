/** Small JSON fetch for the few non-Inertia calls (2FA QR, codes). */
function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(String(response.status));
    }

    return (await response.json()) as T;
}

export class HttpError extends Error {
    constructor(
        public status: number,
        public errors: Record<string, string[]> = {},
        message = String(status),
    ) {
        super(message);
    }
}

/** JSON POST with the session's CSRF token; a 422 carries its field errors. */
export async function postJson<T>(
    url: string,
    body: Record<string, unknown> = {},
): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const data = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        throw new HttpError(response.status, data.errors ?? {}, data.message);
    }

    return (await response.json()) as T;
}
