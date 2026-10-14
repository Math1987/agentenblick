import template from './agentenblick-health.html.twig';
import './agentenblick-health.scss';

const resourceKeys = ['robots', 'llms', 'llms_full', 'agents_md', 'agents_md_upper', 'ucp', 'agent_card', 'mcp'];
const resourceStates = ['available', 'missing', 'blocked', 'error', 'unknown'];
const stages = ['discover', 'browse', 'search', 'product', 'cart', 'checkout', 'order'];

Shopware.Component.register('agentenblick-health', {
    template,
    inject: ['agentenblickApiService', 'acl'],
    props: { filters: { type: Object, required: true } },
    emits: ['busy'],
    data() {
        return {
            health: null, selectedDomainId: '', autoTest: false,
            loading: false, probing: false, loadFailed: false, probeFailed: false,
            sequence: 0, disposed: false,
        };
    },
    computed: {
        canView() { return this.acl.can('agentenblick.viewer'); },
        canEdit() { return this.acl.can('agentenblick.editor'); },
        offline() { return this.health?.offline === true; },
        domains() { return Array.isArray(this.health?.domains) ? this.health.domains : []; },
        selectedDomain() { return this.domains.find((domain) => domain.id === this.selectedDomainId) || null; },
        canProbe() {
            return this.canView && this.canEdit && this.health?.offline === false && this.selectedDomain?.eligible === true
                && !this.loading && !this.probing && !this.loadFailed;
        },
        snapshot() { return this.health?.snapshot || null; },
        resources() { return (this.snapshot?.resources || []).filter((resource) => resourceKeys.includes(resource.key)); },
        rules() { return Array.isArray(this.snapshot?.robots?.rules) ? this.snapshot.robots.rules : []; },
        publicRules() {
            const groups = this.snapshot?.robots?.groups;
            if (!Array.isArray(groups)) return '';
            return groups.map((group) => [
                ...(Array.isArray(group.agents) ? group.agents : []).filter((agent) => typeof agent === 'string').map((agent) => `User-agent: ${agent}`),
                ...(Array.isArray(group.rules) ? group.rules : []).filter((rule) => typeof rule.allow === 'boolean' && typeof rule.pattern === 'string').map((rule) => `${rule.allow ? 'Allow' : 'Disallow'}: ${rule.pattern}`),
            ].join('\n')).join('\n\n');
        },
        findings() { return Array.isArray(this.health?.findings) ? this.health.findings : []; },
        autoTests() { return Array.isArray(this.snapshot?.autoTests) ? this.snapshot.autoTests : []; },
        afterLlms() { return this.health?.afterLlms || null; },
        laterSteps() { return (this.afterLlms?.steps || []).filter((step) => stages.includes(step.stage)); },
        locale() {
            const value = this.$i18n.locale;
            return (typeof value === 'string' ? value : value?.value)?.startsWith('de') ? 'de-DE' : 'en-GB';
        },
    },
    created() { this.load(); },
    beforeUnmount() { this.disposed = true; this.sequence += 1; this.$emit('busy', false); },
    watch: {
        filters: { deep: true, handler() {
            if (this.probing) return;
            this.selectedDomainId = '';
            this.autoTest = false;
            this.load();
        } },
    },
    methods: {
        async load() {
            if (!this.canView || this.probing || this.disposed) return;
            const sequence = ++this.sequence;
            this.loading = true;
            this.loadFailed = false;
            try {
                const response = await this.agentenblickApiService.getHealth({
                    ...this.filters, ...(this.selectedDomainId ? { domainId: this.selectedDomainId } : {}),
                });
                if (sequence !== this.sequence || this.disposed) return;
                if (!Array.isArray(response?.domains) || typeof response.offline !== 'boolean') throw new Error('Invalid health response');
                this.health = response;
                this.selectedDomainId = response.domains.some((domain) => domain.id === response.selectedDomainId) ? response.selectedDomainId : '';
            } catch (_error) {
                if (sequence === this.sequence && !this.disposed) this.loadFailed = true;
            } finally {
                if (sequence === this.sequence && !this.disposed) this.loading = false;
            }
        },
        changeDomain(value) {
            if (this.probing || this.loading || !this.domains.some((domain) => domain.id === value)) return;
            this.selectedDomainId = value;
            this.autoTest = false;
            this.probeFailed = false;
            this.load();
        },
        async probe() {
            if (!this.canProbe) return;
            this.probing = true;
            this.probeFailed = false;
            this.$emit('busy', true);
            try {
                const response = await this.agentenblickApiService.probeHealth({ domainId: this.selectedDomainId, autoTest: this.autoTest === true });
                if (response?.completed !== true) throw new Error('Incomplete public check');
                if (this.disposed) return;
                this.probing = false;
                await this.load();
            } catch (_error) {
                if (!this.disposed) this.probeFailed = true;
            } finally {
                if (!this.disposed) { this.probing = false; this.$emit('busy', false); }
            }
        },
        state(resource) { return resourceStates.includes(resource?.state) ? resource.state : 'unknown'; },
        stateClass(resource) { return `agentenblick-health__state--${this.state(resource)}`; },
        decision(rule) { return ['allow', 'disallow'].includes(rule?.decision) ? rule.decision : 'unknown'; },
        statusCode(value) { return Number.isInteger(value) && value >= 100 && value <= 599 ? String(value) : '—'; },
        count(value) { return Number.isSafeInteger(value) && value >= 0 ? new Intl.NumberFormat(this.locale).format(value) : '—'; },
        label(group, value) {
            const key = `agentenblick.${group}.${value}`;
            return typeof value === 'string' && this.$te(key) ? this.$t(key) : this.$t('agentenblick.health.unknown');
        },
        findingVariant(value) { return value === 'error' ? 'critical' : 'attention'; },
        probeMethod(value) { return ['GET', 'OPTIONS'].includes(value) ? value : '—'; },
        isVerified(evidence) { return ['verified_ip', 'verified_signature', 'verified_rdns'].includes(evidence); },
        date(value) {
            if (typeof value !== 'string' || !value) return '—';
            const normalized = value.replace(' ', 'T');
            const date = new Date(/(?:Z|[+-]\d{2}:?\d{2})$/.test(normalized) ? normalized : `${normalized}Z`);
            return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat(this.locale, { timeZone: 'UTC', dateStyle: 'medium', timeStyle: 'short' }).format(date);
        },
    },
});
