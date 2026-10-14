import template from './agentenblick-journey.html.twig';
import './agentenblick-journey.scss';

const stages = ['discover', 'browse', 'search', 'product', 'cart', 'checkout', 'order'];
const agentKinds = ['training_crawler', 'search_crawler', 'user_fetch', 'browsing_agent', 'automation_unknown'];

Shopware.Component.register('agentenblick-journey', {
    template,
    inject: ['agentenblickApiService', 'acl'],
    props: {
        mode: { type: String, required: true },
        filters: { type: Object, required: true },
    },

    data() {
        return {
            summary: null,
            sessions: [],
            total: 0,
            page: 1,
            limit: 20,
            loading: false,
            failed: false,
            sequence: 0,
            selectedId: null,
            detail: null,
            detailLoading: false,
            detailFailed: false,
            detailSequence: 0,
        };
    },

    computed: {
        canView() { return this.acl.can('agentenblick.viewer'); },
        locale() {
            const locale = this.$i18n.locale;
            return (typeof locale === 'string' ? locale : locale?.value)?.startsWith('de') ? 'de-DE' : 'en-GB';
        },
        stages() { return stages; },
        pageCount() { return Math.max(1, Math.ceil(this.total / this.limit)); },
        proofGroups() {
            return ['verified', 'declared', 'other'].map((group) => ({
                key: group,
                rows: (this.summary?.funnel || []).filter((row) => this.proofGroup(row.evidence) === group),
            })).filter((group) => group.rows.length);
        },
        failures() { return (this.summary?.failures || []).filter((row) => row.eligibleSessions > 0 || row.sessions > 0); },
        unknownFailures() { return (this.summary?.failures || []).filter((row) => !(row.eligibleSessions > 0) && !(row.sessions > 0)); },
        errorRoutes() { return this.summary?.errorRoutes || []; },
    },

    watch: {
        mode() { this.page = 1; this.closeDetail(); this.load(); },
        filters: { deep: true, handler() { this.page = 1; this.closeDetail(); this.load(); } },
    },

    created() { this.load(); },
    beforeUnmount() { this.sequence += 1; this.detailSequence += 1; },

    methods: {
        async load() {
            if (!this.canView || !['funnel', 'journeys', 'failures'].includes(this.mode)) return;
            const sequence = ++this.sequence;
            this.loading = true;
            this.failed = false;
            try {
                if (this.mode === 'journeys') {
                    const result = await this.agentenblickApiService.getJourneySessions({ ...this.filters, page: this.page, limit: this.limit });
                    if (sequence !== this.sequence) return;
                    this.sessions = (result.items || []).filter((session) => this.isAgent(session));
                    this.total = Number.isSafeInteger(result.total) && result.total >= 0 ? result.total : 0;
                } else {
                    const result = await this.agentenblickApiService.getJourneySummary(this.filters);
                    if (sequence !== this.sequence) return;
                    this.summary = result;
                }
            } catch (_error) {
                if (sequence === this.sequence) this.failed = true;
            } finally {
                if (sequence === this.sequence) this.loading = false;
            }
        },
        changePage(delta) {
            const next = this.page + delta;
            if (this.loading || next < 1 || next > this.pageCount) return;
            this.page = next;
            this.load();
        },
        async openDetail(session) {
            if (!this.canView || !this.isAgent(session) || !session.id) return;
            const sequence = ++this.detailSequence;
            this.selectedId = session.id;
            this.detail = null;
            this.detailLoading = true;
            this.detailFailed = false;
            try {
                const result = await this.agentenblickApiService.getJourneySession(session.id, this.filters);
                if (sequence !== this.detailSequence) return;
                if (!this.isAgent(result.session)) throw new Error('Agent session required');
                this.detail = result;
            } catch (_error) {
                if (sequence === this.detailSequence) this.detailFailed = true;
            } finally {
                if (sequence === this.detailSequence) this.detailLoading = false;
            }
        },
        closeDetail() {
            this.detailSequence += 1;
            this.selectedId = null;
            this.detail = null;
            this.detailLoading = false;
            this.detailFailed = false;
        },
        isAgent(session) { return session && agentKinds.includes(session.kind); },
        proofGroup(evidence) {
            if (['verified_signature', 'verified_ip', 'verified_rdns'].includes(evidence)) return 'verified';
            return evidence === 'declared_unverified' ? 'declared' : 'other';
        },
        label(group, value) {
            const key = `agentenblick.${group}.${value}`;
            return value && this.$te(key) ? this.$t(key) : (value || this.$t('agentenblick.journey.unknown'));
        },
        stage(value) { return stages.includes(value) ? this.label('journey.stage', value) : this.$t('agentenblick.journey.unknown'); },
        count(value) {
            return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0
                ? new Intl.NumberFormat(this.locale).format(value) : '—';
        },
        percent(numerator, denominator) {
            if (!Number.isSafeInteger(numerator) || !Number.isSafeInteger(denominator)
                || numerator < 0 || denominator <= 0 || numerator > denominator) return '—';
            return new Intl.NumberFormat(this.locale, { style: 'percent', maximumFractionDigits: 1 }).format(numerator / denominator);
        },
        barWidth(numerator, denominator) {
            if (!Number.isSafeInteger(numerator) || !Number.isSafeInteger(denominator)
                || numerator < 0 || denominator <= 0 || numerator > denominator) return 0;
            return numerator / denominator * 100;
        },
        date(value) {
            if (!value) return '—';
            const normalized = String(value).replace(' ', 'T');
            const date = new Date(/(?:Z|[+-]\d{2}:?\d{2})$/.test(normalized) ? normalized : `${normalized}Z`);
            return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat(this.locale, {
                timeZone: 'UTC', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit',
            }).format(date);
        },
        failureCodes(value) { return Array.isArray(value) ? value.filter((code) => typeof code === 'string') : []; },
        stepNames(value) {
            return stages.filter((stage) => Array.isArray(value) ? value.includes(stage) : value?.[stage] === true || value?.[stage] > 0).map((stage) => this.stage(stage));
        },
    },
});
