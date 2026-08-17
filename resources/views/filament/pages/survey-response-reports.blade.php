<style>
    .ena-report-page {
        --ena-surface: #ffffff;
        --ena-surface-muted: #f4f8f5;
        --ena-ink: #17231e;
        --ena-muted: #5c6c63;
        --ena-border: #d6e1db;
        --ena-line: #e6ece8;
        --ena-forest: #123b2b;
        --ena-forest-deep: #09291d;
        --ena-forest-soft: #e2eee7;
        --ena-amber: #d7a42e;
        --ena-amber-soft: #f7edcf;
        --ena-danger: #b42318;
        display: grid;
        gap: 1.5rem;
        color: var(--ena-ink);
    }

    .dark .ena-report-page {
        --ena-surface: #111923;
        --ena-surface-muted: #151f2b;
        --ena-ink: #f1f7f3;
        --ena-muted: #a9b6af;
        --ena-border: #2a3744;
        --ena-line: #24313d;
        --ena-forest: #174c37;
        --ena-forest-deep: #0b2f22;
        --ena-forest-soft: #17382b;
        --ena-amber-soft: #40351b;
        --ena-danger: #ff8a80;
    }

    .ena-scope-card,
    .ena-jobs-card,
    .ena-analysis-empty {
        overflow: hidden;
        border: 1px solid var(--ena-border);
        border-radius: 1rem;
        background: var(--ena-surface);
        box-shadow: 0 16px 38px rgba(21, 48, 36, 0.07);
    }

    .dark .ena-scope-card,
    .dark .ena-jobs-card,
    .dark .ena-analysis-empty {
        box-shadow: 0 18px 45px rgba(0, 0, 0, 0.22);
    }

    .ena-scope-grid {
        display: grid;
        min-width: 0;
    }

    .ena-filter-pane {
        min-width: 0;
        padding: 1.5rem;
        background:
            radial-gradient(circle at 8% 0%, rgba(39, 122, 83, 0.08), transparent 30%),
            var(--ena-surface);
    }

    .ena-report-filter .fi-section {
        border-radius: 0;
        background: transparent !important;
        box-shadow: none !important;
    }

    .ena-report-filter .fi-section-header {
        padding: 0 0 1rem !important;
    }

    .ena-report-filter .fi-section-content-ctn {
        border-color: var(--ena-line) !important;
    }

    .ena-report-filter .fi-section-content {
        padding: 1.25rem 0 0 !important;
    }

    .ena-report-filter .fi-section-header-heading {
        color: var(--ena-ink) !important;
        font-size: 1rem;
    }

    .ena-report-filter .fi-section-header-description {
        color: var(--ena-muted) !important;
    }

    .ena-filter-note {
        display: flex;
        align-items: flex-start;
        gap: 0.65rem;
        margin-top: 1.1rem;
        padding: 0.8rem 0.9rem;
        border: 1px solid var(--ena-border);
        border-radius: 0.75rem;
        background: var(--ena-surface-muted);
        color: var(--ena-muted);
        font-size: 0.82rem;
        line-height: 1.45;
    }

    .ena-filter-note strong {
        color: var(--ena-ink);
    }

    .ena-coverage-pane {
        position: relative;
        isolation: isolate;
        padding: 1.6rem;
        color: #ffffff;
        background:
            linear-gradient(145deg, rgba(255, 255, 255, 0.04), transparent 55%),
            linear-gradient(145deg, var(--ena-forest), var(--ena-forest-deep));
    }

    .ena-coverage-pane::after {
        position: absolute;
        right: -5rem;
        bottom: -5rem;
        z-index: -1;
        width: 13rem;
        height: 13rem;
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 999px;
        box-shadow: 0 0 0 2.5rem rgba(255, 255, 255, 0.025), 0 0 0 5rem rgba(255, 255, 255, 0.02);
        content: '';
    }

    .ena-coverage-kicker,
    .ena-jobs-kicker,
    .ena-section-kicker {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
    }

    .ena-coverage-kicker,
    .ena-jobs-kicker {
        color: #f2ca65;
    }

    .ena-coverage-title {
        margin-top: 0.75rem;
        color: #ffffff;
        font-size: 1.3rem;
        font-weight: 700;
        line-height: 1.2;
    }

    .ena-coverage-copy {
        max-width: 34rem;
        margin-top: 0.55rem;
        color: #d8e9e0;
        font-size: 0.86rem;
        line-height: 1.55;
    }

    .ena-coverage-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem;
        margin-top: 1.25rem;
    }

    .ena-coverage-item {
        min-width: 0;
        padding: 0.75rem;
        border: 1px solid rgba(255, 255, 255, 0.13);
        border-radius: 0.7rem;
        background: rgba(255, 255, 255, 0.06);
        backdrop-filter: blur(6px);
    }

    .ena-coverage-item dt {
        color: #a9cbbb;
        font-size: 0.69rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .ena-coverage-item dd {
        margin-top: 0.22rem;
        overflow-wrap: anywhere;
        color: #ffffff;
        font-size: 0.82rem;
        font-weight: 650;
        line-height: 1.3;
    }

    .ena-coverage-footnote {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        margin-top: 1rem;
        color: #d8e9e0;
        font-size: 0.76rem;
    }

    .ena-section-heading {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        margin-bottom: 0.8rem;
    }

    .ena-section-number {
        display: grid;
        flex: 0 0 auto;
        width: 2rem;
        height: 2rem;
        place-items: center;
        border: 1px solid var(--ena-border);
        border-radius: 999px;
        background: var(--ena-forest-soft);
        color: var(--ena-forest);
        font-size: 0.7rem;
        font-weight: 800;
    }

    .dark .ena-section-number {
        color: #8ed3ae;
    }

    .ena-section-kicker {
        color: #277a53;
    }

    .dark .ena-section-kicker {
        color: #67ce98;
    }

    .ena-section-title {
        margin-top: 0.16rem;
        color: var(--ena-ink);
        font-size: 1.1rem;
        font-weight: 700;
    }

    .ena-analysis-empty {
        display: flex;
        align-items: center;
        gap: 1rem;
        min-height: 7.5rem;
        padding: 1.35rem;
        background:
            linear-gradient(120deg, var(--ena-surface), var(--ena-surface-muted));
    }

    .ena-empty-icon {
        display: grid;
        flex: 0 0 auto;
        width: 3.25rem;
        height: 3.25rem;
        place-items: center;
        border-radius: 0.85rem;
        background: var(--ena-forest-soft);
        color: #277a53;
    }

    .dark .ena-empty-icon {
        color: #79d6a3;
    }

    .ena-empty-title {
        color: var(--ena-ink);
        font-size: 0.95rem;
        font-weight: 700;
    }

    .ena-empty-copy {
        margin-top: 0.28rem;
        color: var(--ena-muted);
        font-size: 0.84rem;
        line-height: 1.5;
    }

    .ena-jobs-card {
        background: var(--ena-surface);
    }

    .ena-jobs-header {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.25rem 1.4rem;
        color: #ffffff;
        background:
            linear-gradient(105deg, rgba(255, 255, 255, 0.035), transparent 58%),
            linear-gradient(115deg, var(--ena-forest-deep), var(--ena-forest));
    }

    .ena-jobs-title {
        margin-top: 0.3rem;
        color: #ffffff;
        font-size: 1.15rem;
        font-weight: 700;
    }

    .ena-jobs-copy {
        margin-top: 0.24rem;
        color: #cfe2d8;
        font-size: 0.8rem;
    }

    .ena-job-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.4rem;
        border-top: 1px solid var(--ena-line);
    }

    .ena-job-main {
        min-width: 0;
    }

    .ena-job-title-line,
    .ena-job-meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.45rem 0.8rem;
    }

    .ena-job-name {
        max-width: min(56rem, 72vw);
        overflow: hidden;
        color: var(--ena-ink);
        font-size: 0.88rem;
        font-weight: 650;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ena-job-meta {
        margin-top: 0.35rem;
        color: var(--ena-muted);
        font-size: 0.72rem;
    }

    .ena-job-error {
        margin-top: 0.45rem;
        color: var(--ena-danger);
        font-size: 0.78rem;
    }

    .ena-job-action {
        flex: 0 0 auto;
    }

    .ena-job-loading {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--ena-muted);
        font-size: 0.8rem;
    }

    .ena-empty-jobs {
        padding: 2.5rem 1.25rem;
        text-align: center;
    }

    .ena-empty-jobs .ena-report-icon-large {
        margin: 0 auto;
        color: var(--ena-muted);
    }

    .ena-empty-jobs-title {
        margin-top: 0.7rem;
        color: var(--ena-ink);
        font-size: 0.9rem;
        font-weight: 700;
    }

    .ena-empty-jobs-copy {
        margin-top: 0.25rem;
        color: var(--ena-muted);
        font-size: 0.8rem;
    }

    .ena-report-icon {
        width: 1rem;
        height: 1rem;
    }

    .ena-report-icon-large {
        width: 2.4rem;
        height: 2.4rem;
    }

    .ena-loading-icon {
        width: 1.15rem;
        height: 1.15rem;
    }

    @media (min-width: 1100px) {
        .ena-scope-grid {
            grid-template-columns: minmax(0, 1fr) 22rem;
        }

        .ena-coverage-pane {
            border-left: 1px solid rgba(255, 255, 255, 0.1);
        }
    }

    @media (max-width: 1099px) {
        .ena-coverage-pane {
            border-top: 1px solid var(--ena-border);
        }
    }

    @media (max-width: 640px) {
        .ena-filter-pane,
        .ena-coverage-pane {
            padding: 1.1rem;
        }

        .ena-job-row {
            align-items: flex-start;
            flex-direction: column;
            padding: 1rem 1.1rem;
        }

        .ena-job-name {
            max-width: 84vw;
        }

        .ena-analysis-empty {
            align-items: flex-start;
        }
    }

    @media (prefers-reduced-motion: no-preference) {
        .ena-scope-card,
        .ena-analysis-section,
        .ena-jobs-card {
            animation: ena-report-rise 360ms ease-out both;
        }

        .ena-analysis-section {
            animation-delay: 60ms;
        }

        .ena-jobs-card {
            animation-delay: 120ms;
        }

        @keyframes ena-report-rise {
            from {
                opacity: 0;
                transform: translateY(6px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    }
</style>

<x-filament-panels::page>
    <div class="ena-report-page">
        <section class="ena-scope-card">
            <div class="ena-scope-grid">
                <div class="ena-filter-pane">
                    <div class="ena-report-filter">
                        {{ $this->filtersForm }}
                    </div>

                    <div class="ena-filter-note">
                        <x-heroicon-o-information-circle class="ena-report-icon" />
                        <span><strong>Question is optional.</strong> It changes the live analysis below, while the Excel workbook always exports every question.</span>
                    </div>
                </div>

                <aside class="ena-coverage-pane">
                    <div class="ena-coverage-kicker">
                        <x-heroicon-o-shield-check class="ena-report-icon" />
                        Locked workbook coverage
                    </div>
                    <h2 class="ena-coverage-title">One survey. One group. The complete record.</h2>
                    <p class="ena-coverage-copy">Every group member is included, from completed respondents to people who never received or started the survey.</p>

                    <dl class="ena-coverage-grid">
                        <div class="ena-coverage-item">
                            <dt>Dates</dt>
                            <dd>All available</dd>
                        </div>
                        <div class="ena-coverage-item">
                            <dt>Direction</dt>
                            <dd>Credits utilized</dd>
                        </div>
                        <div class="ena-coverage-item">
                            <dt>Activity</dt>
                            <dd>Inbound + outbound</dd>
                        </div>
                        <div class="ena-coverage-item">
                            <dt>Channel</dt>
                            <dd>SMS only</dd>
                        </div>
                    </dl>

                    <div class="ena-coverage-footnote">
                        <x-heroicon-o-table-cells class="ena-report-icon" />
                        All survey questions are exported automatically
                    </div>
                </aside>
            </div>
        </section>

        <section class="ena-analysis-section">
            <div class="ena-section-heading">
                <span class="ena-section-number">01</span>
                <div>
                    <p class="ena-section-kicker">Live analysis</p>
                    <h2 class="ena-section-title">Survey response performance</h2>
                </div>
            </div>

            @if (filled($this->filters['question_id'] ?? null))
                <x-filament-widgets::widgets
                    :widgets="$this->getResponseWidgets()"
                    :columns="1"
                />
            @else
                <div class="ena-analysis-empty">
                    <div class="ena-empty-icon">
                        <x-heroicon-o-chart-bar-square class="ena-report-icon-large" />
                    </div>
                    <div>
                        <p class="ena-empty-title">Choose a question to inspect response patterns</p>
                        <p class="ena-empty-copy">Select an on-screen question above to see answer totals and distribution. You can still generate the full workbook without selecting one.</p>
                    </div>
                </div>
            @endif
        </section>

        <section class="ena-jobs-card" wire:poll.10s>
            <div class="ena-jobs-header">
                <div>
                    <div class="ena-jobs-kicker">
                        <x-heroicon-o-clock class="ena-report-icon" />
                        Private background exports
                    </div>
                    <h2 class="ena-jobs-title">Comprehensive Survey Workbooks</h2>
                    <p class="ena-jobs-copy">Track generation progress and download completed Excel reports.</p>
                </div>

                <x-filament::button
                    color="gray"
                    icon="heroicon-o-arrow-path"
                    size="sm"
                    wire:click="$refresh"
                >
                    Refresh status
                </x-filament::button>
            </div>

            <div>
                @forelse ($this->getRecentExports() as $export)
                    <div class="ena-job-row">
                        <div class="ena-job-main">
                            <div class="ena-job-title-line">
                                <p class="ena-job-name" title="{{ $export->file_name }}">{{ $export->file_name }}</p>
                                <x-filament::badge :color="$this->exportStatusColor($export->status)">
                                    {{ $this->exportStatusLabel($export->status) }}
                                </x-filament::badge>
                            </div>
                            <div class="ena-job-meta">
                                <span>Requested {{ $export->created_at->diffForHumans() }}</span>
                                <span>{{ $this->exportRowLabel($export) }}</span>
                                <span>{{ $this->formatFileSize($export->file_size) }}</span>
                            </div>
                            @if ($export->status === \App\Models\CreditReportExport::STATUS_FAILED)
                                <p class="ena-job-error">Generation failed. Retry the workbook, or contact support if the problem continues.</p>
                            @endif
                        </div>

                        <div class="ena-job-action">
                            @if ($export->isDownloadable())
                                <x-filament::button
                                    tag="a"
                                    :href="route('credit-reports.download', $export)"
                                    icon="heroicon-o-arrow-down-tray"
                                    color="success"
                                    size="sm"
                                >
                                    Download Excel
                                </x-filament::button>
                            @elseif (in_array($export->status, [\App\Models\CreditReportExport::STATUS_QUEUED, \App\Models\CreditReportExport::STATUS_PROCESSING], true))
                                <div class="ena-job-loading">
                                    <x-filament::loading-indicator class="ena-loading-icon" />
                                    Workbook is being prepared
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="ena-empty-jobs">
                        <x-heroicon-o-document-chart-bar class="ena-report-icon-large" />
                        <p class="ena-empty-jobs-title">No comprehensive reports requested yet</p>
                        <p class="ena-empty-jobs-copy">Select a survey and group, then generate your first workbook.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</x-filament-panels::page>
