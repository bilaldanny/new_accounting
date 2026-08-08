export interface DebouncedFunction<T extends (...args: never[]) => unknown> {
    (...args: Parameters<T>): void;
    cancel: () => void;
    flush: () => void;
}

export default function debounce<T extends (...args: never[]) => unknown>(
    fn: T,
    wait = 300,
): DebouncedFunction<T> {
    let timeout: ReturnType<typeof setTimeout> | null = null;
    let lastArgs: Parameters<T> | null = null;

    const debounced = (...args: Parameters<T>) => {
        lastArgs = args;

        if (timeout) {
            clearTimeout(timeout);
        }

        timeout = setTimeout(() => {
            timeout = null;
            lastArgs = null;
            fn(...args);
        }, wait);
    };

    debounced.cancel = () => {
        if (timeout) {
            clearTimeout(timeout);
            timeout = null;
        }
        lastArgs = null;
    };

    debounced.flush = () => {
        if (!timeout || lastArgs === null) {
            return;
        }

        clearTimeout(timeout);
        timeout = null;
        const args = lastArgs;
        lastArgs = null;
        fn(...args);
    };

    return debounced;
}
