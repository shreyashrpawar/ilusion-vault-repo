<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ShieldCheck, ShieldAlert, LockKeyhole } from '@lucide/vue';
import { ref } from 'vue';
import AlertError from '@/components/AlertError.vue';
import Heading from '@/components/Heading.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';

const props = defineProps<{
    twoFactorEnabled: boolean;
}>();

const {
    enabling,
    confirming,
    disabling,
    qrCode,
    secretKey,
    recoveryCodes,
    errors,
    enable,
    confirm,
    disable,
    fetchRecoveryCodes,
    regenerateRecoveryCodes,
} = useTwoFactorAuth();

const setupModalOpen = ref(false);
const showingRecoveryCodes = ref(false);
const localErrors = ref<string[]>([]);

const handleEnable = async () => {
    localErrors.value = [];

    try {
        await enable();
        setupModalOpen.value = true;
    } catch {
        localErrors.value = errors.value;
    }
};

const handleConfirmSetup = async (code: string) => {
    localErrors.value = [];

    try {
        await confirm(code);
        setupModalOpen.value = false;
        showingRecoveryCodes.value = true;
        // Reload parent props to update twoFactorEnabled status
        router.reload({ only: ['twoFactorEnabled'] });
    } catch (e: any) {
        localErrors.value = errors.value;

        throw e;
    }
};

const handleDisable = async () => {
    localErrors.value = [];

    try {
        await disable();
        showingRecoveryCodes.value = false;
        // Reload parent props to update twoFactorEnabled status
        router.reload({ only: ['twoFactorEnabled'] });
    } catch {
        localErrors.value = errors.value;
    }
};

const handleShowRecoveryCodes = async () => {
    localErrors.value = [];

    try {
        await fetchRecoveryCodes();
        showingRecoveryCodes.value = !showingRecoveryCodes.value;
    } catch {
        localErrors.value = ['Failed to fetch recovery codes.'];
    }
};
</script>

<template>
    <div class="space-y-6 pt-6 border-t border-vault-outline-variant/50">
        <Heading
            variant="small"
            title="Two-Factor Authentication"
            description="Add additional security to your account using two-factor authentication"
        />

        <AlertError v-if="localErrors.length > 0" :errors="localErrors" />

        <div class="bg-vault-surface-container-low border border-vault-outline-variant rounded p-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center gap-3">
                    <component
                        :is="props.twoFactorEnabled ? ShieldCheck : ShieldAlert"
                        class="w-8 h-8 shrink-0"
                        :class="props.twoFactorEnabled ? 'text-vault-primary' : 'text-vault-secondary'"
                    />
                    <div>
                        <p class="font-display text-body-lg text-vault-on-surface">
                            Status: <span class="font-bold">{{ props.twoFactorEnabled ? 'Enabled' : 'Disabled' }}</span>
                        </p>
                        <p class="text-vault-secondary font-body-sm mt-1">
                            {{ props.twoFactorEnabled
                                ? 'Your account is protected with two-factor authentication.'
                                : 'Enable two-factor authentication to require a code when logging in.'
                            }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-3 self-end sm:self-center">
                    <button
                        v-if="!props.twoFactorEnabled"
                        type="button"
                        @click="handleEnable"
                        :disabled="enabling"
                        class="bg-vault-primary text-vault-on-primary font-label-md text-label-md py-3 px-6 rounded hover:bg-vault-primary-container transition-colors flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50"
                    >
                        Enable
                    </button>

                    <button
                        v-else
                        type="button"
                        @click="handleDisable"
                        :disabled="disabling"
                        class="bg-red-600 hover:bg-red-700 text-white font-label-md text-label-md py-3 px-6 rounded transition-colors flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50"
                    >
                        Disable
                    </button>
                </div>
            </div>

            <!-- Extra actions when enabled -->
            <div v-if="props.twoFactorEnabled" class="mt-6 pt-6 border-t border-vault-outline-variant/50 flex flex-wrap gap-4">
                <button
                    type="button"
                    @click="handleShowRecoveryCodes"
                    class="bg-vault-surface-container-low border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-2.5 px-6 rounded hover:bg-vault-surface-container-high transition-colors flex items-center gap-2 cursor-pointer"
                >
                    <LockKeyhole class="w-4 h-4" />
                    {{ showingRecoveryCodes ? 'Hide Recovery Codes' : 'Show Recovery Codes' }}
                </button>
            </div>
        </div>

        <!-- Recovery Codes Panel -->
        <TwoFactorRecoveryCodes
            v-if="props.twoFactorEnabled && showingRecoveryCodes"
            :recovery-codes="recoveryCodes"
            :on-regenerate="regenerateRecoveryCodes"
        />

        <!-- Setup Modal -->
        <TwoFactorSetupModal
            :open="setupModalOpen"
            :qr-code="qrCode"
            :secret-key="secretKey"
            :confirming="confirming"
            :errors="localErrors"
            :on-confirm="handleConfirmSetup"
            :on-close="() => setupModalOpen = false"
        />
    </div>
</template>
