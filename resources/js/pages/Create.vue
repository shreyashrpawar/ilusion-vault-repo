<script setup lang="ts">
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, computed, watch } from 'vue';
import { useStorage } from '@vueuse/core';
import { toast } from 'vue-sonner';
import { Toaster } from '@/components/ui/sonner';
import { useConfirm } from '@/composables/useConfirm';
import ConfirmModal from '@/components/ConfirmModal.vue';
import {
    encryptText,
    encryptFile,
} from '@/lib/crypto';
import { login, home, logout } from '@/routes';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

const { confirm } = useConfirm();

interface GuestSecret {
    secret_id: string;
    identifier: string | null;
    url: string;
    expiry_date: string;
    burn_on_read: boolean;
    created_at: string;
}

const guestSecrets = useStorage<GuestSecret[]>('ilusion_guest_secrets', []);
const guestActiveSecrets = computed(() => {
    return guestSecrets.value.filter(s => new Date(s.expiry_date) > new Date());
});

const isGuestLimitReached = computed(() => {
    const isLogged = !!usePage().props.auth?.user;
    if (isLogged) return false;
    return guestActiveSecrets.value.length >= 3;
});

const payload = ref('');
const expiry = ref(usePage().props.auth?.user ? '7 Days' : '1 Day');

async function handleExpiryChange(e: Event) {
    const target = e.target as HTMLSelectElement;
    const newVal = target.value;
    const isLogged = !!usePage().props.auth?.user;
    
    if (!isLogged && ['15 Days', '7 Days', 'Never', 'No Expiry'].includes(newVal)) {
        target.value = expiry.value;
        const isConfirmed = await confirm({
            title: 'Login Required',
            message: 'Guest users can only set an expiry of 1 day or less. Please sign in to choose a longer duration.',
            confirmText: 'Sign In',
            cancelText: 'Cancel',
            type: 'info'
        });
        if (isConfirmed) {
            router.visit(login());
        }
    } else {
        expiry.value = newVal;
    }
}

async function handleRecipientEmailClick() {
    if (!usePage().props.auth?.user) {
        const isConfirmed = await confirm({
            title: 'Login Required',
            message: 'You must be signed in to send secrets directly to recipient emails.',
            confirmText: 'Sign In',
            cancelText: 'Cancel',
            type: 'info'
        });
        if (isConfirmed) {
            router.visit(login());
        }
    }
}

const password = ref('');
const showPassword = ref(false);
const showCreatedKey = ref(false);
const copiedKey = ref(false);

function generateRandomPassword() {
    password.value = generateRandomKey(16);
    showPassword.value = true;
}

function handleCopyKey() {
    navigator.clipboard.writeText(password.value);
    copiedKey.value = true;
    toast.success('Decryption key copied to clipboard.');
    setTimeout(() => {
        copiedKey.value = false;
    }, 2000);
}

const identifier = ref('');
const customAddress = ref('');
const showAdvanced = ref(false);
const recipientEmail = ref('');
const encryptionHint = ref('');
const burnOnRead = ref(false);

const attachedFiles = ref<File[]>([]);
const fileInputRef = ref<HTMLInputElement | null>(null);
const dragOver = ref(false);

const isCreated = ref(false);
const createdLink = ref('');
const copied = ref(false);

const activeTab = ref<'write' | 'preview'>('write');
const textareaRef = ref<HTMLTextAreaElement | null>(null);
const activeFocus = ref<string | null>(null);

function generateRandomKey(length = 16): string {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    const array = new Uint8Array(length);
    window.crypto.getRandomValues(array);
    let key = '';

    for (let i = 0; i < length; i++) {
        key += chars[array[i] % chars.length];
    }

    return key;
}

const charCount = computed(() => payload.value.length);
const charWarning = computed(() => charCount.value > 10000);

const compiledMarkdown = computed(() => {
    if (!payload.value.trim()) {
        return '<p class="text-vault-outline/70 italic select-none">No content to preview yet. Start typing in the "Write" tab...</p>';
    }

    const rawHtml = marked.parse(payload.value, { async: false }) as string;
    
    // Configure DOMPurify to be extremely strict but allow basic formatting
    const cleanHtml = DOMPurify.sanitize(rawHtml, {
        ALLOWED_TAGS: ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'strong', 'em', 'u', 'br', 'ul', 'ol', 'li', 'code', 'pre', 'blockquote', 'a'],
        ALLOWED_ATTR: ['href', 'target', 'rel']
    });

    return cleanHtml;
});

function insertMarkdown(syntax: string, placeholder = '') {
    const textarea = textareaRef.value;

    if (!textarea) {
return;
}

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = payload.value;

    const selectedText = text.substring(start, end) || placeholder;
    const replacement = syntax.replace('$', selectedText);

    payload.value = text.substring(0, start) + replacement + text.substring(end);

    setTimeout(() => {
        textarea.focus();
        const selectionStart = start + replacement.indexOf(selectedText);
        textarea.setSelectionRange(selectionStart, selectionStart + selectedText.length);
    }, 50);
}

const isSubmitting = ref(false);
const uploadProgress = ref(0);
const uploadStage = ref('');

