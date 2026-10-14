import template from './agentenblick-benchmark.html.twig';
import './agentenblick-benchmark.scss';

Shopware.Component.register('agentenblick-benchmark', {
    template,
    inject: ['agentenblickApiService', 'acl'],
    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            status: null,
            preview: null,
            history: [],
            comparison: null,
            consent: false,
            confirmWithdrawal: false,
            loading: false,
            busy: false,
            comparing: false,
            loadFailed: false,
            sequence: 0,
        };
    },

    computed: {
        canEdit() {
            return this.acl.can('agentenblick.editor');
        },
        locale() {
            const locale = this.$i18n.locale;
            const value = typeof locale === 'string' ? locale : locale?.value;
            return value?.startsWith('de') ? 'de-DE' : 'en-GB';
        },
        stateLabel() {
            if (this.identityConflict) return this.$t('agentenblick.benchmark.state.test_identity_conflict');
            if (this.status?.pendingWithdrawal) return this.$t('agentenblick.benchmark.state.withdrawal_pending');
            if (this.status?.offline) return this.$t('agentenblick.benchmark.state.offline');
            if (this.status?.reasonCode === 'registration_pending') return this.$t('agentenblick.benchmark.state.registration_pending');
            return this.$t(`agentenblick.benchmark.state.${this.status?.enabled ? 'ready' : 'disabled'}`);
        },
        identityConflict() {
            return this.status?.reasonCode === 'test_identity_conflict';
        },
        canTransmit() {
            return this.canEdit && this.status?.enabled && !this.status?.offline && !this.status?.pendingWithdrawal && !this.identityConflict && !this.busy;
        },
        canCompare() {
            return this.status?.enabled && !this.status?.offline && !this.status?.pendingWithdrawal && !this.identityConflict && !this.comparing && !this.busy;
        },
        comparisonCells() {
            const report = this.comparison?.report;
            if (!report || !Array.isArray(report.cells)) return [];
            return report.cells.filter((cell) => this.published(cell.metrics?.aiSessionsPct)).map((cell) => ({
                ...cell,
                cohort: report.cohorts?.find((cohort) => cohort.id === cell.cohortId),
                local: (this.comparison.local || []).filter((item) => item.period === cell.period && item.cohortId === cell.cohortId),
            }));
        },
        comparisonMessage() {
            const reason = this.comparison?.reason;
            const key = `agentenblick.benchmark.comparisonReason.${reason}`;
            return reason && this.$te(key) ? this.$t(key) : this.$t('agentenblick.benchmark.insufficient');
        },
    },

    created() {
        this.load();
    },

    beforeUnmount() {
        this.sequence += 1;
    },

    methods: {
        async load() {
            const sequence = ++this.sequence;
            this.loading = true;
            this.loadFailed = false;
            try {
                const status = await this.agentenblickApiService.getBenchmarkStatus();
                const [preview, history] = await Promise.all([
                    status.reasonCode === 'test_identity_conflict' ? Promise.resolve(null) : this.agentenblickApiService.getBenchmarkPreview(),
                    this.agentenblickApiService.getBenchmarkHistory(),
                ]);
                if (sequence !== this.sequence) return;
                this.status = status;
                this.preview = preview;
                this.history = history.items || [];
            } catch (_error) {
                if (sequence === this.sequence) this.loadFailed = true;
            } finally {
                if (sequence === this.sequence) this.loading = false;
            }
        },
        async update(action) {
            if (!this.canEdit || this.busy || !this.status) return;
            if (action === 'enable' && (!this.consent || this.status.pendingWithdrawal || this.status.offline || this.identityConflict)) return;
            if (action === 'send' && !this.canTransmit) return;
            this.busy = true;
            try {
                const body = action === 'enable' ? { consent: true, consentVersion: '1', locale: this.locale } : {};
                const result = await this.agentenblickApiService.updateBenchmark(action, body);
                this.confirmWithdrawal = false;
                this.consent = false;
                this.comparison = null;
                await this.load();
                if (this.loadFailed) throw new Error('Reload failed');
                if (action === 'send' && ['failed', 'registration_pending'].includes(result?.status)) {
                    this.createNotificationError({ message: this.$t('agentenblick.benchmark.error.send') });
                    return;
                }
                this.createNotificationSuccess({ message: this.$t(`agentenblick.benchmark.success.${action}`) });
            } catch (_error) {
                this.createNotificationError({ message: this.$t('agentenblick.benchmark.error.update') });
            } finally {
                this.busy = false;
            }
        },
        async compare() {
            if (!this.canCompare) return;
            this.comparing = true;
            try {
                this.comparison = await this.agentenblickApiService.getBenchmarkComparison();
            } catch (_error) {
                this.createNotificationError({ message: this.$t('agentenblick.benchmark.error.compare') });
            } finally {
                this.comparing = false;
            }
        },
        published(metric) {
            return metric && Number.isSafeInteger(metric.installations) && metric.installations >= 5
                && [metric.p25, metric.median, metric.p75].every((value) => typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 100)
                && metric.p25 <= metric.median && metric.median <= metric.p75;
        },
        percent(value) {
            return typeof value === 'number' && Number.isFinite(value)
                ? new Intl.NumberFormat(this.locale, { style: 'percent', maximumFractionDigits: 1 }).format(value / 100) : '—';
        },
        date(value) {
            if (!value) return '—';
            const normalized = String(value).replace(' ', 'T');
            const date = new Date(/(?:Z|[+-]\d{2}:?\d{2})$/.test(normalized) ? normalized : `${normalized}Z`);
            return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat(this.locale, {
                timeZone: 'UTC', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
            }).format(date);
        },
        rowStatus(value, resultCode) {
            if (value === 'failed' && ['http_400', 'http_409', 'http_410', 'http_413', 'http_422'].includes(resultCode)) {
                return this.$t('agentenblick.benchmark.submission.rejected');
            }
            const key = `agentenblick.benchmark.submission.${value}`;
            return this.$te(key) ? this.$t(key) : this.$t('agentenblick.benchmark.submission.unknown');
        },
        closeWithdrawal() {
            if (!this.busy) this.confirmWithdrawal = false;
        },
    },
});
