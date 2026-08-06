<script setup lang="ts">
import { Head, usePage, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmModal from '@/components/ConfirmModal.vue';
import { Toaster } from '@/components/ui/sonner';
import { useConfirm } from '@/composables/useConfirm';
import { encryptFile, encryptText, decryptFile, createBatchWorker, deriveKey, base64ToArrayBuffer } from '@/lib/crypto';
import { logout } from '@/routes';

const { confirm } = useConfirm();
const page = usePage();
const user = computed(() => page.props.auth?.user);

interface Secret {
    secret_id: string;
    identifier: string | null;
    url: string;
    expiry_date: string;
    burn_on_read: boolean;
    recipient_email: string | null;
    created_at: string;
}

interface VaultFileRaw {
    id: number;
    encrypted_metadata: string;
    salt: string;
    iv: string;
    created_at: string;
    download_url: string;
}

interface VaultFileDecrypted extends VaultFileRaw {
    name: string;
    type: string;
    size: number;
    selected: boolean;
}

const props = defineProps<{ secrets: Secret[] }>();
const localSecrets = ref<Secret[]>([...props.secrets]);

// Tabs
const activeTab = ref<'vault' | 'secrets'>('vault');

// Vault State
const vaultFilesRaw = ref<VaultFileRaw[]>([]);
const vaultFiles = ref<VaultFileDecrypted[]>([]);
const isVaultUnlocked = ref(false);
const vaultPassphrase = ref('');
const isFetchingFiles = ref(false);
const isUnlocking = ref(false);
const isUploading = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

const decryptVaultFiles = async (passphrase: string) => {
    if (vaultFilesRaw.value.length === 0) {
        vaultFiles.value = [];

        return;
    }

    // Cache derived keys by salt to avoid redundant PBKDF2 calls.
    // Files encrypted in the same batch share the same salt,
    // so this dramatically reduces key derivation overhead.
    const keyCache = new Map<string, CryptoKey>();

    const getCachedKey = async (saltB64: string): Promise<CryptoKey> => {
        let key = keyCache.get(saltB64);

        if (!key) {
            const saltBuffer = base64ToArrayBuffer(saltB64);
            key = await deriveKey(passphrase, new Uint8Array(saltBuffer), 600000);
            keyCache.set(saltB64, key);
        }

        return key;
    };

    const decryptedFiles = await Promise.all(
        vaultFilesRaw.value.map(async (file) => {
            try {
                const key = await getCachedKey(file.salt);
                const ivBuffer = base64ToArrayBuffer(file.iv);
                const ciphertextBuffer = base64ToArrayBuffer(file.encrypted_metadata);

                const decrypted = await window.crypto.subtle.decrypt(
                    { name: 'AES-GCM', iv: new Uint8Array(ivBuffer) },
                    key,
                    ciphertextBuffer
                );

                const meta = JSON.parse(new TextDecoder().decode(decrypted));

                return {
                    ...file,
                    name: meta.name || `File #${file.id}`,
                    type: meta.type || 'unknown',
                    size: meta.size || 0,
                    selected: false,
                    decryptError: false
                };
            } catch {
                return {
                    ...file,
                    name: `File #${file.id} (Decryption Error)`,
                    type: 'unknown',
                    size: 0,
                    selected: false,
                    decryptError: true
                };
            }
        })
    );

    // If ALL files failed decryption and raw files exist, passphrase is invalid
    const validCount = decryptedFiles.filter(f => !f.decryptError).length;

    if (vaultFilesRaw.value.length > 0 && validCount === 0) {
        throw new Error('Incorrect passphrase');
    }

    vaultFiles.value = decryptedFiles;
};


const fetchVaultFiles = async () => {
    isFetchingFiles.value = true;

    try {
        const res = await axios.get('/api/vault/files');
        vaultFilesRaw.value = res.data;

        if (isVaultUnlocked.value && vaultPassphrase.value) {
            await decryptVaultFiles(vaultPassphrase.value);
        }
    } catch {
        toast.error('Failed to fetch vault files.');
    } finally {
        isFetchingFiles.value = false;
    }
};

onMounted(() => {
    fetchVaultFiles();
    window.addEventListener('dragenter', handleDragEnter);
    window.addEventListener('dragleave', handleDragLeave);
    window.addEventListener('dragover', handleDragOver);
    window.addEventListener('drop', handleDrop);
});

onUnmounted(() => {
    window.removeEventListener('dragenter', handleDragEnter);
    window.removeEventListener('dragleave', handleDragLeave);
    window.removeEventListener('dragover', handleDragOver);
    window.removeEventListener('drop', handleDrop);
});

const formatBytes = (bytes: number) => {
    if (bytes === 0) {
return '0 Bytes';
}

    const k = 1024, sizes = ['Bytes', 'KB', 'MB', 'GB'], i = Math.floor(Math.log(bytes) / Math.log(k));

    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
};

const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success('Copied to clipboard!');
};

// Vault Actions
const unlockVault = async () => {
    if (!vaultPassphrase.value) {
return toast.error('Enter a passphrase');
}
    
    isUnlocking.value = true;

    try {
        if (isFetchingFiles.value) {
            // Wait for in-flight fetch to complete if user clicks unlock early
            await fetchVaultFiles();
        }

        if (vaultFilesRaw.value.length > 0) {
            await decryptVaultFiles(vaultPassphrase.value);
        } else {
            vaultFiles.value = [];
        }

        isVaultUnlocked.value = true;
        toast.success('Vault unlocked successfully!');
    } catch (e: any) {
        toast.error(e.message || 'Failed to unlock vault. Incorrect passphrase?');
    } finally {
        isUnlocking.value = false;
    }
};

