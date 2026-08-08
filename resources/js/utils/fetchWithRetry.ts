const MAX_RETRIES = 1;

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

export async function fetchWithRetry<T>(
    fn: (...args: unknown[]) => Promise<T>,
    ...args: unknown[]
): Promise<T> {
    let attempts = 0;

    while (attempts < MAX_RETRIES) {
        try {
            return await fn(...args);
        } catch (error) {
            attempts++;

            if (attempts >= MAX_RETRIES) {
                throw error;
            }

            await sleep(attempts * 1000);
        }
    }

    throw new Error('fetchWithRetry failed');
}
