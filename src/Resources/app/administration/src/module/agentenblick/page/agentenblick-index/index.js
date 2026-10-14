import template from './agentenblick-index.html.twig';
import './agentenblick-index.scss';

const { Component, Mixin } = Shopware;

Component.register('agentenblick-index', {
    template,
    inject: ['agentenblickApiService', 'acl'],
    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            days: 7,
            salesChannelId: '',
            activeTab: 'overview',
            page: 1,
            limit: 25,
            summary: null,
            agents: [],
            totalAgents: 0,
            pendingRanges: [],
            selectedRange: null,
            isLoading: false,
            loadFailed: false,
            isExporting: false,
            isApproving: false,
            requestSequence: 0,
        };
    },

    computed: {
        tabs() {
            return ['overview', 'agents', 'funnel', 'journeys', 'failures', 'benchmark'];
        },
        isJourneyTab() {
            return ['funnel', 'journeys', 'failures'].includes(this.activeTab);
        },
        canView() {
            return this.acl.can('agentenblick.viewer');
        },
        canEdit() {
            return this.acl.can('agentenblick.editor');
        },
        locale() {
            const locale = this.$i18n.locale;
            return (typeof locale === 'string' ? locale : locale?.value) || 'en-GB';
        },
        periodOptions() {
            return [7, 30].map((days) => ({ value: days, label: this.$t('agentenblick.filters.lastDays', { days }) }));
        },
        channelOptions() {
            return [
                { value: '', label: this.$t('agentenblick.filters.allChannels') },
                ...(this.summary?.salesChannels || []).map((channel) => ({ value: channel.id, label: channel.name || channel.id })),
            ];
        },
        filters() {
            return { days: this.days, ...(this.salesChannelId ? { salesChannelId: this.salesChannelId } : {}) };
        },
        totals() {
            return this.summary?.totals || {};
        },
        daily() {
            return this.summary?.daily || [];
        },
        proxyWarning() {
            return this.summary?.proxyDiagnostics?.warning === true;
        },
        pageCount() {
            return Math.max(1, Math.ceil(this.totalAgents / this.limit));
        },
        chartMaximum() {
            return Math.max(1, ...this.daily.map((day) => Number(day.sessions) || 0));
        },
        chartTicks() {
            return [...new Set([0, Math.ceil(this.chartMaximum / 2), this.chartMaximum])];
        },
        sessionPoints() {
            return this.chartPoints('sessions');
        },
        agentSessionPoints() {
            return this.chartPoints('agentSessions');
        },
    },

    created() {
        this.loadData();
    },

    beforeUnmount() {
        this.requestSequence += 1;
    },

    methods: {
        async loadData() {
            if (!this.canView) {
                return;
            }
            const sequence = ++this.requestSequence;
            this.isLoading = true;
            this.loadFailed = false;
            try {
                const [summary, agents, pending] = await Promise.all([
                    this.agentenblickApiService.getSummary(this.filters),
                    this.agentenblickApiService.getAgents({ ...this.filters, page: this.page, limit: this.limit }),
                    this.agentenblickApiService.getPendingRanges(),
                ]);
                if (sequence !== this.requestSequence) {
                    return;
                }
                this.summary = summary;
                this.agents = agents.items || [];
                this.totalAgents = Number(agents.total) || 0;
                this.pendingRanges = pending.items || [];
            } catch (_error) {
                if (sequence === this.requestSequence) {
                    this.loadFailed = true;
                }
            } finally {
                if (sequence === this.requestSequence) {
                    this.isLoading = false;
                }
            }
        },
        onDaysChange(value) {
            const days = Number(value);
            if (![7, 30].includes(days) || days === this.days) {
                return;
            }
            this.days = days;
            this.page = 1;
            this.loadData();
        },
        onChannelChange(value) {
            if ((value || '') === this.salesChannelId) {
                return;
            }
            this.salesChannelId = value || '';
            this.page = 1;
            this.loadData();
        },
        changePage(change) {
            const page = this.page + change;
            if (page < 1 || page > this.pageCount) {
                return;
            }
            this.page = page;
            this.loadData();
        },
        selectTab(tab, focus = false) {
            if (!this.tabs.includes(tab)) return;
            this.activeTab = tab;
            if (focus) {
                this.$nextTick(() => {
                    const reference = this.$refs[`${tab}Tab`];
                    (Array.isArray(reference) ? reference[0] : reference)?.focus();
                });
            }
        },
        moveTab(delta) {
            this.selectTab(this.tabs[(this.tabs.indexOf(this.activeTab) + delta + this.tabs.length) % this.tabs.length], true);
        },
        refreshVisible() {
            if (this.isJourneyTab) this.$refs.journey?.load();
            else this.loadData();
        },
        formatNumber(value) {
            return new Intl.NumberFormat(this.locale).format(Number(value) || 0);
        },
        formatPercent(value) {
            if (value === null || value === undefined) {
                return '—';
            }
            return new Intl.NumberFormat(this.locale, { style: 'percent', maximumFractionDigits: 1 }).format(Number(value) / 100);
        },
        formatDate(value, includeTime = false) {
            if (!value) {
                return '—';
            }
            const normalized = String(value).replace(' ', 'T');
            const date = new Date(normalized.length === 10 ? `${normalized}T00:00:00Z` : /(?:Z|[+-]\d{2}:?\d{2})$/.test(normalized) ? normalized : `${normalized}Z`);
            if (Number.isNaN(date.getTime())) {
                return '—';
            }
            return new Intl.DateTimeFormat(this.locale, {
                timeZone: 'UTC', day: '2-digit', month: 'short',
                ...(includeTime ? { hour: '2-digit', minute: '2-digit' } : {}),
            }).format(date);
        },
        label(group, value) {
            const key = `agentenblick.${group}.${value}`;
            return this.$te(key) ? this.$t(key) : value;
        },
        chartX(index) {
            return 46 + (this.daily.length > 1 ? (index / (this.daily.length - 1)) * 656 : 328);
        },
        chartY(value) {
            return 174 - ((Number(value) || 0) / this.chartMaximum) * 144;
        },
        chartPoints(metric) {
            return this.daily.map((day, index) => `${this.chartX(index)},${this.chartY(day[metric])}`).join(' ');
        },
        evidenceClass(value) {
            return ['verified_signature', 'verified_ip', 'verified_rdns'].includes(value)
                ? 'agentenblick-proof--verified' : 'agentenblick-proof--unverified';
        },
        async exportCsv() {
            if (this.isExporting || !this.canView) {
                return;
            }
            this.isExporting = true;
            try {
                const blob = await this.agentenblickApiService.exportAgents(this.filters);
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = `agentenblick-agents-${this.days}d.csv`;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            } catch (_error) {
                this.createNotificationError({ message: this.$t('agentenblick.errors.export') });
            } finally {
                this.isExporting = false;
            }
        },
        closeRange() {
            if (!this.isApproving) {
                this.selectedRange = null;
            }
        },
        async approveRange() {
            if (!this.canEdit || this.isApproving || !this.selectedRange) {
                return;
            }
            this.isApproving = true;
            try {
                await this.agentenblickApiService.approveRanges(this.selectedRange.agent, this.selectedRange.version);
                this.selectedRange = null;
                this.createNotificationSuccess({ message: this.$t('agentenblick.ranges.approved') });
                await this.loadData();
            } catch (error) {
                const changed = error.response?.status === 409;
                this.createNotificationError({ message: this.$t(changed ? 'agentenblick.ranges.changed' : 'agentenblick.errors.approve') });
                if (changed) {
                    this.selectedRange = null;
                    await this.loadData();
                }
            } finally {
                this.isApproving = false;
            }
        },
        publicSourceUrl(value) {
            try {
                const url = new URL(value);
                return url.protocol === 'https:' && !url.username && !url.password ? url.href : null;
            } catch (_error) {
                return null;
            }
        },
    },
});