async function handleCreateSecret() {
    if (!payload.value.trim() && attachedFiles.value.length === 0) {
        return;
    }

    if (!password.value.trim()) {
        toast.error('Please enter or generate a decryption key.');
        return;
    }

    isSubmitting.value = true;
    uploadProgress.value = 5;
    uploadStage.value = 'Encrypting secret payload...';

    try {
        const encKey = password.value;
        const encryptedTextJson = await encryptText(payload.value, encKey);

        const formData = new FormData();
        formData.append('payload', encryptedTextJson);
        formData.append('expiry', expiry.value);

        if (customAddress.value) {
            formData.append('custom_address', customAddress.value);
        }

        if (identifier.value) {
            formData.append('identifier', identifier.value);
        }

        formData.append('burn_on_read', burnOnRead.value ? '1' : '0');

        if (recipientEmail.value) {
            formData.append('recipient_email', recipientEmail.value);
        }

        if (encryptionHint.value) {
            const encryptedHint = await encryptText(encryptionHint.value, encKey);
            formData.append('encryption_hint', encryptedHint);
        }
        
        const fileMetadataArray: any[] = [];

        if (attachedFiles.value.length > 0) {
            for (let i = 0; i < attachedFiles.value.length; i++) {
                const file = attachedFiles.value[i];
                uploadStage.value = `Encrypting file ${i + 1} of ${attachedFiles.value.length} (${file.name})...`;
                uploadProgress.value = 10 + Math.round(((i + 1) / attachedFiles.value.length) * 20);
                const { encryptedBlob, metadata } = await encryptFile(file, encKey);
                formData.append('files[]', encryptedBlob, `file_${i}`);
                fileMetadataArray.push(metadata);
            }
        }
        
        if (fileMetadataArray.length > 0) {
            formData.append('file_metadata', JSON.stringify(fileMetadataArray));
        }

        uploadStage.value = 'Uploading encrypted secret...';
        uploadProgress.value = 30;

        const response = await axios.post('/api/secrets', formData, {
            headers: {
                'Accept': 'application/json'
            },
            onUploadProgress: (progressEvent) => {
                if (progressEvent.total) {
                    const percent = Math.round((progressEvent.loaded * 100) / progressEvent.total);
                    uploadProgress.value = 30 + Math.round(percent * 0.6);
                    uploadStage.value = `Uploading payload... ${percent}%`;
                }
            }
        });

        uploadStage.value = 'Complete!';
        uploadProgress.value = 100;
        await new Promise(resolve => setTimeout(resolve, 300));

        let finalUrl = response.data.url;

        createdSecretId.value = response.data.secret_id;
        createdLink.value = finalUrl;
        isCreated.value = true;
        
        if (!usePage().props.auth?.user) {
            guestSecrets.value.push({
                secret_id: response.data.secret_id,
                url: finalUrl,
                identifier: response.data.identifier,
                created_at: response.data.created_at,
                expiry_date: response.data.expiry_date,
                burn_on_read: response.data.burn_on_read,
            });
        }
    } catch (error: any) {
        console.error('Error creating secret:', error);
        if (error.response?.status === 422 && error.response?.data?.errors?.custom_address) {
            toast.error(error.response.data.errors.custom_address[0]);
        } else {
            toast.error(error.response?.data?.message || 'Failed to create secret.');
        }
    } finally {
        isSubmitting.value = false;
        uploadProgress.value = 0;
        uploadStage.value = '';
    }
}

const createdSecretId = ref('');
const isDeleting = ref(false);

async function handleDeleteSecret() {
    if (!createdSecretId.value) return;
    const isConfirmed = await confirm({
        title: 'Delete Secret',
        message: 'Are you sure you want to delete this secret? This action is permanent and will delete all associated files.',
        confirmText: 'Delete',
        cancelText: 'Keep it',
        type: 'danger'
    });
    if (isConfirmed) {
        isDeleting.value = true;
        try {
            await axios.delete(`/api/secrets/${createdSecretId.value}`);
            toast.success('Secret deleted successfully.');
            guestSecrets.value = guestSecrets.value.filter(s => s.secret_id !== createdSecretId.value);
            handleCreateAnother();
        } catch (error: any) {
            toast.error('Failed to delete secret: ' + (error.response?.data?.message || error.message));
        } finally {
            isDeleting.value = false;
        }
    }
}

function handleKeydown(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
        handleCreateSecret();
    }
}

function handleCopy() {
    navigator.clipboard.writeText(createdLink.value);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
}

function handleCreateAnother() {
    payload.value = '';
    password.value = '';
    identifier.value = '';
    customAddress.value = '';
    attachedFiles.value = [];
    recipientEmail.value = '';
    encryptionHint.value = '';
    showAdvanced.value = false;
    burnOnRead.value = false;
    activeTab.value = 'write';
    isCreated.value = false;
    createdLink.value = '';
    createdSecretId.value = '';
    showPassword.value = false;
    showCreatedKey.value = false;
    copiedKey.value = false;
}

async function triggerFileInput() {
    if (!usePage().props.auth?.user) {
        const isConfirmed = await confirm({
            title: 'Login Required',
            message: 'You must be signed in to add file attachments to your secrets.',
            confirmText: 'Sign In',
            cancelText: 'Cancel',
            type: 'info'
        });
        if (isConfirmed) {
            router.visit(login());
        }
        return;
    }
    fileInputRef.value?.click();
}

function handleFileSelect(e: Event) {
    if (!usePage().props.auth?.user) return;
    const target = e.target as HTMLInputElement;

    if (target.files) {
        addFiles(target.files);
    }
}

async function handleFileDrop(e: DragEvent) {
    dragOver.value = false;

    if (!usePage().props.auth?.user) {
        const isConfirmed = await confirm({
            title: 'Login Required',
            message: 'You must be signed in to add file attachments to your secrets.',
            confirmText: 'Sign In',
            cancelText: 'Cancel',
            type: 'info'
        });
        if (isConfirmed) {
            router.visit(login());
        }
        return;
    }

    if (e.dataTransfer?.files) {
        addFiles(e.dataTransfer.files);
    }
}

