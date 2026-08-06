import { ref } from 'vue';

interface ConfirmOptions {
    title?: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    type?: 'danger' | 'warning' | 'info';
}

const isVisible = ref(false);
const options = ref<ConfirmOptions>({
    title: 'Confirm Action',
    message: '',
    confirmText: 'Confirm',
    cancelText: 'Cancel',
    type: 'danger'
});

let resolvePromise: (value: boolean) => void;

export function useConfirm() {
    const confirm = (opts: ConfirmOptions | string) => {
        if (typeof opts === 'string') {
            options.value = {
                title: 'Confirm Action',
                message: opts,
                confirmText: 'Confirm',
                cancelText: 'Cancel',
                type: 'danger'
            };
        } else {
            options.value = {
                title: opts.title || 'Confirm Action',
                message: opts.message,
                confirmText: opts.confirmText || 'Confirm',
                cancelText: opts.cancelText || 'Cancel',
                type: opts.type || 'danger'
            };
        }

        isVisible.value = true;

        return new Promise<boolean>((resolve) => {
            resolvePromise = resolve;
        });
    };

    const handleConfirm = () => {
        isVisible.value = false;

        if (resolvePromise) {
resolvePromise(true);
}
    };

    const handleCancel = () => {
        isVisible.value = false;

        if (resolvePromise) {
resolvePromise(false);
}
    };

    return {
        isVisible,
        options,
        confirm,
        handleConfirm,
        handleCancel
    };
}
