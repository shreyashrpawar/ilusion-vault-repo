<script setup lang="ts">
import { Eye, EyeOff, LockKeyhole, RefreshCw, Copy, Check } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { ref } from 'vue';
import AlertError from '@/components/AlertError.vue';

const props = defineProps<{
    recoveryCodes: string[];
    onRegenerate: () => Promise<void>;
}>();

const showCodes = ref(false);
const regenerating = ref(false);
const error = ref<string | null>(null);

const { copy, copied } = useClipboard({
    source: () => props.recoveryCodes.join('\n'),
});

const handleRegenerate = async () => {
    regenerating.value = true;
    error.value = null;

    try {
        await props.onRegenerate();
    } catch {
        error.value = 'Failed to regenerate recovery codes.';
    } finally {
        regenerating.value = false;
    }
};
</script>

<template>
    <div class="bg-vault-surface-container-lowest border border-vault-outline-variant rounded p-6 space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2 rounded bg-vault-surface-container-low border border-vault-outline-variant text-vault-primary">
                    <LockKeyhole class="w-5 h-5" />
                </div>
                <div>
                    <h3 class="font-display text-title-sm text-vault-on-surface">Recovery Codes</h3>
                    <p class="text-vault-secondary font-body-sm mt-1">
                        Store these recovery codes securely. They can be used to access your account if your two-factor authentication device is lost.
                    </p>
                </div>
            </div>
        </div>

        <AlertError v-if="error" :errors="[error]" />

        <div v-if="props.recoveryCodes.length > 0" class="space-y-4">
            <!-- Codes grid container -->
            <div class="relative bg-vault-surface-container-low border border-vault-outline-variant rounded p-4 font-mono text-sm tracking-wider">
                <div v-if="showCodes" class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-vault-on-surface select-all">
                    <div v-for="code in props.recoveryCodes" :key="code" class="text-center py-1">
                        {{ code }}
                    </div>
                </div>
                <div v-else class="flex flex-col items-center justify-center py-6 text-vault-secondary">
                    <LockKeyhole class="w-8 h-8 opacity-40 mb-2" />
                    <span>Recovery codes are hidden</span>
                </div>
            </div>

            <!-- Actions row -->
            <div class="flex flex-wrap items-center gap-3 pt-2">
                <button
                    type="button"
                    @click="showCodes = !showCodes"
                    class="bg-vault-surface-container-low border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-2 px-4 rounded hover:bg-vault-surface-container-high transition-colors flex items-center gap-2 cursor-pointer"
                >
                    <component :is="showCodes ? EyeOff : Eye" class="w-4 h-4" />
                    {{ showCodes ? 'Hide Codes' : 'Show Codes' }}
                </button>

                <button
                    type="button"
                    v-if="showCodes"
                    @click="copy()"
                    class="bg-vault-surface-container-low border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-2 px-4 rounded hover:bg-vault-surface-container-high transition-colors flex items-center gap-2 cursor-pointer"
                >
                    <component :is="copied ? Check : Copy" class="w-4 h-4" />
                    {{ copied ? 'Copied!' : 'Copy All' }}
                </button>

                <button
                    type="button"
                    @click="handleRegenerate"
                    :disabled="regenerating"
                    class="bg-vault-surface-container-low border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-2 px-4 rounded hover:bg-vault-surface-container-high transition-colors flex items-center gap-2 disabled:opacity-50 cursor-pointer ml-auto"
                >
                    <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': regenerating }" />
                    Regenerate Codes
                </button>
            </div>
        </div>
    </div>
</template>