const isDragging = ref(false);
let dragCounter = 0;

const handleDragEnter = (e: DragEvent) => {
    e.preventDefault();

    if (e.dataTransfer?.types.includes('Files')) {
        dragCounter++;
        isDragging.value = true;
    }
};

const handleDragLeave = (e: DragEvent) => {
    e.preventDefault();
    dragCounter--;

    if (dragCounter <= 0) {
        isDragging.value = false;
        dragCounter = 0;
    }
};

const handleDragOver = (e: DragEvent) => {
    e.preventDefault();
};

const handleDrop = async (e: DragEvent) => {
    e.preventDefault();
    isDragging.value = false;
    dragCounter = 0;
    
    if (!isVaultUnlocked.value) {
        toast.error('Please unlock your vault first before dragging and dropping files.');

        return;
    }

    if (e.dataTransfer?.files && e.dataTransfer.files.length > 0) {
        const filesArray = Array.from(e.dataTransfer.files);
        await processUpload(filesArray);
    }
};

const uploadProgress = ref(0);
const uploadStage = ref('');

const processUpload = async (filesArray: File[]) => {
    const MAX_SIZE = 100 * 1024 * 1024; // 100MB

    for (const file of filesArray) {
        if (file.size > MAX_SIZE) {
            toast.error(`File "${file.name}" exceeds the maximum upload limit of 100MB.`);

            return;
        }
    }

    isUploading.value = true;
    uploadProgress.value = 5;
    uploadStage.value = 'Deriving encryption key...';
    
    const formData = new FormData();
    
    try {
        // Derive key once — all files reuse it
        const batch = await createBatchWorker(vaultPassphrase.value);
        
        try {
            const metadataArr = [];

            for (let i = 0; i < filesArray.length; i++) {
                const file = filesArray[i];
                uploadStage.value = `Encrypting ${i + 1}/${filesArray.length} (${file.name})...`;
                uploadProgress.value = 5 + Math.round(((i + 1) / filesArray.length) * 25);
                const result = await batch.encryptFile(file);
                formData.append('files[]', result.encryptedBlob, file.name);
                metadataArr.push(result.metadata);
            }
            
            formData.append('file_metadata', JSON.stringify(metadataArr));
        } finally {
            batch.terminate();
        }
        
        uploadStage.value = 'Uploading encrypted files...';
        uploadProgress.value = 30;
        
        await axios.post('/api/vault/files', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            onUploadProgress: (progressEvent) => {
                if (progressEvent.total) {
                    const percent = Math.round((progressEvent.loaded * 100) / progressEvent.total);
                    uploadProgress.value = 30 + Math.round(percent * 0.5);
                    uploadStage.value = `Uploading to Vault... ${percent}%`;
                }
            }
        });
        
        uploadStage.value = 'Refreshing vault...';
        uploadProgress.value = 85;
        await fetchVaultFiles();

        uploadStage.value = 'Decrypting workspace...';
        uploadProgress.value = 95;
        await unlockVault();

        uploadStage.value = 'Complete!';
        uploadProgress.value = 100;
        toast.success('Files securely added to Vault.');
        await new Promise(resolve => setTimeout(resolve, 400));
    } catch (e: any) {
        console.error('Vault upload error:', e);
        const serverMsg = e.response?.data?.errors 
            ? Object.values(e.response.data.errors).flat().join(' ')
            : (e.response?.data?.message || 'Failed to upload files.');
        toast.error(serverMsg);
    } finally {
        isUploading.value = false;
        uploadProgress.value = 0;
        uploadStage.value = '';
    }
};

const triggerFileUpload = () => fileInput.value?.click();

const handleFileUpload = async (event: Event) => {
    const target = event.target as HTMLInputElement;

    if (!target.files || target.files.length === 0) {
return;
}
    
    const filesArray = Array.from(target.files);
    await processUpload(filesArray);

    if (target) {
target.value = '';
}
};

const deleteVaultFile = async (id: number) => {
    if (await confirm({ title: 'Delete Vault File', message: 'This file will be permanently deleted.', confirmText: 'Delete', type: 'danger' })) {
        try {
            await axios.delete(`/api/vault/files/${id}`);
            vaultFiles.value = vaultFiles.value.filter(f => f.id !== id);
            vaultFilesRaw.value = vaultFilesRaw.value.filter(f => f.id !== id);
            toast.success('File deleted.');
        } catch {
            toast.error('Deletion failed.');
        }
    }
};

const deleteSecret = async (secretId: string) => {
    if (await confirm({ title: 'Delete Secret', message: 'Delete this shared secret?', confirmText: 'Delete', type: 'danger' })) {
        try {
            await axios.delete(`/api/secrets/${secretId}`);
            localSecrets.value = localSecrets.value.filter(s => s.secret_id !== secretId);
            toast.success('Secret deleted.');
        } catch {
            toast.error('Deletion failed.');
        }
    }
};

