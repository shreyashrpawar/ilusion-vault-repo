import axios from 'axios';
import { ref } from 'vue';

export function useTwoFactorAuth() {
    const enabling = ref(false);
    const confirming = ref(false);
    const disabling = ref(false);
    const qrCode = ref<string | null>(null);
    const secretKey = ref<string | null>(null);
    const recoveryCodes = ref<string[]>([]);
    const errors = ref<string[]>([]);

    const clearErrors = () => {
        errors.value = [];
    };

    const enable = async () => {
        enabling.value = true;
        clearErrors();

        try {
            await axios.post('/user/two-factor-authentication');
            await Promise.all([fetchQrCode(), fetchSecretKey()]);
        } catch (e: any) {
            errors.value = e.response?.data?.errors 
                ? Object.values(e.response.data.errors).flat() as string[]
                : ['Failed to enable two-factor authentication.'];

            throw e;
        } finally {
            enabling.value = false;
        }
    };

    const confirm = async (code: string) => {
        confirming.value = true;
        clearErrors();

        try {
            await axios.post('/user/confirmed-two-factor-authentication', { code });
            await fetchRecoveryCodes();
        } catch (e: any) {
            errors.value = e.response?.data?.errors 
                ? Object.values(e.response.data.errors).flat() as string[]
                : ['Invalid confirmation code.'];

            throw e;
        } finally {
            confirming.value = false;
        }
    };

    const disable = async () => {
        disabling.value = true;
        clearErrors();

        try {
            await axios.delete('/user/two-factor-authentication');
            qrCode.value = null;
            secretKey.value = null;
            recoveryCodes.value = [];
        } catch (e: any) {
            errors.value = e.response?.data?.errors 
                ? Object.values(e.response.data.errors).flat() as string[]
                : ['Failed to disable two-factor authentication.'];

            throw e;
        } finally {
            disabling.value = false;
        }
    };

    const fetchQrCode = async () => {
        try {
            const response = await axios.get('/user/two-factor-qr-code');
            qrCode.value = response.data.svg;
        } catch (e) {
            console.error('Failed to fetch QR code', e);
        }
    };

    const fetchSecretKey = async () => {
        try {
            const response = await axios.get('/user/two-factor-secret-key');
            secretKey.value = response.data.secretKey;
        } catch (e) {
            console.error('Failed to fetch secret key', e);
        }
    };

    const fetchRecoveryCodes = async () => {
        try {
            const response = await axios.get('/user/two-factor-recovery-codes');
            recoveryCodes.value = response.data;
        } catch (e) {
            console.error('Failed to fetch recovery codes', e);
        }
    };

    const regenerateRecoveryCodes = async () => {
        clearErrors();

        try {
            await axios.post('/user/two-factor-recovery-codes');
            await fetchRecoveryCodes();
        } catch {
            errors.value = ['Failed to regenerate recovery codes.'];
        }
    };

    return {
        enabling,
        confirming,
        disabling,
        qrCode,
        secretKey,
        recoveryCodes,
        errors,
        clearErrors,
        enable,
        confirm,
        disable,
        fetchRecoveryCodes,
        regenerateRecoveryCodes,
    };
}
