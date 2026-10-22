/** Small JSON fetch for the few non-Inertia calls (2FA QR, codes). */
function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(String(response.status));
    }

    return (await response.json()) as T;
}