// Selection logic
const toggleSelectAll = (e: Event) => {
    const checked = (e.target as HTMLInputElement).checked;
    vaultFiles.value.forEach(f => f.selected = checked);
};
const selectedFilesCount = computed(() => vaultFiles.value.filter(f => f.selected).length);

// Share logic
const isShareModalOpen = ref(false);
const isSharing = ref(false);
const generatedShareUrl = ref('');
const shareForm = ref({
    password: '',
    expiry: '7 Days',
    burn_on_read: false,
    identifier: '',
    recipient_email: '',
    message: ''
});

const generateRandomPassword = () => {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_';
    let p = '';

    for (let i = 0; i < 16; i++) {
p += chars.charAt(Math.floor(Math.random() * chars.length));
}

    shareForm.value.password = p;
};

const initiateShare = () => {
    const selected = vaultFiles.value.filter(f => f.selected);

    if (selected.length === 0) {
return toast.error('Select at least one file to share.');
}
    
    shareForm.value = { password: '', expiry: '7 Days', burn_on_read: false, identifier: '', recipient_email: '', message: '' };
    generateRandomPassword();
    isShareModalOpen.value = true;
    generatedShareUrl.value = '';
};

const executeShare = async () => {
    if (!shareForm.value.password) {
return toast.error('Share password is required.');
}
    
    const selected = vaultFiles.value.filter(f => f.selected);
    isSharing.value = true;
    uploadProgress.value = 5;
    uploadStage.value = 'Preparing files for share link...';
    
    try {
        const formData = new FormData();
        const metadataArr: any[] = [];
        
        for (let index = 0; index < selected.length; index++) {
            const file = selected[index];
            uploadStage.value = `Encrypting ${index + 1}/${selected.length} (${file.name})...`;
            uploadProgress.value = 5 + Math.round(((index + 1) / selected.length) * 30);

            // 1. Download encrypted blob from vault
            const res = await axios.get(file.download_url, { 
                responseType: 'arraybuffer',
                headers: { 'X-Vault-Decrypted': 'true' }
            });
            
            // 2. Decrypt using Vault Passphrase
            const { decryptedFile } = await decryptFile(
                res.data,
                vaultPassphrase.value,
                file.encrypted_metadata,
                file.salt,
                file.iv
            );
            
            // 3. Re-encrypt using Share Password (PBKDF2 — compatible with View.vue decryption)
            const { encryptedBlob, metadata } = await encryptFile(decryptedFile, shareForm.value.password);
            
            formData.append('files[]', encryptedBlob, decryptedFile.name);
            metadataArr.push(metadata);
        }

        formData.append('file_metadata', JSON.stringify(metadataArr));
        formData.append('expiry', shareForm.value.expiry);
        formData.append('burn_on_read', shareForm.value.burn_on_read ? '1' : '0');

        if (shareForm.value.identifier) {
formData.append('identifier', shareForm.value.identifier);
}

        if (shareForm.value.recipient_email) {
formData.append('recipient_email', shareForm.value.recipient_email);
}
        
        // The backend requires a payload, so we provide a default message if none is entered.
        const messageText = shareForm.value.message || 'Secure files shared via Ilusion Vault.';
        const encryptedPayload = await encryptText(messageText, shareForm.value.password);
        formData.append('payload', encryptedPayload);
        
        uploadStage.value = 'Uploading encrypted payload...';
        uploadProgress.value = 35;

        // Post to SecretController
        const response = await axios.post('/api/secrets', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            onUploadProgress: (progressEvent) => {
                if (progressEvent.total) {
                    const percent = Math.round((progressEvent.loaded * 100) / progressEvent.total);
                    uploadProgress.value = 35 + Math.round(percent * 0.65);
                    uploadStage.value = `Uploading... ${percent}%`;
                }
            }
        });
        
        uploadStage.value = 'Complete!';
        uploadProgress.value = 100;
        await new Promise(resolve => setTimeout(resolve, 300));

        const secretId = response.data.secret_id;
        generatedShareUrl.value = `${window.location.origin}/secret/${secretId}`;
        
        toast.success('Share link generated securely!');
    } catch (e: any) {
        toast.error(e.response?.data?.message || 'Failed to generate share link.');
    } finally {
        isSharing.value = false;
        uploadProgress.value = 0;
        uploadStage.value = '';
    }
};

const closeShareModal = () => {
    isShareModalOpen.value = false;

    if (generatedShareUrl.value) {
        // Switch to secrets tab to see new link
        activeTab.value = 'secrets';
        router.reload({ only: ['secrets'] }); // reload inertia props for secrets
    }
};

</script>

