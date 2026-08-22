/**
 * Open Laravel Filemanager for a single image field (Vueform TextElement + addon button).
 * Uses delegated click handling so it works inside modals mounted after page load.
 */
export function openLfmImagePicker(event: MouseEvent, appUrl: string): void {
    event.preventDefault();

    const button = event.currentTarget as HTMLButtonElement;
    const routePrefix = `${appUrl.replace(/\/$/, '')}/laravel-filemanager`;
    const baseUrl = appUrl.replace(/\/$/, '');
    const inputId = button.getAttribute('data-input');
    const fieldName = button.getAttribute('data-field-name');
    const previewId = button.getAttribute('data-preview');

    if (!inputId && !fieldName) {
        return;
    }

    const scope = button.closest('form') ?? button.closest('.vf-form') ?? document;

    let targetInput =
        (inputId ? (document.getElementById(inputId) as HTMLInputElement | null) : null)
        ?? (fieldName ? (scope.querySelector(`input[name="${fieldName}"]`) as HTMLInputElement | null) : null);
    const targetPreview = previewId ? document.getElementById(previewId) : null;

    if (!targetInput) {
        console.warn(`lfm: input element with id="${inputId}" not found`);

        return;
    }

    window.open(`${routePrefix}?type=image`, 'FileManager', 'width=900,height=600');

    window.SetUrl = function (items: Array<{ url: string; thumb_url?: string }>) {
        const filePath = items
            .map((item) => {
                let url = item.url;

                if (baseUrl && url.startsWith(baseUrl)) {
                    url = url.slice(baseUrl.length);
                }

                return url;
            })
            .filter((url) => url !== '');

        const merged = filePath.join(',');
        targetInput.value = merged;
        targetInput.dispatchEvent(new Event('input', { bubbles: true }));
        targetInput.dispatchEvent(new Event('change', { bubbles: true }));

        if (!targetPreview) {
            return;
        }

        targetPreview.innerHTML = '';

        items.forEach((item) => {
            const imageUrl = item.thumb_url ?? item.url;
            const img = document.createElement('img');
            img.style.height = '3rem';
            img.style.marginTop = '0.375rem';
            img.style.borderRadius = '0.25rem';
            img.style.objectFit = 'contain';
            img.src = imageUrl;
            targetPreview.appendChild(img);
        });

        targetPreview.dispatchEvent(new Event('change', { bubbles: true }));
    };
}

export function openLfmImagePickerCallback(
    event: Event,
    appUrl: string,
    onSelect: (path: string) => void,
): void {
    event.preventDefault();

    const routePrefix = `${appUrl.replace(/\/$/, '')}/laravel-filemanager`;
    const baseUrl = appUrl.replace(/\/$/, '');

    window.open(`${routePrefix}?type=image`, 'FileManager', 'width=900,height=600');

    window.SetUrl = function (items: Array<{ url: string; thumb_url?: string }>) {
        const path = items
            .map((item) => {
                let url = item.url;

                if (baseUrl && url.startsWith(baseUrl)) {
                    url = url.slice(baseUrl.length);
                }

                return url;
            })
            .find((url) => url !== '');

        if (path) {
            onSelect(path);
        }
    };
}
