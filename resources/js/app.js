document.addEventListener('click', async (event) => {
    const link = event.target.closest?.('a[data-pdf-generation]');

    if (!link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    event.preventDefault();

    const modal = window.luxQuotePdfGenerationModal ??= createPdfGenerationModal();
    const title = link.dataset.pdfTitle || 'Generating PDF';
    const message = link.dataset.pdfMessage || 'PDF generation is in progress. This can take a while.';
    const openInNewTab = link.target === '_blank';
    const progressToken = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const pdfUrl = new URL(link.href);

    pdfUrl.searchParams.set('pdf_progress_token', progressToken);

    modal.open(title, message);
    const fallbackProgress = startFallbackProgress(modal);
    const progressPoll = startProgressPolling(progressToken, modal);

    try {
        const response = await fetch(pdfUrl, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/pdf,*/*',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`PDF generation failed with status ${response.status}.`);
        }

        modal.update(96, 'Opening PDF...');

        const blob = await response.blob();
        const filename = filenameFromResponse(response) || link.dataset.pdfFilename || 'luxquote.pdf';
        const objectUrl = URL.createObjectURL(blob);

        if (openInNewTab) {
            const opened = window.open(objectUrl, '_blank', 'noopener');

            if (!opened) {
                downloadBlob(objectUrl, filename);
            }
        } else {
            downloadBlob(objectUrl, filename);
        }

        setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000);
        modal.update(100, 'PDF ready.');
        modal.close();
    } catch (error) {
        modal.fail(error instanceof Error ? error.message : 'The PDF could not be generated.');
    } finally {
        clearInterval(fallbackProgress);
        clearInterval(progressPoll);
    }
});

function createPdfGenerationModal() {
    const wrapper = document.createElement('div');
    wrapper.className = 'fixed inset-0 z-[100000] hidden items-center justify-center bg-gray-950/75 px-4';
    wrapper.innerHTML = `
        <div class="w-full max-w-md rounded-xl border border-white/10 bg-white p-6 shadow-2xl dark:bg-gray-900">
            <div class="flex items-start gap-4">
                <div class="mt-1 h-9 w-9 shrink-0 animate-spin rounded-full border-4 border-orange-200 border-t-orange-500"></div>
                <div class="min-w-0">
                    <h2 data-pdf-modal-title class="text-base font-semibold text-gray-950 dark:text-white"></h2>
                    <p data-pdf-modal-message class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300"></p>
                    <div class="mt-5 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                        <div data-pdf-modal-bar class="h-full rounded-full bg-orange-500 transition-all duration-500" style="width: 8%"></div>
                    </div>
                    <div data-pdf-modal-percent class="mt-2 text-xs font-medium text-gray-500 dark:text-gray-400">8%</div>
                    <button type="button" data-pdf-modal-close class="mt-5 hidden h-9 rounded-md border border-gray-300 px-3 text-sm font-semibold text-gray-700 dark:border-white/10 dark:text-gray-200">
                        Close
                    </button>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(wrapper);

    const title = wrapper.querySelector('[data-pdf-modal-title]');
    const message = wrapper.querySelector('[data-pdf-modal-message]');
    const close = wrapper.querySelector('[data-pdf-modal-close]');
    const spinner = wrapper.querySelector('.animate-spin');
    const bar = wrapper.querySelector('[data-pdf-modal-bar]');
    const percent = wrapper.querySelector('[data-pdf-modal-percent]');

    close.addEventListener('click', () => wrapper.classList.add('hidden'));

    return {
        open(nextTitle, nextMessage) {
            title.textContent = nextTitle;
            message.textContent = nextMessage;
            this.update(8, nextMessage);
            close.classList.add('hidden');
            spinner.classList.remove('hidden');
            wrapper.classList.remove('hidden');
            wrapper.classList.add('flex');
        },
        update(nextPercent, nextMessage) {
            const value = Math.max(0, Math.min(100, Number(nextPercent) || 0));

            bar.style.width = `${value}%`;
            percent.textContent = `${Math.round(value)}%`;

            if (nextMessage) {
                message.textContent = nextMessage;
            }
        },
        close() {
            wrapper.classList.add('hidden');
            wrapper.classList.remove('flex');
        },
        fail(nextMessage) {
            title.textContent = 'PDF generation failed';
            message.textContent = nextMessage;
            bar.style.width = '100%';
            percent.textContent = '';
            close.classList.remove('hidden');
            spinner.classList.add('hidden');
        },
    };
}

function filenameFromResponse(response) {
    const header = response.headers.get('Content-Disposition') || '';
    const utf8Match = header.match(/filename\*=UTF-8''([^;]+)/i);

    if (utf8Match) {
        return decodeURIComponent(utf8Match[1].replaceAll('"', ''));
    }

    const match = header.match(/filename="?([^";]+)"?/i);

    return match?.[1] ?? null;
}

function downloadBlob(objectUrl, filename) {
    const anchor = document.createElement('a');
    anchor.href = objectUrl;
    anchor.download = filename;
    anchor.className = 'hidden';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
}

function startFallbackProgress(modal) {
    let progress = 8;

    return setInterval(() => {
        progress = Math.min(progress + Math.max(1, (84 - progress) * 0.08), 84);
        modal.update(progress);
    }, 900);
}

function startProgressPolling(token, modal) {
    if (!token) {
        return null;
    }

    return setInterval(async () => {
        try {
            const response = await fetch(`/pdf-progress/${encodeURIComponent(token)}`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                return;
            }

            const progress = await response.json();

            if (typeof progress.percent !== 'undefined') {
                modal.update(progress.percent, progress.message);
            }
        } catch {
            // Keep the fallback bar moving if polling fails.
        }
    }, 700);
}
