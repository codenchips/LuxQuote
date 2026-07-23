document.addEventListener('click', async (event) => {
    const link = event.target.closest?.('a[data-pdf-generation]');

    if (!link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    event.preventDefault();

    const modal = window.luxQuotePdfGenerationModal ??= createPdfGenerationModal();
    const startedAt = Date.now();
    const title = link.dataset.pdfTitle || 'Generating PDF';
    const message = link.dataset.pdfMessage || 'PDF generation is in progress. This can take a while.';
    const openInNewTab = link.target === '_blank';
    const progressToken = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const pdfUrl = new URL(link.href);

    pdfUrl.searchParams.set('pdf_progress_token', progressToken);
    pdfUrl.searchParams.set('pdf_delivery_link', '1');

    modal.open(title, message);
    const fallbackProgress = startFallbackProgress(modal);
    const progressPoll = startProgressPolling(progressToken, modal);

    try {
        const response = await fetch(pdfUrl, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`PDF generation failed with status ${response.status}.`);
        }

        const preparedPdf = await response.json();
        const filename = preparedPdf.filename || link.dataset.pdfFilename || 'luxquote.pdf';
        const downloadUrl = preparedPdf.url;

        if (!downloadUrl) {
            throw new Error('The PDF was generated but no download URL was returned.');
        }

        await finishProgress(modal, Date.now() - startedAt);

        if (openInNewTab) {
            window.open(downloadUrl, '_blank', 'noopener');
        } else {
            downloadPreparedPdf(downloadUrl, filename);
        }

        modal.update(100, 'PDF ready.');
        await sleep(300);
        modal.close();
        showPdfNotification(preparedPdf.notification);
    } catch (error) {
        modal.fail(error instanceof Error ? error.message : 'The PDF could not be generated.');
    } finally {
        clearInterval(fallbackProgress);
        clearInterval(progressPoll);
    }
});

function showPdfNotification(notification) {
    if (!notification?.title || typeof window.FilamentNotification !== 'function') {
        return;
    }

    const card = new window.FilamentNotification()
        .title(notification.title);

    if (notification.body) {
        card.body(notification.body);
    }

    if (['success', 'warning', 'danger', 'info'].includes(notification.status)) {
        card[notification.status]();
    }

    card.send();
}

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
    let currentPercent = 8;
    let liveProgress = false;

    close.addEventListener('click', () => wrapper.classList.add('hidden'));

    return {
        open(nextTitle, nextMessage) {
            title.textContent = nextTitle;
            message.textContent = nextMessage;
            currentPercent = 0;
            liveProgress = false;
            this.update(8, nextMessage, { force: true });
            close.classList.add('hidden');
            spinner.classList.remove('hidden');
            wrapper.classList.remove('hidden');
            wrapper.classList.add('flex');
        },
        update(nextPercent, nextMessage, options = {}) {
            const requestedValue = Math.max(0, Math.min(100, Number(nextPercent) || 0));
            const value = options.force ? requestedValue : Math.max(currentPercent, requestedValue);

            currentPercent = value;

            if (options.live) {
                liveProgress = true;
            }

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
        hasLiveProgress() {
            return liveProgress;
        },
        fail(nextMessage) {
            title.textContent = 'PDF generation failed';
            message.textContent = nextMessage;
            bar.style.width = '100%';
            percent.textContent = '';
            currentPercent = 100;
            close.classList.remove('hidden');
            spinner.classList.add('hidden');
        },
    };
}

function downloadPreparedPdf(downloadUrl, filename) {
    const anchor = document.createElement('a');
    anchor.href = downloadUrl;
    anchor.download = filename;
    anchor.className = 'hidden';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
}

function startFallbackProgress(modal) {
    let progress = 8;
    const stages = [
        [18, 'Preparing PDF...'],
        [34, 'Rendering document pages...'],
        [52, 'Checking generated file...'],
        [68, 'Assembling download...'],
        [82, 'Nearly ready...'],
    ];
    let stage = 0;

    return setInterval(() => {
        if (modal.hasLiveProgress()) {
            return;
        }

        const [target, message] = stages[stage] ?? [88, 'Finalising PDF...'];

        progress = Math.min(progress + Math.max(1, (target - progress) * 0.22), target);
        modal.update(progress, message);

        if (progress >= target - 0.5 && stage < stages.length - 1) {
            stage += 1;
        }
    }, 700);
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
                const hasLiveProgress = progress.percent > 8 || progress.complete || progress.message !== 'Starting PDF generation...';

                modal.update(progress.percent, progress.message, { live: hasLiveProgress });
            }
        } catch {
            // Keep the fallback bar moving if polling fails.
        }
    }, 700);
}

async function finishProgress(modal, elapsedMs) {
    const animationStartedAt = Date.now();
    const stages = [
        [42, 'Rendering PDF pages...', 220],
        [66, 'Assembling PDF...', 220],
        [86, 'Finalising download...', 260],
        [96, 'Opening PDF...', 180],
    ];
    const minimumVisibleMs = 1400;

    for (const [percent, message, delayMs] of stages) {
        modal.update(percent, message);
        await sleep(delayMs);
    }

    const totalVisibleMs = elapsedMs + (Date.now() - animationStartedAt);

    if (totalVisibleMs < minimumVisibleMs) {
        await sleep(minimumVisibleMs - totalVisibleMs);
    }
}

function sleep(milliseconds) {
    return new Promise((resolve) => {
        setTimeout(resolve, milliseconds);
    });
}