function addFiles(fileList: FileList) {
    const maxSize = 100 * 1024 * 1024;
    
    for (let i = 0; i < fileList.length; i++) {
        const file = fileList[i];

        if (file.size > maxSize) {
            toast.warning(`File ${file.name} is too large. Maximum size is 100MB.`);
            continue;
        }

        const alreadyExists = attachedFiles.value.some(f => f.name === file.name && f.size === file.size);

        if (!alreadyExists) {
            attachedFiles.value.push(file);
        }
    }
}

function removeFile(index: number) {
    attachedFiles.value.splice(index, 1);
}

function formatBytes(bytes: number, decimals = 1) {
    if (bytes === 0) {
return '0 Bytes';
}

    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

function formatExpiryDate(dateStr?: string) {
    if (!dateStr) {
return '';
}

    try {
        const date = new Date(dateStr);

        return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch {
        return '';
    }
}
</script>

<template>
    <Head title="Create Encrypted Secret | Ilusion Vault">
        <meta name="description" content="Encrypt and store texts, keys, passwords, and files securely. Generate zero-knowledge sharing links." />
    </Head>

    <div class="vault-light bg-vault-background text-vault-on-background min-h-screen flex flex-col font-body-md antialiased selection:bg-[#e4e4e7] selection:text-[#18181b]">
        
        <!-- App Navbar (SaaS Style - Consistent with Dashboard) -->
        <header class="sticky top-0 z-50 bg-vault-surface-container-lowest border-b border-vault-outline-variant shadow-sm px-4 sm:px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-6">
                <Link href="/" class="flex items-center gap-2 group">
                    <img src="/ilusion-logo.png" alt="Ilusion" class="w-7 h-7 object-contain group-hover:scale-105 transition-transform" />
                    <span class="font-headline-md font-bold text-lg tracking-tight text-vault-on-surface">Ilusion Vault</span>
                </Link>

                <nav class="hidden md:flex items-center gap-2 ml-4">
                    <Link href="/" class="px-3 py-1.5 rounded-md text-sm font-medium text-vault-on-surface-variant hover:text-vault-on-surface hover:bg-vault-surface-container-low transition-colors flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[1rem]">arrow_back</span> Dashboard
                    </Link>
                </nav>
            </div>

            <div class="flex items-center gap-4">
                <template v-if="$page.props.auth?.user">
                    <div class="hidden sm:flex items-center gap-3 mr-2">
                        <div class="text-right">
                            <p class="text-sm font-medium text-vault-on-surface leading-none">{{ $page.props.auth.user.name }}</p>
                            <p class="text-xs text-vault-on-surface-variant mt-1">{{ $page.props.auth.user.email }}</p>
                        </div>
                        <div class="w-9 h-9 rounded-full bg-vault-primary text-vault-on-primary flex items-center justify-center font-bold text-sm shadow-sm select-none">
                            {{ $page.props.auth.user.name ? $page.props.auth.user.name[0].toUpperCase() : 'V' }}
                        </div>
                    </div>
                    <div class="h-6 w-px bg-vault-outline-variant hidden sm:block"></div>
                    <Link href="/settings" class="text-vault-secondary hover:text-vault-on-surface transition-colors p-1 flex items-center justify-center" title="Settings">
                        <span class="material-symbols-outlined text-[1.25rem]">settings</span>
                    </Link>
                    <Link :href="logout().url" method="post" as="button" class="text-vault-secondary hover:text-vault-on-surface transition-colors p-1 flex items-center justify-center" title="Log Out">
                        <span class="material-symbols-outlined text-[1.25rem]">logout</span>
                    </Link>
                </template>
                <template v-else>
                    <Link :href="login()" class="bg-vault-primary text-vault-on-primary font-label-md text-xs py-2 px-4 rounded-lg hover:bg-vault-primary-container transition-colors shadow-sm">
                        Sign In
                    </Link>
                </template>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-grow w-full max-w-4xl mx-auto px-4 sm:px-6 py-8 flex flex-col gap-6 animate-in fade-in duration-300">
            
            <!-- Page Heading -->
            <div class="flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <Link href="/" class="inline-flex items-center gap-1.5 text-xs font-semibold text-vault-primary hover:text-vault-primary-container transition-colors">
                        <span class="material-symbols-outlined text-[1rem]">west</span> Back to Dashboard
                    </Link>
                    <span class="text-xs font-mono text-vault-outline bg-vault-surface-container px-2 py-0.5 rounded">Zero-Knowledge AES-256</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-bold text-vault-on-surface font-headline-md tracking-tight mt-2">Create Encrypted Secret</h1>
                <p class="text-sm text-vault-on-surface-variant">Payloads are encrypted in your browser before uploading to our server.</p>
            </div>

            <!-- Form Card -->
            <div class="bg-vault-surface-container-lowest border border-vault-outline-variant rounded-2xl p-6 sm:p-8 shadow-sm">
                
                <!-- Guest Limit Notice -->
                <div v-if="isGuestLimitReached" class="bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/50 rounded-2xl p-8 text-center flex flex-col items-center justify-center">
                    <span class="material-symbols-outlined text-4xl text-red-500 mb-3">lock_person</span>
                    <h3 class="text-xl font-bold text-red-700 dark:text-red-400 mb-2 font-headline-md">Guest Limit Reached</h3>
                    <p class="text-sm text-red-600 dark:text-red-300 max-w-md mx-auto mb-6">You have reached the maximum of 3 active secrets allowed for guest users. Create a free account to manage more secrets.</p>
                    <div class="flex flex-col sm:flex-row items-center gap-3">
                        <Link href="/register" class="bg-vault-primary text-vault-on-primary font-label-md text-xs py-2.5 px-6 rounded-lg shadow-sm hover:bg-vault-primary-container transition-colors">Create Free Account</Link>
                        <Link href="/login" class="bg-vault-surface-container border border-vault-outline-variant text-vault-on-surface font-label-md text-xs py-2.5 px-6 rounded-lg hover:bg-vault-outline-variant/30 transition-colors">Sign In</Link>
                    </div>
                </div>

                <!-- Create Form -->
                <form v-else-if="!isCreated" @submit.prevent="handleCreateSecret" class="flex flex-col gap-6">
                    
                    <!-- Payload Text Area -->
                    <div class="flex flex-col gap-2">
                        <div class="flex justify-between items-center select-none">
                            <label class="text-[0.6875rem] font-medium uppercase tracking-wider text-vault-on-surface-variant" for="secret-content">Secret Content</label>

                            <div class="flex items-center gap-3">
                                <div v-show="activeTab === 'write'" class="hidden sm:flex items-center gap-2 text-vault-outline">
                                    <button type="button" @click="insertMarkdown('**$**', 'bold')" class="hover:text-vault-primary transition-colors font-bold text-[0.75rem] px-1 py-0.5 rounded hover:bg-vault-surface-container" title="Bold">B</button>
                                    <button type="button" @click="insertMarkdown('*$*', 'italic')" class="hover:text-vault-primary transition-colors italic text-[0.75rem] px-1 py-0.5 rounded hover:bg-vault-surface-container" title="Italic">I</button>
                                    <button type="button" @click="insertMarkdown('# $', 'Heading')" class="hover:text-vault-primary transition-colors text-[0.75rem] px-1 py-0.5 rounded hover:bg-vault-surface-container" title="Heading">H</button>
                                    <button type="button" @click="insertMarkdown('```\n$\n```', 'code')" class="hover:text-vault-primary transition-colors text-[0.75rem] px-1 py-0.5 font-mono rounded hover:bg-vault-surface-container" title="Code Block">&lt;/&gt;</button>
                                </div>
                                <span v-show="activeTab === 'write'" class="text-vault-outline-variant hidden sm:inline">|</span>
                                <div class="flex bg-vault-surface-container p-0.5 rounded-lg border border-vault-outline-variant">
                                    <button
                                        type="button"
                                        @click="activeTab = 'write'"
                                        class="px-2.5 py-1 rounded-md text-xs font-medium transition-all"
                                        :class="activeTab === 'write' ? 'bg-vault-surface-container-lowest text-vault-on-surface shadow-xs' : 'text-vault-on-surface-variant hover:text-vault-on-surface'"
                                    >
                                        Write
                                    </button>
                                    <button
                                        type="button"
                                        @click="activeTab = 'preview'"
                                        class="px-2.5 py-1 rounded-md text-xs font-medium transition-all"
                                        :class="activeTab === 'preview' ? 'bg-vault-surface-container-lowest text-vault-on-surface shadow-xs' : 'text-vault-on-surface-variant hover:text-vault-on-surface'"
                                    >
                                        Preview
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="relative">
                            <textarea
                                v-show="activeTab === 'write'"
                                ref="textareaRef"
                                v-model="payload"
                                id="secret-content"
                                @keydown="handleKeydown"
                                class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-xl p-4 font-mono text-sm text-vault-on-surface focus:outline-none focus:ring-2 focus:ring-vault-primary focus:border-transparent transition-all resize-none placeholder:text-vault-outline h-48"
                                placeholder="Enter passwords, API keys, private text, or Markdown to encrypt..."
                                autocomplete="off"
                                spellcheck="false"
                                required
                            ></textarea>
                            <div v-show="activeTab === 'write' && charCount > 0" class="absolute bottom-3 right-4 font-mono text-[0.6875rem] select-none pointer-events-none" :class="charWarning ? 'text-vault-error font-bold' : 'text-vault-on-surface-variant/60'">{{ charCount.toLocaleString() }} chars</div>

                            <div
                                v-show="activeTab === 'preview'"
                                class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-xl p-4 font-body-md text-sm text-vault-on-surface overflow-y-auto select-text h-48 preview-container"
                                v-html="compiledMarkdown"
                            ></div>
                        </div>
                    </div>

                    <!-- Main Parameters Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-5 pt-4 border-t border-vault-outline-variant">
                        <!-- Expiry Select -->
                        <div class="flex flex-col gap-2 md:col-span-4">
                            <label class="text-[0.6875rem] font-medium uppercase tracking-wider text-vault-on-surface-variant select-none" for="expiry">Expiry Duration</label>
                            <div class="relative">
                                <select
                                    :value="expiry"
                                    @change="handleExpiryChange($event)"
                                    id="expiry"
                                    class="w-full appearance-none bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg py-2.5 pl-4 pr-10 text-sm text-vault-on-surface focus:outline-none focus:ring-2 focus:ring-vault-primary focus:border-transparent transition-all cursor-pointer"
                                >
                                    <option>No Expiry</option>
                                    <option>Never</option>
                                    <option>15 Days</option>
                                    <option>7 Days</option>
                                    <option>1 Day</option>
                                    <option>1 Hour</option>
                                </select>
                                <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-vault-outline pointer-events-none text-[1.25rem]">expand_more</span>
                            </div>
                        </div>

                        <!-- Burn on View Switch -->
                        <div class="flex flex-col gap-2 md:col-span-2">
                            <label class="text-[0.6875rem] font-medium uppercase tracking-wider text-vault-on-surface-variant select-none">Burn on Read</label>
                            <div class="flex items-center h-[2.625rem]">
                                <button
                                    type="button"
                                    @click="burnOnRead = !burnOnRead"
                                    :class="burnOnRead ? 'bg-vault-primary' : 'bg-vault-surface-container-high'"
                                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-vault-primary"
                                    role="switch"
                                    :aria-checked="burnOnRead"
                                >
                                    <span
                                        :class="burnOnRead ? 'translate-x-5' : 'translate-x-0'"
                                        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                    ></span>
                                </button>
                                <span class="text-xs font-medium text-vault-on-surface ml-2.5 select-none">
                                    {{ burnOnRead ? 'Yes' : 'No' }}
                                </span>
                            </div>
                        </div>

                        <!-- Decryption Key Input -->
                        <div class="flex flex-col gap-2 md:col-span-6">
                            <div class="flex items-center justify-between">
                                <label class="text-[0.6875rem] font-medium uppercase tracking-wider text-vault-on-surface-variant select-none" for="password">Decryption Key</label>
                                <button
                                    type="button"
                                    @click="generateRandomPassword"
                                    class="text-xs font-semibold text-vault-primary hover:text-vault-primary-container transition-colors flex items-center gap-1"
                                >
                                    <span class="material-symbols-outlined text-[0.875rem]">casino</span> Generate
                                </button>
                            </div>
                            <div class="relative">
                                <input
                                    v-model="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    id="password"
                                    autocomplete="new-password"
                                    class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg py-2.5 pl-4 pr-12 text-sm font-mono text-vault-on-surface focus:outline-none focus:ring-2 focus:ring-vault-primary focus:border-transparent transition-all placeholder:text-vault-outline"
                                    placeholder="Enter or generate a key"
                                    required
                                />
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-vault-outline hover:text-vault-on-surface transition-colors flex items-center"
                                >
                                    <span class="material-symbols-outlined text-[1.25rem]">
                                        {{ showPassword ? 'visibility_off' : 'visibility' }}
                                    </span>
                                </button>
                            </div>
                        </div>

                        <!-- Identifier -->
                        <div class="flex flex-col gap-2 md:col-span-6">
                            <label class="text-[0.6875rem] font-medium uppercase tracking-wider text-vault-on-surface-variant select-none" for="identifier">Secret Identifier (Optional)</label>
                            <input
                                v-model="identifier"
                                type="text"
                                id="identifier"
                                class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg py-2.5 px-4 text-sm text-vault-on-surface focus:outline-none focus:ring-2 focus:ring-vault-primary focus:border-transparent transition-all placeholder:text-vault-outline"
                                placeholder="e.g. Production Database Password"
                            />
                        </div>

                        <!-- Custom Slug -->
                        <div class="flex flex-col gap-2 md:col-span-6 relative">
                            <div class="flex items-center justify-between">
                                <label class="text-[0.6875rem] font-medium uppercase tracking-wider text-vault-on-surface-variant select-none" for="custom-address">Custom URL Slug (Optional)</label>
                                <span v-if="!$page.props.auth?.user" class="text-[0.625rem] text-vault-primary font-bold uppercase tracking-wider">Login Required</span>
                            </div>
                            <input
                                v-model="customAddress"
                                type="text"
                                id="custom-address"
                                :disabled="!$page.props.auth?.user"
                                minlength="5"
                                pattern="[a-zA-Z0-9\-]+"
                                :class="{'opacity-60 cursor-not-allowed': !$page.props.auth?.user}"
                                class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg py-2.5 px-4 text-sm text-vault-on-surface focus:outline-none focus:ring-2 focus:ring-vault-primary focus:border-transparent transition-all placeholder:text-vault-outline disabled:bg-vault-surface-container-low"
                                placeholder="e.g. my-custom-secret"
                            />
                        </div>
                    </div>

                    <!-- File Attachments -->
                    <div class="pt-4 border-t border-vault-outline-variant flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <label class="text-[0.6875rem] font-medium uppercase tracking-wider text-vault-on-surface-variant select-none">File Attachments (Optional)</label>
                            <span v-if="!$page.props.auth?.user" class="text-[0.625rem] text-vault-primary font-bold uppercase tracking-wider">Login Required</span>
                        </div>
                        <div 
                            class="border border-dashed border-vault-outline-variant rounded-xl p-5 flex flex-col items-center justify-center bg-vault-surface-container-lowest hover:bg-vault-surface-container/30 hover:border-vault-primary/60 transition-all cursor-pointer select-none"
                            @click="triggerFileInput"
                            @dragover.prevent="!$page.props.auth?.user ? null : dragOver = true"
                            @dragleave.prevent="dragOver = false"
                            @drop.prevent="handleFileDrop"
                            :class="{ 'border-vault-primary bg-vault-primary/5': dragOver, 'opacity-60': !$page.props.auth?.user }"
                        >
                            <input 
                                type="file" 
                                ref="fileInputRef" 
                                class="hidden" 
                                @change="handleFileSelect" 
                                multiple
                            />
                            <div class="flex items-center gap-2 text-vault-on-surface-variant">
                                <span class="material-symbols-outlined text-vault-primary text-[1.5rem]">upload_file</span>
                                <span v-if="!$page.props.auth?.user" class="text-sm">Sign in to attach encrypted files</span>
                                <span v-else class="text-sm">Drag & drop files here, or <span class="text-vault-primary font-medium underline">browse</span></span>
                            </div>
                            <p class="text-xs text-vault-on-surface-variant/70 mt-1">Files are encrypted client-side using AES-256 (up to 100MB per file)</p>
                        </div>

                        <!-- Selected Files Chips -->
                        <div v-if="attachedFiles.length > 0" class="flex flex-col gap-2 mt-2">
                            <div 
                                v-for="(file, index) in attachedFiles" 
                                :key="index"
                                class="flex items-center justify-between bg-vault-surface-container-low border border-vault-outline-variant/60 rounded-lg px-3.5 py-2 text-vault-on-surface text-sm"
                            >
                                <div class="flex items-center gap-2.5 overflow-hidden mr-4">
                                    <span class="material-symbols-outlined text-vault-outline text-[1.125rem]">description</span>
                                    <span class="truncate font-medium">{{ file.name }}</span>
                                    <span class="text-vault-on-surface-variant text-xs font-mono">({{ formatBytes(file.size) }})</span>
                                </div>
                                <button 
                                    type="button" 
                                    @click="removeFile(index)" 
                                    class="text-vault-outline hover:text-red-600 transition-colors p-1 rounded-full"
                                    title="Remove file"
                                >
                                    <span class="material-symbols-outlined text-[1.125rem]">close</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Section Toggle -->
                    <div v-show="showAdvanced" class="pt-4 border-t border-vault-outline-variant grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="flex flex-col gap-2 relative">
                            <label class="text-[0.6875rem] font-medium uppercase tracking-wider text-vault-on-surface-variant select-none" for="recipient-email">Recipient Email(s)</label>
                            <input
                                v-model="recipientEmail"
                                type="text"
                                id="recipient-email"
                                :disabled="!$page.props.auth?.user"
                                class="w-full border border-vault-outline-variant rounded-lg py-2.5 px-4 text-sm text-vault-on-surface focus:outline-none focus:ring-2 focus:ring-vault-primary focus:border-transparent transition-all placeholder:text-vault-outline bg-vault-surface-container-lowest disabled:opacity-50"
                                :placeholder="!$page.props.auth?.user ? 'Sign in to send email notifications' : 'e.g. recipient@example.com'"
                            />
                        </div>
                        <div class="flex flex-col gap-2">
                            <label class="text-[0.6875rem] font-medium uppercase tracking-wider text-vault-on-surface-variant select-none" for="encryption-hint">Encryption Hint</label>
                            <input
                                v-model="encryptionHint"
                                type="text"
                                id="encryption-hint"
                                class="w-full border border-vault-outline-variant rounded-lg py-2.5 px-4 text-sm text-vault-on-surface focus:outline-none focus:ring-2 focus:ring-vault-primary focus:border-transparent transition-all placeholder:text-vault-outline bg-vault-surface-container-lowest"
                                placeholder="Hint to help recipient recall passphrase"
                            />
                        </div>
                    </div>

                    <!-- Footer Actions -->
                    <div class="pt-4 border-t border-vault-outline-variant flex flex-col sm:flex-row justify-between items-center gap-4">
                        <button
                            type="button"
                            @click="showAdvanced = !showAdvanced"
                            class="w-full sm:w-auto text-xs font-semibold text-vault-on-surface-variant hover:text-vault-on-surface transition-colors inline-flex items-center gap-1.5"
                        >
                            <span class="material-symbols-outlined text-[1.125rem]" :class="{ 'rotate-180': showAdvanced }">expand_more</span>
                            {{ showAdvanced ? 'Hide Advanced Options' : 'Show Advanced Options' }}
                        </button>

                        <div v-if="isSubmitting" class="w-full sm:w-80 bg-vault-surface-container-low border border-vault-outline-variant rounded-lg p-3 flex flex-col gap-1.5 shadow-sm animate-in fade-in duration-200">
                            <div class="flex justify-between items-center text-xs text-vault-on-surface">
                                <span class="font-medium flex items-center gap-1.5 truncate max-w-[14rem]">
                                    <span class="material-symbols-outlined text-[1rem] animate-spin text-vault-primary shrink-0">sync</span>
                                    <span class="truncate">{{ uploadStage }}</span>
                                </span>
                                <span class="font-mono font-bold text-vault-primary shrink-0">{{ uploadProgress }}%</span>
                            </div>
                            <div class="w-full bg-vault-surface-container-high rounded-full h-2 overflow-hidden">
                                <div class="bg-vault-primary h-2 rounded-full transition-all duration-200 ease-out" :style="{ width: uploadProgress + '%' }"></div>
                            </div>
                        </div>
                        <button
                            v-else
                            type="submit"
                            :disabled="!payload.trim() && attachedFiles.length === 0"
                            class="w-full sm:w-auto bg-vault-primary text-vault-on-primary font-label-md text-sm py-3 px-8 rounded-lg shadow-sm hover:bg-vault-primary-container transition-all active:scale-[0.98] flex items-center justify-center gap-2 disabled:opacity-60"
                        >
                            <span class="material-symbols-outlined text-[1.125rem]">lock</span>
                            Encrypt & Create Secret Link
                        </button>
                    </div>
                </form>

                <!-- Secret Created Result Card -->
                <div v-else class="flex flex-col gap-6 animate-in fade-in duration-300">
                    <div class="flex items-center gap-3 text-vault-primary">
                        <span class="material-symbols-outlined text-3xl">verified</span>
                        <div>
                            <h2 class="text-xl font-bold text-vault-on-surface font-headline-md tracking-tight">Secret Link Generated Successfully!</h2>
                            <p class="text-xs text-vault-on-surface-variant mt-0.5">Your secret was encrypted locally before being transmitted to the server.</p>
                        </div>
                    </div>

                    <div class="flex flex-col md:flex-row gap-6 items-center bg-vault-surface-container/30 p-6 rounded-xl border border-vault-outline-variant">
                        <div class="flex-shrink-0 bg-white p-3 rounded-xl border border-vault-outline-variant shadow-sm flex items-center justify-center">
                            <img :src="`https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(createdLink)}&bgcolor=ffffff&color=000000`" alt="Secret QR Code" class="w-32 h-32" />
                        </div>

                        <div class="flex flex-col gap-4 w-full">
                            <!-- Share Link Box -->
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[0.6875rem] font-medium uppercase tracking-wider text-vault-on-surface-variant select-none">Encrypted Secret URL</label>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <input
                                        readonly
                                        :value="createdLink"
                                        class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg py-2.5 px-4 font-mono text-xs text-vault-on-surface focus:outline-none"
                                    />
                                    <button
                                        @click="handleCopy"
                                        type="button"
                                        class="bg-vault-primary text-vault-on-primary font-label-md text-xs px-5 py-2.5 rounded-lg hover:bg-vault-primary-container transition-colors flex items-center justify-center gap-1.5 whitespace-nowrap shadow-xs"
                                    >
                                        <span class="material-symbols-outlined text-[1rem]">{{ copied ? 'done' : 'content_copy' }}</span>
                                        {{ copied ? 'Copied' : 'Copy Link' }}
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Decryption Key Box -->
                            <div class="flex flex-col gap-1.5 pt-3 border-t border-vault-outline-variant/60">
                                <label class="text-[0.6875rem] font-medium uppercase tracking-wider text-vault-on-surface-variant select-none">Decryption Passphrase / Key</label>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <div class="relative w-full">
                                        <input
                                            readonly
                                            :type="showCreatedKey ? 'text' : 'password'"
                                            :value="password"
                                            class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-lg py-2.5 pl-4 pr-10 font-mono text-xs text-vault-on-surface focus:outline-none"
                                        />
                                        <button
                                            type="button"
                                            @click="showCreatedKey = !showCreatedKey"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-vault-outline hover:text-vault-on-surface transition-colors"
                                        >
                                            <span class="material-symbols-outlined text-[1.125rem]">
                                                {{ showCreatedKey ? 'visibility_off' : 'visibility' }}
                                            </span>
                                        </button>
                                    </div>
                                    <button
                                        @click="handleCopyKey"
                                        type="button"
                                        class="bg-vault-surface-container border border-vault-outline-variant text-vault-on-surface font-label-md text-xs px-5 py-2.5 rounded-lg hover:bg-vault-outline-variant/30 transition-colors flex items-center justify-center gap-1.5 whitespace-nowrap"
                                    >
                                        <span class="material-symbols-outlined text-[1rem]">{{ copiedKey ? 'done' : 'content_copy' }}</span>
                                        {{ copiedKey ? 'Copied' : 'Copy Key' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-900/50 p-4 rounded-xl text-xs text-blue-800 dark:text-blue-300 flex items-start gap-2.5">
                        <span class="material-symbols-outlined text-[1.25rem] text-blue-600 mt-0.5">info</span>
                        <div>
                            <p class="font-bold">Zero-Knowledge Storage</p>
                            <p class="mt-0.5">The decryption key is never stored on our servers. Be sure to share both the link and key with your recipient.</p>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-vault-outline-variant flex flex-col sm:flex-row justify-end gap-3 w-full">
                        <button
                            @click="handleDeleteSecret"
                            :disabled="isDeleting"
                            type="button"
                            class="bg-red-50 hover:bg-red-100 border border-red-200 text-red-700 font-label-md text-xs py-2.5 px-5 rounded-lg transition-colors flex items-center justify-center gap-1.5"
                        >
                            <span v-if="!isDeleting" class="material-symbols-outlined text-[1rem]">delete</span>
                            <span v-else class="material-symbols-outlined text-[1rem] animate-spin">progress_activity</span>
                            Delete Secret
                        </button>
                        <Link
                            href="/"
                            class="bg-vault-surface-container border border-vault-outline-variant text-vault-on-surface font-label-md text-xs py-2.5 px-5 rounded-lg hover:bg-vault-outline-variant/30 transition-colors text-center flex items-center justify-center"
                        >
                            Back to Dashboard
                        </Link>
                        <button
                            @click="handleCreateAnother"
                            type="button"
                            class="bg-vault-primary text-vault-on-primary font-label-md text-xs py-2.5 px-5 rounded-lg hover:bg-vault-primary-container transition-colors flex items-center justify-center gap-1.5"
                        >
                            <span class="material-symbols-outlined text-[1rem]">add</span> Create Another
                        </button>
                    </div>
                </div>
            </div>
        </main>

        <footer class="w-full py-6 md:py-8 px-4 sm:px-6 md:px-12 flex flex-col md:flex-row justify-between items-center gap-6 bg-vault-surface border-t border-vault-outline-variant z-10 relative mt-auto">
            <div class="flex items-center gap-2 font-label-md text-label-md uppercase tracking-widest text-vault-on-surface">
                <img src="/ilusion-logo.png" alt="Ilusion" class="w-8 h-8 object-contain opacity-80" />
                Ilusion
            </div>
            <div class="flex gap-6 flex-wrap justify-center">
                <Link class="font-label-sm text-label-sm text-vault-on-secondary-container hover:text-vault-on-surface transition-colors duration-200" href="/contact">Contact</Link>
                <Link class="font-label-sm text-label-sm text-vault-on-secondary-container hover:text-vault-on-surface transition-colors duration-200" href="/vs/bitwarden-send">vs Bitwarden</Link>
                <Link class="font-label-sm text-label-sm text-vault-on-secondary-container hover:text-vault-on-surface transition-colors duration-200" href="/vs/firefox-send">vs Firefox Send</Link>
                <Link class="font-label-sm text-label-sm text-vault-on-secondary-container hover:text-vault-on-surface transition-colors duration-200" href="/vs/1password-send">vs 1Password</Link>
            </div>
            <div class="flex items-center gap-4 font-label-sm text-label-sm text-vault-secondary">
                <span>© 2026 Ilusion Vault</span>
                <a href="https://github.com/ilusion-io/ilusion-vault" target="_blank" rel="noopener noreferrer" class="hover:text-vault-on-surface transition-colors duration-200" title="GitHub">
                    <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>
                </a>
                <a href="https://in.linkedin.com/company/ilusion-io" target="_blank" rel="noopener noreferrer" class="hover:text-vault-on-surface transition-colors duration-200" title="LinkedIn">
                    <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.779-1.75-1.75s.784-1.75 1.75-1.75 1.75.779 1.75 1.75-.784 1.75-1.75 1.75zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                </a>
            </div>
        </footer>

        <ConfirmModal />
        <Toaster />
    </div>
</template>

<style scoped>
.font-display {
    font-family: 'Inter', sans-serif;
    font-size: 3rem;
    font-weight: 700;
    line-height: 1.0;
    letter-spacing: -0.04em;
}
.font-headline-md {
    font-family: 'Inter', sans-serif;
    font-size: 1.25rem;
    font-weight: 600;
    line-height: 1.2;
    letter-spacing: -0.01em;
}
.font-body-md {
    font-family: 'Inter', sans-serif;
    font-size: 0.875rem;
    font-weight: 400;
    line-height: 1.5;
}
.font-label-md {
    font-family: 'Inter', sans-serif;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
    letter-spacing: 0.08em;
}
.font-label-sm {
    font-family: 'Inter', sans-serif;
    font-size: 0.625rem;
    font-weight: 600;
    line-height: 1;
    letter-spacing: 0.1em;
}
.font-mono-custom {
    font-family: 'JetBrains Mono', monospace;
}

@media (max-width: 768px) {
    .font-display { font-size: 2rem; }
}

.material-symbols-outlined {
    font-family: 'Material Symbols Outlined';
    font-weight: normal;
    font-style: normal;
    font-size: 1.5rem;
    line-height: 1;
    letter-spacing: normal;
    text-transform: none;
    display: inline-block;
    white-space: nowrap;
    word-wrap: normal;
    direction: ltr;
    -webkit-font-feature-settings: 'liga';
    -webkit-font-smoothing: antialiased;
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}

.vault-light {
    --vault-background: #f7f9fb;
    --vault-surface: #f7f9fb;
    --vault-surface-container-lowest: #ffffff;
    --vault-surface-container-low: #f2f4f6;
    --vault-surface-container: #eceef0;
    --vault-surface-container-high: #e6e8ea;
    --vault-on-surface: #191c1e;
    --vault-on-surface-variant: #434655;
    --vault-outline-variant: #c3c6d7;
    --vault-outline: #737686;
    --vault-primary: #18181b;
    --vault-on-primary: #ffffff;
    --vault-primary-container: #27272a;
    --vault-secondary: #565e74;
    --color-vault-background: #f7f9fb;
    --color-vault-surface: #f7f9fb;
    --color-vault-surface-container-lowest: #ffffff;
    --color-vault-surface-container-low: #f2f4f6;
    --color-vault-surface-container: #eceef0;
    --color-vault-surface-container-high: #e6e8ea;
    --color-vault-on-surface: #191c1e;
    --color-vault-on-surface-variant: #434655;
    --color-vault-outline-variant: #c3c6d7;
    --color-vault-outline: #737686;
    --color-vault-primary: #18181b;
    --color-vault-on-primary: #ffffff;
    --color-vault-primary-container: #27272a;
    --color-vault-secondary: #565e74;
    --color-vault-on-secondary-container: #5c647a;
    --color-vault-on-background: #191c1e;
    --color-vault-on-primary-fixed: #00174b;
    --color-vault-primary-fixed: #dbe1ff;
    color-scheme: light;
}

.bg-dot-grid {
    background-image: radial-gradient(#c3c6d7 1px, transparent 1px);
    background-size: 24px 24px;
    background-position: -11px -11px;
    opacity: 0.3;
}

.preview-container :deep(h1) {
    font-size: 1.25rem;
    font-weight: 700;
    margin-top: 16px;
    margin-bottom: 8px;
    border-bottom: 1px solid var(--color-vault-outline-variant);
    padding-bottom: 4px;
    color: var(--color-vault-on-surface);
}
.preview-container :deep(h2) {
    font-size: 1.125rem;
    font-weight: 700;
    margin-top: 14px;
    margin-bottom: 8px;
    color: var(--color-vault-on-surface);
}
.preview-container :deep(h3) {
    font-size: 0.9375rem;
    font-weight: 600;
    margin-top: 12px;
    margin-bottom: 6px;
    color: var(--color-vault-on-surface);
}
.preview-container :deep(pre) {
    background-color: var(--color-vault-surface-container);
    border: 1px solid var(--color-vault-outline-variant);
    border-radius: 4px;
    padding: 12px;
    margin: 12px 0;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem;
    overflow-x: auto;
}
.preview-container :deep(code) {
    background-color: var(--color-vault-surface-container-low);
    padding: 2px 6px;
    border-radius: 4px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem;
    color: var(--color-vault-primary);
}
.preview-container :deep(ul) {
    list-style-type: disc;
    margin-left: 20px;
    margin-top: 8px;
    margin-bottom: 8px;
}
.preview-container :deep(li) {
    margin-bottom: 4px;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(4px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
