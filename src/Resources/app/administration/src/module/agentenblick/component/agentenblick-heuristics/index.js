import template from './agentenblick-heuristics.html.twig';
import './agentenblick-heuristics.scss';

Shopware.Component.register('agentenblick-heuristics', {
    template,
    inject: ['agentenblickApiService', 'acl'],
    mixins: [Shopware.Mixin.getByName('notification')],
    emits: ['saving'],

    data() {
        return { settings: null, enabled: false, threshold: 60, loading: false, saving: false, failed: false, sequence: 0 };
    },

    computed: {
        canView() { return this.acl.can('agentenblick.viewer'); },
        canEdit() { return this.acl.can('agentenblick.editor'); },
        thresholdValid() { return Number.isInteger(this.threshold) && this.threshold >= 30 && this.threshold <= 100; },
        dirty() { return this.settings && (this.enabled !== this.settings.enabled || this.threshold !== this.settings.threshold); },
        canSave() { return this.canEdit && this.settings && !this.loading && !this.saving && this.thresholdValid && this.dirty; },
    },

    created() { this.load(); },
    beforeUnmount() { this.sequence += 1; this.$emit('saving', false); },

    methods: {
        acceptSettings(value) {
            if (typeof value?.enabled !== 'boolean' || !Number.isInteger(value.threshold)
                || value.threshold < 30 || value.threshold > 100 || typeof value.version !== 'string') {
                throw new Error('Invalid heuristic settings');
            }
            this.settings = value;
            this.enabled = value.enabled;
            this.threshold = value.threshold;
        },
        async load() {
            if (!this.canView || this.saving) return;
            const sequence = ++this.sequence;
            this.loading = true;
            this.failed = false;
            try {
                const value = await this.agentenblickApiService.getHeuristicSettings();
                if (sequence === this.sequence) this.acceptSettings(value);
            } catch (_error) {
                if (sequence === this.sequence) { this.failed = true; this.settings = null; }
            } finally {
                if (sequence === this.sequence) this.loading = false;
            }
        },
        changeEnabled(value) {
            if (this.canEdit && !this.loading && !this.saving && this.settings) this.enabled = value === true;
        },
        changeThreshold(value) {
            if (!this.canEdit || this.loading || this.saving || !this.settings) return;
            this.threshold = value === '' || value === null ? null : Number(value);
        },
        async save() {
            if (!this.canSave) return;
            this.saving = true;
            this.$emit('saving', true);
            const sequence = ++this.sequence;
            try {
                const value = await this.agentenblickApiService.updateHeuristicSettings({ enabled: this.enabled, threshold: this.threshold });
                if (sequence !== this.sequence) return;
                this.acceptSettings(value);
                this.createNotificationSuccess({ message: this.$t('agentenblick.heuristics.saved') });
            } catch (_error) {
                if (sequence === this.sequence) this.createNotificationError({ message: this.$t('agentenblick.heuristics.saveFailed') });
            } finally {
                if (sequence === this.sequence) { this.saving = false; this.$emit('saving', false); }
            }
        },
    },
});