<template>
    <Head title="Dashboard - Ilusion Vault" />

    <div class="vault-light bg-vault-background min-h-screen flex flex-col font-body-md antialiased text-vault-on-background selection:bg-[#e4e4e7] selection:text-[#18181b]">
        
        <!-- App Navbar (SaaS Style) -->
        <header class="sticky top-0 z-50 bg-vault-surface-container-lowest border-b border-vault-outline-variant shadow-sm px-4 sm:px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-6">
                <Link href="/" class="flex items-center gap-2 group">
                    <img src="/ilusion-logo.png" alt="Ilusion" class="w-7 h-7 object-contain group-hover:scale-105 transition-transform" />
                    <span class="font-headline-md font-bold text-lg tracking-tight text-vault-on-surface">Ilusion Vault</span>
                </Link>
                
                <nav class="hidden md:flex items-center gap-1 ml-4">
                    <button @click="activeTab = 'vault'" :class="['px-3 py-1.5 rounded-md text-sm font-medium transition-colors flex items-center gap-1.5', activeTab === 'vault' ? 'bg-vault-surface-container text-vault-on-surface' : 'text-vault-on-surface-variant hover:text-vault-on-surface hover:bg-vault-surface-container-low']">
                        <span class="material-symbols-outlined text-[1rem]">lock</span> My Vault
                    </button>
                    <button @click="activeTab = 'secrets'" :class="['px-3 py-1.5 rounded-md text-sm font-medium transition-colors flex items-center gap-1.5', activeTab === 'secrets' ? 'bg-vault-surface-container text-vault-on-surface' : 'text-vault-on-surface-variant hover:text-vault-on-surface hover:bg-vault-surface-container-low']">
                        <span class="material-symbols-outlined text-[1rem]">vpn_key</span> Shared Links
                        <span v-if="localSecrets.length > 0" class="bg-vault-primary/10 text-vault-primary text-[10px] px-1.5 py-0.5 rounded-full font-bold">{{ localSecrets.length }}</span>
                    </button>
                </nav>
            </div>

            <div class="flex items-center gap-3">
                <Link href="/create" class="bg-vault-primary text-vault-on-primary font-label-md text-xs py-2 px-3.5 rounded-lg hover:bg-vault-primary-container transition-colors shadow-sm inline-flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[1rem]">add</span> New Secret
                </Link>
                <Link href="/view" class="bg-vault-surface-container border border-vault-outline-variant text-vault-on-surface font-label-md text-xs py-2 px-3.5 rounded-lg hover:bg-vault-outline-variant/30 transition-colors shadow-sm inline-flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[1rem]">lock_open</span> Reveal Secret
                </Link>

                <div class="hidden sm:flex items-center gap-3 mr-2">
                    <div class="text-right">
                        <p class="text-sm font-medium text-vault-on-surface leading-none">{{ user?.name }}</p>
                        <p class="text-xs text-vault-on-surface-variant mt-1">{{ user?.email }}</p>
                    </div>
                    <div class="w-9 h-9 rounded-full bg-vault-primary text-vault-on-primary flex items-center justify-center font-bold text-sm shadow-sm select-none">
                        {{ user?.name ? user.name[0].toUpperCase() : 'V' }}
                    </div>
                </div>
                
                <div class="h-6 w-px bg-vault-outline-variant hidden sm:block"></div>
                
                <Link href="/settings" class="text-vault-secondary hover:text-vault-on-surface transition-colors p-1 flex items-center justify-center" title="Settings">
                    <span class="material-symbols-outlined text-[1.25rem]">settings</span>
                </Link>
                <Link :href="logout().url" method="post" as="button" class="text-vault-secondary hover:text-vault-on-surface transition-colors p-1 flex items-center justify-center" title="Log Out">
                    <span class="material-symbols-outlined text-[1.25rem]">logout</span>
                </Link>
            </div>
        </header>

        <!-- Main Workspace (Full-Bleed Web App Layout) -->
        <main class="flex-grow w-full flex flex-col px-4 sm:px-6 lg:px-8 py-6">
            
            <!-- Mobile Tab Switcher (Visible only on small screens) -->
            <div class="flex md:hidden bg-vault-surface-container-low p-1 rounded-lg border border-vault-outline-variant mb-4">
                <button @click="activeTab = 'vault'" :class="['flex-1 py-2 rounded-md text-sm font-medium transition-all duration-200 flex items-center justify-center gap-1.5', activeTab === 'vault' ? 'bg-vault-surface-container-lowest text-vault-on-surface shadow-sm' : 'text-vault-on-surface-variant']">
                    <span class="material-symbols-outlined text-[1rem]">lock</span> My Vault
                </button>
                <button @click="activeTab = 'secrets'" :class="['flex-1 py-2 rounded-md text-sm font-medium transition-all duration-200 flex items-center justify-center gap-1.5', activeTab === 'secrets' ? 'bg-vault-surface-container-lowest text-vault-on-surface shadow-sm' : 'text-vault-on-surface-variant']">
                    <span class="material-symbols-outlined text-[1rem]">vpn_key</span> Shared Links
                </button>
            </div>

            <!-- TAB 1: MY VAULT -->
            <div v-if="activeTab === 'vault'" class="flex-grow flex flex-col animate-in fade-in duration-300">
                <!-- Locked State -->
                <div v-if="!isVaultUnlocked" class="my-auto mx-auto w-full max-w-md bg-vault-surface-container-lowest border border-vault-outline-variant rounded-2xl p-8 text-center shadow-lg">
                    <div class="w-16 h-16 mx-auto bg-vault-surface-container-low rounded-2xl flex items-center justify-center mb-5">
                        <span class="material-symbols-outlined text-3xl text-vault-primary">enhanced_encryption</span>
                    </div>
                    <h2 class="text-xl font-bold text-vault-on-surface mb-2 font-headline-md">Unlock Your Vault</h2>
                    <p class="text-sm text-vault-on-surface-variant mb-6">Enter your Master Passphrase to decrypt your files locally. Your passphrase never leaves your device.</p>

                    <form @submit.prevent="unlockVault" class="space-y-4">
                        <input v-model="vaultPassphrase" type="password" placeholder="Master Passphrase" class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg px-4 py-3 text-vault-on-surface focus:ring-2 focus:ring-vault-primary outline-none transition-all text-sm shadow-sm" required />
                        <button type="submit" :disabled="isUnlocking" class="w-full bg-vault-primary hover:bg-vault-primary-container text-vault-on-primary font-label-md py-3 rounded-lg shadow-sm transition-all active:scale-[0.98] disabled:opacity-50 flex justify-center items-center gap-2">
                            <span v-if="isUnlocking" class="material-symbols-outlined animate-spin text-[1.125rem]">progress_activity</span>
                            <span v-else class="material-symbols-outlined text-[1.125rem]">key</span>
                            {{ isUnlocking ? 'Decrypting...' : 'Unlock Workspace' }}
                        </button>
                    </form>
                </div>

                <!-- Unlocked State -->
                <div v-else class="w-full flex flex-col flex-grow">
                    <!-- Top Workspace Bar -->
                    <div class="pb-4 mb-2 border-b border-vault-outline-variant flex flex-wrap justify-between items-center gap-4">
                        <div class="flex items-center gap-3">
                            <h1 class="text-xl font-bold text-vault-on-surface font-headline-md tracking-tight">Files</h1>
                            <span v-if="vaultFiles.length > 0" class="bg-vault-surface-container text-vault-on-surface px-2.5 py-0.5 rounded-full text-xs font-semibold font-mono border border-vault-outline-variant">{{ vaultFiles.length }} items</span>
                        </div>

                        <div class="flex items-center gap-3">
                            <input type="file" ref="fileInput" multiple @change="handleFileUpload" class="hidden" />
                            <button v-if="selectedFilesCount > 0 && !isUploading" @click="initiateShare" class="bg-vault-primary text-vault-on-primary font-label-md text-[0.8125rem] py-2 px-4 rounded-lg hover:bg-vault-primary-container transition-colors shadow-sm inline-flex items-center gap-2">
                                <span class="material-symbols-outlined text-[1rem]">ios_share</span>
                                Share {{ selectedFilesCount }} Selected
                            </button>

                            <div v-if="isUploading" class="bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg px-3.5 py-1.5 flex flex-col gap-1 shadow-sm min-w-[220px]">
                                <div class="flex justify-between items-center text-xs text-vault-on-surface">
                                    <span class="font-medium flex items-center gap-1 truncate max-w-[150px]">
                                        <span class="material-symbols-outlined text-[0.875rem] animate-spin text-vault-primary shrink-0">sync</span>
                                        <span class="truncate text-[0.6875rem]">{{ uploadStage }}</span>
                                    </span>
                                    <span class="font-mono font-bold text-vault-primary text-[0.6875rem] shrink-0">{{ uploadProgress }}%</span>
                                </div>
                                <div class="w-full bg-vault-surface-container rounded-full h-1.5 overflow-hidden">
                                    <div class="bg-vault-primary h-1.5 rounded-full transition-all duration-200 ease-out" :style="{ width: uploadProgress + '%' }"></div>
                                </div>
                            </div>
                            <button v-else @click="triggerFileUpload" class="bg-vault-surface-container border border-vault-outline-variant text-vault-on-surface font-label-md text-[0.8125rem] py-2 px-4 rounded-lg hover:bg-vault-outline-variant/30 transition-colors shadow-sm inline-flex items-center gap-2">
                                <span class="material-symbols-outlined text-[1rem]">upload_file</span>
                                Upload Files
                            </button>
                        </div>
                    </div>

                    <!-- Loading State -->
                    <div v-if="isFetchingFiles" class="flex-grow flex flex-col items-center justify-center p-16 text-center">
                        <span class="material-symbols-outlined animate-spin text-3xl text-vault-primary mb-3">progress_activity</span>
                        <p class="text-vault-on-surface font-medium text-sm">Decrypting workspace files...</p>
                    </div>

                    <!-- Empty State -->
                    <div v-else-if="vaultFiles.length === 0" class="flex-grow flex flex-col items-center justify-center p-16 text-center">
                        <div class="w-16 h-16 bg-vault-surface-container rounded-2xl flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined text-3xl text-vault-secondary">inventory_2</span>
                        </div>
                        <p class="text-vault-on-surface font-bold mb-1 font-headline-md text-lg">No files in your vault</p>
                        <p class="text-sm text-vault-on-surface-variant mb-6 max-w-sm">Files uploaded here are encrypted locally before reaching the server.</p>
                        <button @click="triggerFileUpload" class="bg-vault-primary text-vault-on-primary font-label-md text-[0.8125rem] py-2.5 px-6 rounded-lg shadow-sm hover:bg-vault-primary-container transition-transform active:scale-[0.98] flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-[1rem]">add</span> Upload Your First File
                        </button>
                    </div>

                    <!-- File List -->
                    <div v-else class="overflow-x-auto w-full">
                        <table class="w-full text-left border-collapse whitespace-nowrap">
                            <thead>
                                <tr class="bg-vault-surface-container/30 text-xs font-semibold uppercase tracking-wider text-vault-secondary border-b border-vault-outline-variant">
                                    <th class="py-3.5 px-4 w-12 text-center font-headline-md"><input type="checkbox" @change="toggleSelectAll" class="rounded border-vault-outline accent-vault-primary cursor-pointer w-4 h-4" /></th>
                                    <th class="py-3.5 px-4 font-headline-md">Name</th>
                                    <th class="py-3.5 px-4 font-headline-md">Size</th>
                                    <th class="py-3.5 px-4 font-headline-md">Date Modified</th>
                                    <th class="py-3.5 px-4 text-right font-headline-md">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-vault-outline-variant/60 text-sm">
                                <tr v-for="file in vaultFiles" :key="file.id" class="hover:bg-vault-surface-container/40 transition-colors group cursor-default">
                                    <td class="py-3.5 px-4 text-center"><input type="checkbox" v-model="file.selected" class="rounded border-vault-outline accent-vault-primary cursor-pointer w-4 h-4" /></td>
                                    <td class="py-3.5 px-4 font-medium text-vault-on-surface flex items-center gap-3">
                                        <span class="material-symbols-outlined text-vault-outline text-[1.25rem]">insert_drive_file</span>
                                        <span class="truncate max-w-[20rem] sm:max-w-[30rem] lg:max-w-[40rem]">{{ file.name }}</span>
                                    </td>
                                    <td class="py-3.5 px-4 text-vault-on-surface-variant font-mono text-xs">{{ formatBytes(file.size) }}</td>
                                    <td class="py-3.5 px-4 text-vault-on-surface-variant">{{ formatDate(file.created_at) }}</td>
                                    <td class="py-3.5 px-4 text-right">
                                        <button @click="deleteVaultFile(file.id)" class="text-vault-outline hover:text-red-600 transition-colors p-1" title="Delete">
                                            <span class="material-symbols-outlined text-[1.125rem]">delete</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 2: SHARED SECRETS -->
            <div v-if="activeTab === 'secrets'" class="flex-grow flex flex-col animate-in fade-in duration-300">
                <div class="w-full flex flex-col flex-grow">
                    <div class="pb-4 mb-2 border-b border-vault-outline-variant flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <h1 class="text-xl font-bold text-vault-on-surface font-headline-md tracking-tight">Active Shared Links</h1>
                            <span v-if="localSecrets.length > 0" class="bg-vault-surface-container text-vault-on-surface px-2.5 py-0.5 rounded-full text-xs font-semibold font-mono border border-vault-outline-variant">{{ localSecrets.length }} active</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <Link href="/view" class="bg-vault-surface-container border border-vault-outline-variant text-vault-on-surface font-label-md text-xs py-2 px-3.5 rounded-lg hover:bg-vault-outline-variant/30 transition-colors shadow-sm inline-flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[1rem]">lock_open</span> Reveal Secret
                            </Link>
                            <Link href="/create" class="bg-vault-primary text-vault-on-primary font-label-md text-xs py-2 px-3.5 rounded-lg hover:bg-vault-primary-container transition-colors shadow-sm inline-flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[1rem]">add</span> Create Encrypted Secret
                            </Link>
                        </div>
                    </div>

                    <div v-if="localSecrets.length === 0" class="flex-grow flex flex-col items-center justify-center p-16 text-center">
                        <div class="w-16 h-16 bg-vault-surface-container rounded-2xl flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined text-3xl text-vault-secondary">link_off</span>
                        </div>
                        <p class="text-vault-on-surface font-bold mb-1 font-headline-md text-lg">No active share links</p>
                        <p class="text-sm text-vault-on-surface-variant mb-6 max-w-sm">Create a secret link with custom expiration, burn-on-read, and attachments, or reveal an existing secret using its link.</p>
                        <div class="flex flex-col sm:flex-row items-center gap-3">
                            <Link href="/create" class="bg-vault-primary text-vault-on-primary font-label-md text-[0.8125rem] py-2.5 px-6 rounded-lg shadow-sm hover:bg-vault-primary-container transition-transform active:scale-[0.98] flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-[1rem]">add</span> Create Secret Link
                            </Link>
                            <Link href="/view" class="bg-vault-surface-container border border-vault-outline-variant text-vault-on-surface font-label-md text-[0.8125rem] py-2.5 px-6 rounded-lg hover:bg-vault-outline-variant/30 transition-colors flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-[1rem]">lock_open</span> Reveal / Decrypt Secret
                            </Link>
                        </div>
                    </div>

                    <div v-else class="overflow-x-auto w-full">
                        <table class="w-full text-left border-collapse whitespace-nowrap">
                            <thead>
                                <tr class="bg-vault-surface-container/30 text-xs font-semibold uppercase tracking-wider text-vault-secondary border-b border-vault-outline-variant">
                                    <th class="py-3.5 px-4 font-headline-md">Identifier / ID</th>
                                    <th class="py-3.5 px-4 font-headline-md">Created At</th>
                                    <th class="py-3.5 px-4 font-headline-md">Expires At</th>
                                    <th class="py-3.5 px-4 text-center font-headline-md">Settings</th>
                                    <th class="py-3.5 px-4 text-right font-headline-md">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-vault-outline-variant/60 text-sm">
                                <tr v-for="secret in localSecrets" :key="secret.secret_id" class="hover:bg-vault-surface-container/40 transition-colors group">
                                    <td class="py-3.5 px-4 font-mono text-vault-on-surface max-w-[15rem] truncate select-text" :title="secret.identifier || secret.secret_id">
                                        {{ secret.identifier || secret.secret_id }}
                                    </td>
                                    <td class="py-3.5 px-4 text-vault-on-surface-variant">{{ formatDate(secret.created_at) }}</td>
                                    <td class="py-3.5 px-4 text-vault-on-surface-variant">{{ formatDate(secret.expiry_date) }}</td>
                                    <td class="py-3.5 px-4 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <span v-if="secret.burn_on_read" class="inline-flex items-center px-2 py-0.5 rounded text-[0.625rem] font-bold bg-red-100 text-red-800 tracking-wide uppercase">Burn on Read</span>
                                            <span v-if="secret.recipient_email" class="inline-flex items-center px-2 py-0.5 rounded text-[0.625rem] font-bold bg-blue-100 text-blue-800 tracking-wide uppercase" :title="secret.recipient_email">Emailed</span>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4 text-right">
                                        <div class="flex items-center justify-end gap-2.5">
                                            <button @click="copyToClipboard(secret.url)" class="text-vault-outline hover:text-vault-primary transition-colors p-1" title="Copy Secret Link">
                                                <span class="material-symbols-outlined text-[1.125rem]">content_copy</span>
                                            </button>
                                            <a :href="secret.url" class="bg-vault-primary text-vault-on-primary hover:bg-vault-primary-container px-3 py-1.5 rounded-md text-xs font-semibold inline-flex items-center gap-1.5 transition-colors shadow-xs" target="_blank" title="Reveal Secret">
                                                <span class="material-symbols-outlined text-[0.95rem]">lock_open</span> Reveal
                                            </a>
                                            <button @click="deleteSecret(secret.secret_id)" class="text-vault-outline hover:text-red-600 transition-colors p-1" title="Delete Secret">
                                                <span class="material-symbols-outlined text-[1.125rem]">delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>

        <!-- SHARE MODAL -->
        <div v-if="isShareModalOpen" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity" @click="!isSharing ? closeShareModal() : null"></div>
            
            <div class="relative bg-vault-surface-container-lowest border border-vault-outline-variant shadow-2xl rounded-2xl w-full max-w-lg p-6 sm:p-8 animate-in zoom-in-95 duration-200">
                <button @click="closeShareModal" class="absolute top-4 right-4 text-vault-outline hover:text-vault-on-surface transition-colors">
                    <span class="material-symbols-outlined">close</span>
                </button>

                <h3 class="text-xl font-bold text-vault-on-surface mb-1 font-headline-md">Share Securely</h3>
                <p class="text-sm text-vault-on-surface-variant mb-6">Create an encrypted sharing link for {{ selectedFilesCount }} file{{ selectedFilesCount > 1 ? 's' : '' }}.</p>

                <div v-if="generatedShareUrl" class="space-y-6">
                    <div class="bg-green-50 border border-green-200 p-5 rounded-xl text-center">
                        <span class="material-symbols-outlined text-green-600 text-[2.5rem] mb-2">check_circle</span>
                        <h4 class="text-lg font-bold text-green-900 mb-1">Link Ready!</h4>
                        <p class="text-sm text-green-800">Your secure sharing link has been generated.</p>
                    </div>

                    <div class="flex items-center gap-2 bg-vault-surface-container-low border border-vault-outline-variant p-1.5 rounded-lg">
                        <input type="text" :value="generatedShareUrl" class="flex-grow bg-transparent text-sm font-mono text-vault-on-surface px-3 py-1 outline-none" readonly />
                        <button @click="copyToClipboard(generatedShareUrl)" class="bg-vault-primary text-vault-on-primary px-3 py-1.5 rounded hover:bg-vault-primary-container flex items-center font-label-md text-xs shadow-sm">Copy Link</button>
                    </div>

                    <button @click="closeShareModal" class="w-full bg-vault-surface-container border border-vault-outline-variant hover:bg-vault-outline-variant/30 text-vault-on-surface font-label-md py-2.5 rounded-lg transition-colors">Close</button>
                </div>

                <div v-else class="space-y-4 text-left">
                    <div>
                        <label class="block text-sm font-semibold text-vault-on-surface mb-1.5">Encryption Key</label>
                        <div class="flex gap-2">
                            <input v-model="shareForm.password" type="text" class="flex-grow bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg px-4 py-2.5 text-vault-on-surface text-sm focus:ring-2 focus:ring-vault-primary outline-none shadow-sm" placeholder="Key required to unlock link" required />
                            <button @click="generateRandomPassword" class="bg-vault-surface-container border border-vault-outline-variant px-3 rounded-lg hover:bg-vault-outline-variant/30 flex items-center justify-center transition-colors shadow-sm" title="Generate Secure Key">
                                <span class="material-symbols-outlined text-[1.25rem]">password</span>
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-vault-on-surface mb-1.5">Expiration</label>
                            <select v-model="shareForm.expiry" class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg px-4 py-2.5 text-vault-on-surface text-sm outline-none shadow-sm cursor-pointer">
                                <option>1 Hour</option>
                                <option>1 Day</option>
                                <option>7 Days</option>
                                <option>15 Days</option>
                                <option>Never</option>
                            </select>
                        </div>
                        <div class="flex flex-col justify-end pb-2">
                            <label class="flex items-center gap-2 cursor-pointer group">
                                <input type="checkbox" v-model="shareForm.burn_on_read" class="w-4 h-4 rounded border-vault-outline accent-red-600" />
                                <span class="text-sm font-semibold text-red-600 group-hover:text-red-700 transition-colors">Burn on read</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-vault-on-surface mb-1.5">Secret Content / Note <span class="font-normal text-vault-secondary">(Optional)</span></label>
                        <textarea v-model="shareForm.message" rows="3" class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg px-4 py-2 text-vault-on-surface text-sm outline-none shadow-sm focus:ring-2 focus:ring-vault-primary placeholder:text-vault-outline/50 resize-y" placeholder="Add text, note or credentials..."></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-vault-on-surface mb-1.5">Custom ID <span class="font-normal text-vault-secondary">(Optional)</span></label>
                        <input v-model="shareForm.identifier" type="text" class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg px-4 py-2.5 text-vault-on-surface text-sm outline-none shadow-sm" placeholder="e.g. client-assets-q3" />
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-vault-on-surface mb-1.5">Recipient Email <span class="font-normal text-vault-secondary">(Optional)</span></label>
                        <input v-model="shareForm.recipient_email" type="email" class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg px-4 py-2.5 text-vault-on-surface text-sm outline-none shadow-sm" placeholder="Send link via email" />
                    </div>

                    <div class="pt-2">
                        <div v-if="isSharing" class="w-full bg-vault-surface-container-low border border-vault-outline-variant rounded-lg p-3 flex flex-col gap-1.5 shadow-sm">
                            <div class="flex justify-between items-center text-xs text-vault-on-surface">
                                <span class="font-medium flex items-center gap-1.5 truncate max-w-[14rem]">
                                    <span class="material-symbols-outlined text-[1rem] animate-spin text-vault-primary shrink-0">sync</span>
                                    <span class="truncate text-xs">{{ uploadStage }}</span>
                                </span>
                                <span class="font-mono font-bold text-vault-primary text-xs shrink-0">{{ uploadProgress }}%</span>
                            </div>
                            <div class="w-full bg-vault-surface-container-high rounded-full h-2 overflow-hidden">
                                <div class="bg-vault-primary h-2 rounded-full transition-all duration-200 ease-out" :style="{ width: uploadProgress + '%' }"></div>
                            </div>
                        </div>
                        <button v-else @click="executeShare" :disabled="!shareForm.password" class="w-full bg-vault-primary hover:bg-vault-primary-container text-vault-on-primary font-label-md py-3 rounded-lg shadow-sm transition-transform active:scale-[0.98] disabled:opacity-50 disabled:active:scale-100 flex justify-center items-center gap-2">
                            <span class="material-symbols-outlined text-[1.125rem]">lock</span>
                            Create Secure Link
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Drag and Drop Overlay -->
        <transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div 
                v-if="isDragging"
                class="fixed inset-0 z-[100] flex items-center justify-center p-6 bg-vault-background/60 backdrop-blur-md transition-all pointer-events-none"
            >
                <div class="w-full h-full border-2 border-dashed border-vault-primary/60 rounded-2xl flex flex-col items-center justify-center gap-4 bg-vault-surface-container/40 p-8 text-center animate-[pulse_2s_infinite]">
                    <div class="w-16 h-16 rounded-full bg-vault-primary/10 text-vault-primary flex items-center justify-center shadow-inner">
                        <span class="material-symbols-outlined text-[2.5rem]">lock</span>
                    </div>
                    <div>
                        <h3 class="font-headline-sm text-xl font-bold text-vault-on-surface mb-2">
                            Drop files here to encrypt & upload to your secure vault
                        </h3>
                        <p class="text-sm text-vault-secondary max-w-sm mx-auto">
                            Files will be encrypted directly in your browser before upload.
                        </p>
                    </div>
                </div>
            </div>
        </transition>

        <ConfirmModal />
        <Toaster />
    </div>
