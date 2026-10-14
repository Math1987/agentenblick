import cardTemplate from './card.html.twig';
import buttonTemplate from './button.html.twig';
import bannerTemplate from './banner.html.twig';
import selectTemplate from './select.html.twig';
import checkboxTemplate from './checkbox.html.twig';

// The 6.6 baseline has Vue 3 but no guaranteed Meteor component registration.
// Keep that branch on the native Shopware components without replacing globals.
function meteorAvailable() {
    const version = String(Shopware.Context?.app?.config?.version || '');
    const match = /^(\d+)\.(\d+)(?:\.|$)/.exec(version);
    return Boolean(match && (Number(match[1]) > 6 || (Number(match[1]) === 6 && Number(match[2]) >= 7)));
}

const shared = { inheritAttrs: false, computed: { meteorAvailable } };

Shopware.Component.register('agentenblick-card', {
    ...shared,
    template: cardTemplate,
    props: {
        positionIdentifier: { type: String, required: true },
        title: { type: String, default: '' },
    },
});

Shopware.Component.register('agentenblick-button', {
    ...shared,
    template: buttonTemplate,
    props: {
        variant: { type: String, default: undefined },
        size: { type: String, default: undefined },
        disabled: { type: Boolean, default: false },
        isLoading: { type: Boolean, default: false },
    },
    emits: ['click'],
    methods: {
        clicked(event) {
            if (!this.disabled && !this.isLoading) this.$emit('click', event);
        },
    },
});

Shopware.Component.register('agentenblick-banner', {
    ...shared,
    template: bannerTemplate,
    props: { variant: { type: String, default: 'neutral' } },
    computed: {
        meteorAvailable,
        legacyVariant() {
            return { attention: 'warning', critical: 'error', positive: 'success', neutral: 'info' }[this.variant] || 'info';
        },
    },
});

Shopware.Component.register('agentenblick-select', {
    ...shared,
    template: selectTemplate,
    props: {
        modelValue: { type: [String, Number], default: null },
        options: { type: Array, required: true },
        label: { type: String, default: '' },
        name: { type: String, default: undefined },
        disabled: { type: Boolean, default: false },
        hideClearableButton: { type: Boolean, default: false },
    },
    emits: ['update:modelValue'],
    methods: {
        updateValue(value) {
            if (!this.disabled) this.$emit('update:modelValue', value);
        },
    },
});

Shopware.Component.register('agentenblick-checkbox', {
    ...shared,
    template: checkboxTemplate,
    props: {
        modelValue: { type: Boolean, default: false },
        label: { type: String, default: '' },
        disabled: { type: Boolean, default: false },
    },
    emits: ['update:modelValue'],
    methods: {
        updateValue(value) {
            if (!this.disabled && typeof value === 'boolean') this.$emit('update:modelValue', value);
        },
    },
});
