<script setup lang="ts">
import { Copy, Check, QrCode, KeyRound } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { ref, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    open: boolean;
    qrCode: string | null;
    secretKey: string | null;
    confirming: boolean;
    errors: string[];
    onConfirm: (code: string) => Promise<void>;
    onClose: () => void;
}>();

const code = ref('');
const localConfirming = ref(false);
const errorList = ref<string[]>(props.errors);

watch(() => props.errors, (newVal) => {
    errorList.value = newVal;
});

const { copy, copied } = useClipboard({
    source: () => props.secretKey || '',
});

const handleConfirm = async () => {
    if (!code.value) {
        errorList.value = ['Please enter the 6-digit verification code.'];

        return;
    }

    localConfirming.value = true;
    errorList.value = [];

    try {
        await props.onConfirm(code.value);
        code.value = '';
    } catch {
        // Errors are populated by parent/composable prop
    } finally {
        localConfirming.value = false;
    }
};

const handleOpenChange = (val: boolean) => {
    if (!val) {
        code.value = '';
        errorList.value = [];
        props.onClose();
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogContent class="bg-vault-surface-container-lowest border border-vault-outline-variant text-vault-on-surface max-w-md">
            <DialogHeader class="space-y-3">
                <DialogTitle class="text-lg font-bold text-vault-on-surface">
                    Set Up Two-Factor Authentication
                </DialogTitle>
                <DialogDescription class="text-sm text-vault-secondary">
                    Secure your account by configuring two-factor authentication (TOTP).
                </DialogDescription>
            </DialogHeader>

            <AlertError v-if="errorList.length > 0" :errors="errorList" />

            <div class="space-y-6 my-4">
                <!-- QR Code section -->
                <div class="flex flex-col items-center justify-center space-y-3">
                    <div class="p-2 bg-vault-surface-container-low border border-vault-outline-variant rounded flex items-center gap-2 w-full text-vault-secondary font-label-sm uppercase select-none">
                        <QrCode class="w-4 h-4 text-vault-primary" />
                        <span>1. Scan this QR Code</span>
                    </div>
                    <div class="bg-white p-3 rounded-lg border border-vault-outline-variant inline-flex items-center justify-center max-w-[180px] max-h-[180px]">
                        <div class="qr-code-svg-container w-[156px] h-[156px] [&>svg]:w-full [&>svg]:h-full" v-html="qrCode"></div>
                    </div>
                </div>

                <!-- Manual Secret Key -->
                <div v-if="secretKey" class="space-y-2">
                    <div class="p-2 bg-vault-surface-container-low border border-vault-outline-variant rounded flex items-center gap-2 w-full text-vault-secondary font-label-sm uppercase select-none">
                        <KeyRound class="w-4 h-4 text-vault-primary" />
                        <span>2. Or enter secret key manually</span>
                    </div>
                    <div class="flex items-center gap-2 bg-vault-surface-container-low border border-vault-outline-variant rounded px-3 py-2">
                        <code class="font-mono text-sm tracking-wider flex-grow text-vault-on-surface select-all overflow-x-auto whitespace-nowrap">
                            {{ secretKey }}
                        </code>
                        <button
                            type="button"
                            @click="copy()"
                            class="bg-vault-surface-container-lowest border border-vault-outline-variant text-vault-on-surface hover:bg-vault-surface-container-high transition-colors p-1.5 rounded cursor-pointer shrink-0"
                            title="Copy Secret Key"
                        >
                            <component :is="copied ? Check : Copy" class="w-4 h-4 text-vault-primary" />
                        </button>
                    </div>
                </div>

                <!-- Verification Input -->
                <div class="space-y-2">
                    <label for="verification_code" class="font-label-sm text-label-sm uppercase text-vault-secondary select-none">
                        3. Enter Verification Code
                    </label>
                    <input
                        id="verification_code"
                        type="text"
                        pattern="[0-9]*"
                        inputmode="numeric"
                        maxlength="6"
                        v-model="code"
                        placeholder="000000"
                        class="mt-1 block w-full bg-vault-surface-container-low border border-vault-outline-variant rounded px-4 py-3 text-vault-on-surface placeholder-vault-secondary font-mono text-center text-lg tracking-[0.5em] focus:outline-none focus:border-vault-primary transition-colors"
                        @keydown.enter="handleConfirm"
                    />
                </div>
            </div>

            <DialogFooter class="gap-2">
                <button
                    type="button"
                    class="bg-vault-surface-container-lowest border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-3 px-6 rounded hover:bg-vault-surface-container-low transition-colors duration-200 cursor-pointer"
                    @click="handleOpenChange(false)"
                >
                    Cancel
                </button>

                <button
                    type="button"
                    class="bg-vault-primary text-vault-on-primary font-label-md text-label-md py-3 px-6 rounded hover:bg-vault-primary-container transition-colors flex items-center justify-center cursor-pointer disabled:opacity-50"
                    :disabled="localConfirming || confirming"
                    @click="handleConfirm"
                >
                    Verify & Activate
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