</template>

<style scoped>
.vault-light {
    --vault-background: #f8fafc; /* Professional SaaS background */
    --vault-surface: #f8fafc;
    --vault-surface-container-lowest: #ffffff;
    --vault-surface-container-low: #f1f5f9;
    --vault-surface-container: #e2e8f0;
    --vault-on-surface: #0f172a;
    --vault-on-surface-variant: #334155;
    --vault-outline-variant: #cbd5e1;
    --vault-outline: #64748b;
    --vault-primary: #0f172a;
    --vault-on-primary: #ffffff;
    --vault-primary-container: #1e293b;
    --vault-secondary: #475569;
    color-scheme: light;
}
.font-headline-md { font-family: 'Space Grotesk', sans-serif; }
.font-body-md { font-family: 'Inter', sans-serif; font-size: 0.95rem; }
.font-label-md { font-family: 'Inter', sans-serif; font-weight: 600; letter-spacing: 0.04em; }
.material-symbols-outlined {
    font-family: 'Material Symbols Outlined'; font-weight: normal; font-style: normal;
    font-size: 1.5rem; line-height: 1; letter-spacing: normal; text-transform: none;
    display: inline-block; white-space: nowrap; direction: ltr;
    -webkit-font-feature-settings: 'liga'; -webkit-font-smoothing: antialiased;
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}
</style>

