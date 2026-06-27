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
import { profile, login, home, logout } from '@/routes';
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

async function handleCreateSecret() {
    if (!payload.value.trim() && attachedFiles.value.length === 0) {
        return;
    }

    if (!password.value.trim()) {
        toast.error('Please enter or generate a decryption key.');
        return;
    }

    isSubmitting.value = true;

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

        for (let i = 0; i < attachedFiles.value.length; i++) {
            const file = attachedFiles.value[i];
            const { encryptedBlob, metadata } = await encryptFile(file, encKey);
            formData.append('files[]', encryptedBlob, `file_${i}`);
            fileMetadataArray.push(metadata);
        }
        
        if (fileMetadataArray.length > 0) {
            formData.append('file_metadata', JSON.stringify(fileMetadataArray));
        }

        const response = await axios.post('/api/secrets', formData, {
            headers: {
                'Accept': 'application/json'
            }
        });

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
            toast.error('Failed to create secret. ' + (error.response?.data?.message || error.message));
        }
    } finally {
        isSubmitting.value = false;
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
    <Head title="Create Encrypted Vault & Secure Link | Ilusion Vault">
        <meta name="description" content="Encrypt and store texts, keys, passwords, and files securely. Generate zero-knowledge sharing links." />
    </Head>

    <div class="vault-light bg-vault-background text-vault-on-background min-h-screen flex flex-col font-body-md antialiased selection:bg-[#dbe1ff] selection:text-[#00174b] relative overflow-x-hidden">
        <div class="absolute inset-0 bg-dot-grid pointer-events-none z-0"></div>

        <header class="fixed top-0 left-0 right-0 z-50 flex justify-between items-center px-4 sm:px-6 md:px-12 py-3 md:py-4 bg-vault-surface/80 backdrop-blur-xl border-b border-vault-outline-variant shadow-sm transition-all duration-300">
            <div class="flex items-center gap-4 sm:gap-8 max-w-[75rem] w-full mx-auto">
                <div class="flex items-center gap-4 sm:gap-8 flex-1">
                    <Link class="flex items-center gap-2 font-headline-md text-headline-md font-bold text-vault-on-surface hover:opacity-90" :href="home()">
                        <img src="/ilusion-logo.png" alt="Ilusion" class="w-10 h-10 md:w-12 md:h-12 object-contain" />
                        Ilusion
                    </Link>
                </div>
                <div class="flex items-center gap-3">
                    <Link href="/contact" class="font-label-md text-label-md text-vault-on-surface hover:text-vault-primary transition-colors duration-200 mr-2 uppercase tracking-widest hidden sm:inline-block border-b-2 border-transparent hover:border-vault-primary">Contact</Link>
                    <template v-if="$page.props.auth?.user">
                        <span class="font-body-md text-body-md text-vault-on-surface-variant select-none hidden sm:flex items-center mr-2 gap-1.5">
                            Hello <span class="font-semibold text-vault-on-surface">{{ $page.props.auth.user.name }}</span>
                        </span>
                        <Link
                            :href="profile()"
                            class="bg-vault-surface-container-lowest border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-2 px-4 rounded hover:bg-vault-surface-container-low transition-colors duration-200 scale-95 hover:scale-100 ease-in-out inline-flex items-center justify-center mr-2"
                        >
                            Profile
                        </Link>
                        <Link
                            :href="logout().url"
                            method="post"
                            as="button"
                            class="bg-vault-surface-container-lowest border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-2 px-4 rounded hover:bg-vault-surface-container-low transition-colors duration-200 scale-95 hover:scale-100 ease-in-out inline-flex items-center justify-center"
                        >
                            Logout
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="bg-vault-surface-container-lowest border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-2 px-4 rounded hover:bg-vault-surface-container-low transition-colors duration-200 scale-95 hover:scale-100 ease-in-out inline-flex items-center justify-center"
                        >
                            Sign In
                        </Link>
                    </template>
                </div>
            </div>
        </header>

        <main class="flex-grow flex flex-col items-center justify-center px-4 sm:px-6 md:px-12 pt-24 pb-12 relative z-10 w-full max-w-[75rem] mx-auto animate-[fadeIn_0.3s_ease-out]">
            <div class="text-center mb-6 max-w-2xl mx-auto px-2">
                <Link :href="home()" class="inline-flex items-center gap-1 text-vault-primary hover:text-vault-primary-container text-xs font-semibold uppercase tracking-wider mb-4 transition-colors">
                    <span class="material-symbols-outlined text-[1rem]">arrow_back</span>
                    Back to Home
                </Link>
                <h1 class="font-display text-4xl md:text-[3rem] font-bold text-vault-on-surface mb-2 leading-tight tracking-tight select-none">Create Encrypted Vault</h1>
                <p class="text-vault-secondary text-sm md:text-base">Your browser will encrypt your payload before upload. Zero knowledge.</p>
            </div>

            <div class="w-full max-w-[60rem] bg-vault-surface-container-lowest border border-vault-outline-variant rounded-xl p-4 sm:p-6 md:p-8 shadow-sm">
                
                <div v-if="isGuestLimitReached" class="bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/50 rounded-xl p-6 md:p-10 text-center flex flex-col items-center justify-center animate-[fadeIn_0.3s_ease-out]">
                    <span class="material-symbols-outlined text-5xl text-red-500 mb-4">lock_person</span>
                    <h3 class="text-2xl font-bold text-red-700 dark:text-red-400 mb-2 font-display">Guest Limit Reached</h3>
                    <p class="text-red-600 dark:text-red-300 text-base max-w-md mx-auto mb-8">You have reached the maximum of 3 active secrets allowed for guest users. Create a free account to manage more secrets and access advanced features.</p>
                    <div class="flex flex-col sm:flex-row items-center justify-center gap-3 w-full sm:w-auto">
                        <Link :href="home()" class="w-full sm:w-auto bg-vault-surface-container-low border border-vault-outline-variant text-vault-on-surface hover:bg-vault-surface-container-high px-6 py-3 rounded text-center transition-colors font-label-md whitespace-nowrap">View History</Link>
                        <Link href="/register" class="w-full sm:w-auto bg-vault-primary text-white hover:bg-vault-primary-container px-6 py-3 rounded text-center transition-colors font-label-md whitespace-nowrap">Create Free Account</Link>
                    </div>
                </div>

                <form v-else-if="!isCreated" @submit.prevent="handleCreateSecret" class="flex flex-col gap-4 md:gap-5">
                    <div class="flex flex-col gap-2">
                        <div class="flex justify-between items-center select-none">
                            <label class="font-label-sm text-label-sm uppercase text-vault-secondary" for="secret-content">Payload</label>

                            <div class="flex items-center gap-3">
                                <div v-show="activeTab === 'write'" class="hidden sm:flex items-center gap-2 text-vault-outline">
                                    <button type="button" @click="insertMarkdown('**$**', 'bold')" class="hover:text-vault-primary transition-colors font-bold text-[0.6875rem] px-0.5" title="Bold">B</button>
                                    <button type="button" @click="insertMarkdown('*$*', 'italic')" class="hover:text-vault-primary transition-colors italic text-[0.6875rem] px-0.5" title="Italic">I</button>
                                    <button type="button" @click="insertMarkdown('# $', 'Heading')" class="hover:text-vault-primary transition-colors text-[0.6875rem] px-0.5" title="Heading">H</button>
                                    <button type="button" @click="insertMarkdown('```\n$\n```', 'code')" class="hover:text-vault-primary transition-colors text-[0.6875rem] px-0.5 font-mono" title="Code Block">&lt;/&gt;</button>
                                    <button type="button" @click="insertMarkdown('- $', 'list item')" class="hover:text-vault-primary transition-colors text-[0.6875rem] px-0.5" title="List Item">•</button>
                                </div>
                                <span v-show="activeTab === 'write'" class="text-vault-outline/20 hidden sm:inline">|</span>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        @click="activeTab = 'write'"
                                        class="font-label-sm text-[0.625rem] uppercase tracking-wider transition-colors"
                                        :class="activeTab === 'write' ? 'text-vault-primary font-bold' : 'text-vault-outline hover:text-vault-on-surface'"
                                    >
                                        Write
                                    </button>
                                    <span class="text-vault-outline/20">|</span>
                                    <button
                                        type="button"
                                        @click="activeTab = 'preview'"
                                        class="font-label-sm text-[0.625rem] uppercase tracking-wider transition-colors"
                                        :class="activeTab === 'preview' ? 'text-vault-primary font-bold' : 'text-vault-outline hover:text-vault-on-surface'"
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
                                class="w-full bg-vault-surface-container-low border border-vault-outline-variant rounded p-4 font-mono-custom text-sm text-vault-on-surface focus:outline-none focus:border-vault-primary focus:ring-1 focus:ring-vault-primary transition-all resize-none placeholder:text-vault-outline h-[10rem] md:h-[11.25rem]"
                                placeholder="Enter code, credentials, or text to encrypt locally..."
                                autocomplete="off"
                                spellcheck="false"
                                required
                            ></textarea>
                            <div v-show="activeTab === 'write' && charCount > 0" class="absolute bottom-2 right-3 font-mono-custom text-[0.625rem] select-none pointer-events-none" :class="charWarning ? 'text-vault-error' : 'text-vault-outline/50'">{{ charCount.toLocaleString() }}</div>

                            <div
                                v-show="activeTab === 'preview'"
                                class="w-full bg-vault-surface-container-low border border-vault-outline-variant rounded p-4 font-body-md text-body-md text-vault-on-surface overflow-y-auto select-text h-[10rem] md:h-[11.25rem] preview-container"
                                v-html="compiledMarkdown"
                            ></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-12 gap-5 pt-4 border-t border-vault-outline-variant">
                        <div class="flex flex-col gap-2 md:col-span-4">
                            <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none" for="expiry">Expiry</label>
                            <div class="relative">
                                <select
                                    :value="expiry"
                                    @change="handleExpiryChange($event)"
                                    id="expiry"
                                    @focus="activeFocus = 'expiry'"
                                    @blur="activeFocus = null"
                                    class="w-full appearance-none bg-vault-surface-container-lowest border border-vault-outline-variant rounded py-3 pl-4 pr-10 font-body-md text-body-md text-vault-on-surface focus:outline-none focus:border-vault-primary focus:ring-1 focus:ring-vault-primary transition-all"
                                >
                                    <option>No Expiry</option>
                                    <option>Never</option>
                                    <option>15 Days</option>
                                    <option>7 Days</option>
                                    <option>1 Day</option>
                                    <option>1 Hour</option>
                                </select>
                                <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-vault-outline pointer-events-none text-[1.25rem] select-none">expand_more</span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 md:col-span-2">
                            <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none">Burn on View</label>
                            <div class="flex items-center h-[2.875rem]">
                                <button
                                    type="button"
                                    @click="burnOnRead = !burnOnRead"
                                    :class="burnOnRead ? 'bg-vault-primary' : 'bg-vault-surface-container-high'"
                                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-vault-primary focus:ring-offset-2"
                                    role="switch"
                                    aria-label="Burn on View"
                                    :aria-checked="burnOnRead"
                                >
                                    <span
                                        :class="burnOnRead ? 'translate-x-5' : 'translate-x-0'"
                                        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                    ></span>
                                </button>
                                <span class="font-body-md text-body-md text-vault-on-surface-variant ml-3 select-none">
                                    {{ burnOnRead ? 'Yes' : 'No' }}
                                </span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 md:col-span-6">
                            <div class="flex items-center justify-between">
                                <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none" for="password">Decryption Key</label>
                                <button
                                    type="button"
                                    @click="generateRandomPassword"
                                    class="font-label-sm text-[0.6875rem] uppercase tracking-wider text-vault-primary hover:underline transition-colors font-semibold"
                                >
                                    Generate Key
                                </button>
                            </div>
                            <div class="relative">
                                <input
                                    v-model="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    id="password"
                                    autocomplete="new-password"
                                    class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded py-3 pl-4 pr-12 font-body-md text-body-md text-vault-on-surface focus:outline-none focus:border-vault-primary focus:ring-1 focus:ring-vault-primary transition-all placeholder:text-vault-outline"
                                    placeholder="Enter decryption key"
                                    required
                                />
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-vault-outline hover:text-vault-on-surface transition-colors flex items-center"
                                >
                                    <span class="material-symbols-outlined text-[1.25rem] select-none">
                                        {{ showPassword ? 'visibility_off' : 'visibility' }}
                                    </span>
                                </button>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 md:col-span-6">
                            <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none" for="identifier">Secret Identifier (Optional)</label>
                            <input
                                v-model="identifier"
                                type="text"
                                id="identifier"
                                class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded py-3 px-4 font-body-md text-body-md text-vault-on-surface focus:outline-none focus:border-vault-primary focus:ring-1 focus:ring-vault-primary transition-all placeholder:text-vault-outline"
                                placeholder="e.g., Instagram Password"
                            />
                        </div>
                        <div class="flex flex-col gap-2 md:col-span-6 relative">
                            <div class="flex items-center justify-between">
                                <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none" for="custom-address">Custom Address (Optional)</label>
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
                                class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded py-3 px-4 font-body-md text-body-md text-vault-on-surface focus:outline-none focus:border-vault-primary focus:ring-1 focus:ring-vault-primary transition-all placeholder:text-vault-outline disabled:bg-vault-surface-container-low"
                                placeholder="Add custom address"
                            />
                        </div>
                    </div>
 
                    <!-- File Attachment Section -->
                    <div class="pt-3 border-t border-vault-outline-variant flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none">Attachments (Optional)</label>
                            <span v-if="!$page.props.auth?.user" class="text-[0.625rem] text-vault-primary font-bold uppercase tracking-wider">Login Required</span>
                        </div>
                        <div 
                            class="border border-dashed border-vault-outline-variant rounded-lg p-3 flex flex-col items-center justify-center bg-vault-surface-container-lowest hover:bg-vault-surface-container-low/30 hover:border-vault-primary/50 transition-all cursor-pointer select-none"
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
                            <div class="flex items-center gap-2 text-vault-outline hover:text-vault-primary transition-colors">
                                <span class="material-symbols-outlined text-[1.25rem]">attach_file</span>
                                <span v-if="!$page.props.auth?.user" class="font-body-md text-body-md text-vault-secondary">Sign in to add file attachments</span>
                                <span v-else class="font-body-md text-body-md text-vault-secondary">Drag & drop files here, or <span class="text-vault-primary font-medium">browse</span></span>
                            </div>
                            <p class="font-label-sm text-[0.625rem] text-vault-secondary/70 mt-1">Maximum size: 100MB per file</p>
                        </div>

                        <!-- Selected Files List -->
                        <div v-if="attachedFiles.length > 0" class="flex flex-col gap-2 mt-2">
                            <div 
                                v-for="(file, index) in attachedFiles" 
                                :key="index"
                                class="flex items-center justify-between bg-vault-surface-container-low border border-vault-outline-variant/60 rounded px-3 py-2 text-vault-on-surface font-body-md text-body-md animate-[fadeIn_0.2s_ease-out]"
                            >
                                <div class="flex items-center gap-2 overflow-hidden mr-4">
                                    <span class="material-symbols-outlined text-vault-outline text-[1.125rem]">description</span>
                                    <span class="truncate">{{ file.name }}</span>
                                    <span class="text-vault-secondary text-[0.6875rem] font-mono-custom">({{ formatBytes(file.size) }})</span>
                                </div>
                                <button 
                                    type="button" 
                                    @click="removeFile(index)" 
                                    class="text-vault-outline hover:text-vault-error transition-colors flex items-center justify-center p-0.5 rounded-full hover:bg-vault-outline-variant/30"
                                    title="Remove file"
                                >
                                    <span class="material-symbols-outlined text-[1.125rem]">close</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Options Section -->
                    <div v-show="showAdvanced" class="pt-4 mt-2 border-t border-vault-outline-variant grid grid-cols-1 md:grid-cols-2 gap-5 animate-[fadeIn_0.2s_ease-out]">
                        <div class="flex flex-col gap-2 relative">
                            <div class="flex items-center justify-between">
                                <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none" for="recipient-email">Recipient Email(s) (Optional)</label>
                                <span v-if="!$page.props.auth?.user" class="text-[0.625rem] text-vault-primary font-bold uppercase tracking-wider">Login Required</span>
                            </div>
                            <div class="relative w-full">
                                <input
                                    v-model="recipientEmail"
                                    type="text"
                                    id="recipient-email"
                                    :disabled="!$page.props.auth?.user"
                                    class="w-full border border-vault-outline-variant rounded py-3 px-4 font-body-md text-body-md text-vault-on-surface focus:outline-none focus:border-vault-primary focus:ring-1 transition-all placeholder:text-vault-outline bg-vault-surface-container-lowest focus:ring-vault-primary disabled:opacity-50"
                                    :placeholder="!$page.props.auth?.user ? 'Sign in to notify recipients via email' : 'e.g. user1@example.com, user2@example.com'"
                                />
                                <div
                                    v-if="!$page.props.auth?.user"
                                    @click="handleRecipientEmailClick"
                                    class="absolute inset-0 cursor-pointer z-10"
                                    title="Sign in to use this feature"
                                ></div>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center justify-between">
                                <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none" for="encryption-hint">Encryption Hint (Optional)</label>
                            </div>
                            <input
                                v-model="encryptionHint"
                                type="text"
                                id="encryption-hint"
                                class="w-full border border-vault-outline-variant rounded py-3 px-4 font-body-md text-body-md text-vault-on-surface focus:outline-none focus:border-vault-primary focus:ring-1 transition-all placeholder:text-vault-outline bg-vault-surface-container-lowest focus:ring-vault-primary"
                                placeholder="Add a hint to help decrypt"
                            />
                        </div>
                    </div>

                    <div class="pt-4 mt-2 border-t border-vault-outline-variant flex flex-col md:flex-row justify-between items-center gap-4">
                        <button
                            type="button"
                            @click="showAdvanced = !showAdvanced"
                            class="w-full md:w-auto bg-vault-surface-container-lowest border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-3 px-6 rounded hover:bg-vault-surface-container-low transition-colors duration-200 scale-95 hover:scale-100 ease-in-out inline-flex items-center justify-center gap-2"
                        >
                            <span class="material-symbols-outlined text-[1.125rem] transition-transform duration-200" :class="{ 'rotate-180': showAdvanced }">expand_more</span>
                            Advanced
                        </button>
                        <button
                            type="submit"
                            :disabled="isSubmitting || (!payload.trim() && attachedFiles.length === 0)"
                            class="w-full md:w-auto bg-vault-primary text-vault-on-primary font-label-md text-label-md py-3 px-8 rounded hover:bg-vault-primary-container transition-colors flex items-center justify-center gap-2 shadow-[inset_0_-1px_0_rgba(0,0,0,0.2)] active:shadow-none active:translate-y-[1px] disabled:opacity-70 disabled:cursor-not-allowed"
                            aria-label="Encrypt and create secret link"
                        >
                            <span v-if="!isSubmitting" class="material-symbols-outlined text-[1.125rem]" style="font-variation-settings: 'FILL' 1;">lock</span>
                            <span v-else class="material-symbols-outlined text-[1.125rem] animate-spin">sync</span>
                            {{ isSubmitting ? 'Encrypting...' : 'Encrypt & Store' }}
                        </button>
                    </div>
                </form>

                <div v-else class="flex flex-col gap-6 animate-[fadeIn_0.3s_ease-out]">
                    <div class="flex items-center gap-3 text-vault-primary">
                        <span class="material-symbols-outlined text-[1.75rem]">verified</span>
                        <h2 class="font-headline-md text-headline-md font-bold">Secret Link Generated</h2>
                    </div>

                    <p class="font-body-md text-body-md text-vault-on-surface-variant">
                        This payload is encrypted and stored securely in your vault. Share this link, or keep it for yourself. It will be destroyed based on the expiry config (<strong>{{ expiry }}</strong>).
                    </p>

                    <div class="flex flex-col md:flex-row gap-6 items-center">
                        <div class="flex-shrink-0 bg-vault-surface-container-low p-4 rounded-xl border border-vault-outline-variant flex items-center justify-center bg-white">
                            <img :src="`https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(createdLink)}&bgcolor=ffffff&color=000000`" alt="Secret QR Code" class="rounded-md w-[7.5rem] h-[7.5rem] md:w-[9.375rem] md:h-[9.375rem]" />
                        </div>
                        <div class="flex flex-col gap-4 w-full">
                            <div class="flex flex-col gap-2">
                                <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none">Secret URL</label>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <input
                                        readonly
                                        :value="createdLink"
                                        class="w-full bg-vault-surface-container-low border border-vault-outline-variant rounded py-3 px-4 font-mono-custom text-sm text-vault-on-surface focus:outline-none"
                                    />
                                    <button
                                        @click="handleCopy"
                                        type="button"
                                        class="bg-vault-primary text-vault-on-primary font-label-md text-label-md px-6 py-3 rounded hover:bg-vault-primary-container transition-colors flex items-center justify-center gap-2 whitespace-nowrap"
                                    >
                                        <span class="material-symbols-outlined text-[1.125rem]">{{ copied ? 'done' : 'content_copy' }}</span>
                                        {{ copied ? 'Copied' : 'Copy Link' }}
                                    </button>
                                </div>
                            </div>
                            
                            <div class="flex flex-col gap-2 pt-2 border-t border-vault-outline-variant/30">
                                <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none">Decryption Key</label>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <div class="relative w-full">
                                        <input
                                            readonly
                                            :type="showCreatedKey ? 'text' : 'password'"
                                            :value="password"
                                            class="w-full bg-vault-surface-container-low border border-vault-outline-variant rounded py-3 pl-4 pr-12 font-mono-custom text-sm text-vault-on-surface focus:outline-none"
                                        />
                                        <button
                                            type="button"
                                            @click="showCreatedKey = !showCreatedKey"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-vault-outline hover:text-vault-on-surface transition-colors flex items-center"
                                        >
                                            <span class="material-symbols-outlined text-[1.25rem] select-none">
                                                {{ showCreatedKey ? 'visibility_off' : 'visibility' }}
                                            </span>
                                        </button>
                                    </div>
                                    <button
                                        @click="handleCopyKey"
                                        type="button"
                                        class="bg-vault-surface-container-low hover:bg-vault-surface-container-high border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md px-6 py-3 rounded transition-colors flex items-center justify-center gap-2 whitespace-nowrap"
                                    >
                                        <span class="material-symbols-outlined text-[1.125rem]">{{ copiedKey ? 'done' : 'content_copy' }}</span>
                                        {{ copiedKey ? 'Copied' : 'Copy Key' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-vault-surface-container-low p-4 rounded border border-vault-outline-variant/50 text-xs text-vault-on-surface-variant flex flex-col gap-2">
                        <p class="font-medium text-vault-secondary">🔒 Zero-Knowledge Security Notice</p>
                        <p>
                            The decryption key is never stored on our servers. You must copy the URL and Key to share with your recipient.
                        </p>
                    </div>

                    <div class="pt-6 border-t border-vault-outline-variant/50 flex flex-col md:flex-row justify-end gap-3 w-full">
                        <button
                            @click="handleDeleteSecret"
                            :disabled="isDeleting"
                            type="button"
                            class="w-full md:w-auto bg-red-50 hover:bg-red-100 dark:bg-red-950/20 dark:hover:bg-red-950/30 border border-red-200 dark:border-red-900/50 text-red-600 dark:text-red-400 font-label-md text-label-md py-3 px-8 rounded transition-colors text-center flex items-center justify-center gap-2"
                        >
                            <span v-if="!isDeleting" class="material-symbols-outlined text-[1.125rem]">delete</span>
                            <span v-else class="material-symbols-outlined text-[1.125rem] animate-spin">sync</span>
                            Delete Secret
                        </button>
                        <Link
                            :href="home()"
                            class="w-full md:w-auto bg-vault-surface-container-low border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-3 px-8 rounded hover:bg-vault-surface-container-high transition-colors text-center flex items-center justify-center"
                        >
                            Go Home
                        </Link>
                        <button
                            @click="handleCreateAnother"
                            type="button"
                            class="w-full md:w-auto bg-vault-primary text-vault-on-primary font-label-md text-label-md py-3 px-8 rounded hover:bg-vault-primary-container transition-colors flex items-center justify-center"
                        >
                            Create Another
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
