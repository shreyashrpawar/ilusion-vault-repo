<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium text-vault-on-surface'
                    : 'text-xl font-semibold tracking-tight text-vault-on-surface'
            "
        >
            {{ title }}
        </h2>
        <p v-if="description" class="text-sm text-vault-on-surface-variant">
            {{ description }}
        </p>
    </header>
</template>
