<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { Toaster } from '@/components/ui/sonner';
import { useConfirm } from '@/composables/useConfirm';
import ConfirmModal from '@/components/ConfirmModal.vue';
import {
    decryptText,
    decryptFile,
    base64ToArrayBuffer,
    deriveKey
} from '@/lib/crypto';
import { profile, login, home, logout } from '@/routes';

const { confirm } = useConfirm();

const props = defineProps<{
    initialSecretId?: string;
}>();

const retrieveId = ref('');
const fetchedSecretPayload = ref<any>(null);
const isRetrieving = ref(false);
const isDecrypting = ref(false);

const decryptionKey = ref('');
const decryptedPayload = ref<string | null>(null);
const decryptError = ref('');
const decryptedFiles = ref<Array<{
    name: string;
    downloadUrl: string;
    type: string;
    isDecrypting: boolean;
    decryptedDataUrl?: string;
}>>([]);

const decryptedCopied = ref(false);

async function handleRetrieve() {
    if (!retrieveId.value.trim()) {
return;
}

    isRetrieving.value = true;
    isDecrypting.value = true;
    decryptError.value = '';

    try {
        let id = retrieveId.value.trim();

        if (id.includes('/secret/')) {
            id = id.split('/secret/').pop() || id;
        } else if (id.includes('/view/')) {
            id = id.split('/view/').pop() || id;
        }

        const response = await axios.get(`/api/secrets/${id}`);
        fetchedSecretPayload.value = response.data;

        // Auto decrypt if hash key is present
        if (decryptionKey.value) {
            handleDecrypt();
        }
    } catch (error: any) {
        console.error('Error retrieving secret:', error);
        toast.error(error.response?.data?.message || 'Secret not found or expired.');
        isDecrypting.value = false;
    } finally {
        isRetrieving.value = false;
    }
}

async function handleDecrypt() {
    if (!decryptionKey.value.trim()) {
        decryptError.value = 'Decryption key is required';

        return;
    }
    
    decryptError.value = '';

    try {
        const decrypted = await decryptText(
            fetchedSecretPayload.value.encrypted_payload,
            decryptionKey.value.trim()
        );

        if (fetchedSecretPayload.value.burn_on_read) {
            try {
                await axios.post(`/api/secrets/${fetchedSecretPayload.value.secret_id}/burn`);
            } catch (burnError: any) {
                console.error('Failed to burn secret:', burnError);
                decryptError.value = 'This secret has already been burned and cannot be viewed.';
                return;
            }
        }

        decryptedPayload.value = decrypted;
        
        decryptedFiles.value = [];

        if (fetchedSecretPayload.value.file_paths) {
            const decoder = new TextDecoder();

            for (let i = 0; i < fetchedSecretPayload.value.file_paths.length; i++) {
                const file = fetchedSecretPayload.value.file_paths[i];

                if (!file.encrypted_metadata) {
continue;
}
                
                const metaSalt = new Uint8Array(base64ToArrayBuffer(file.salt));
                const metaIv = new Uint8Array(base64ToArrayBuffer(file.iv));
                const metaCiphertext = base64ToArrayBuffer(file.encrypted_metadata);
                
                const metaKey = await deriveKey(decryptionKey.value.trim(), metaSalt);
                const decryptedMeta = await window.crypto.subtle.decrypt(
                    { name: 'AES-GCM', iv: metaIv },
                    metaKey,
                    metaCiphertext
                );
                
                const meta = JSON.parse(decoder.decode(decryptedMeta));
                decryptedFiles.value.push({
                    name: meta.name,
                    type: meta.type,
                    downloadUrl: file.download_url,
                    isDecrypting: false
                });
            }
            
            for (let i = 0; i < decryptedFiles.value.length; i++) {
                downloadAndDecryptFile(i);
            }
        }
    } catch (error: any) {
        console.error('Decryption failed:', error);
        decryptError.value = 'Incorrect decryption key or corrupted payload.';
    }
}

async function downloadAndDecryptFile(index: number) {
    const file = decryptedFiles.value[index];

    if (!file) {
return;
}

    if (file.decryptedDataUrl) {
        triggerDownload(file.decryptedDataUrl, file.name);

        return;
    }

    file.isDecrypting = true;

    try {
        const payloadFile = fetchedSecretPayload.value.file_paths[index];
        
        const response = await axios.get(payloadFile.download_url, {
            responseType: 'arraybuffer',
            headers: {
                'X-Vault-Decrypted': '1'
            }
        });
        
        const { decryptedFile } = await decryptFile(
            response.data,
            decryptionKey.value.trim(),
            payloadFile.encrypted_metadata,
            payloadFile.salt,
            payloadFile.iv
        );
        
        const url = URL.createObjectURL(decryptedFile);
        file.decryptedDataUrl = url;
        triggerDownload(url, file.name);
    } catch (error: any) {
        console.error('File decryption failed:', error);
        toast.error('Failed to decrypt and download file.');
    } finally {
        file.isDecrypting = false;
    }
}

function triggerDownload(url: string, filename: string) {
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function handleCopyDecrypted() {
    if (!decryptedPayload.value) {
return;
}

    navigator.clipboard.writeText(decryptedPayload.value);
    decryptedCopied.value = true;
    setTimeout(() => {
        decryptedCopied.value = false;
    }, 2000);
}

function handleClearRetrieved() {
    fetchedSecretPayload.value = null;
    decryptedPayload.value = null;
    isDecrypting.value = false;
    decryptedFiles.value = [];
    decryptionKey.value = '';
    retrieveId.value = '';
    decryptedCopied.value = false;
    
    // Clear URL parameters
    window.history.replaceState({}, document.title, '/view');
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

onMounted(() => {
    // Read the encryption key from URL hash if available
    const hashKey = window.location.hash.slice(1);

    if (hashKey) {
        decryptionKey.value = hashKey;
    }

    if (props.initialSecretId) {
        retrieveId.value = props.initialSecretId;
        handleRetrieve();
    }
});
</script>

<template>
    <Head title="Decrypt & View Secret | Ilusion Vault">
        <meta name="description" content="Access and decrypt zero-knowledge secure links safely. All decryption takes place inside your browser." />
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
                <h1 class="font-display text-4xl md:text-[3rem] font-bold text-vault-on-surface mb-2 leading-tight tracking-tight select-none">Decrypt & Access</h1>
                <p class="text-vault-secondary text-sm md:text-base">All decryption operations happen on your device. The keys are never sent to our servers.</p>
            </div>

            <div class="w-full max-w-[45rem] bg-vault-surface-container-lowest border border-vault-outline-variant rounded-xl p-4 sm:p-6 md:p-8 shadow-sm">
                <div v-if="!isDecrypting" class="flex flex-col gap-6 items-center">
                    <div class="w-full relative">
                        <input
                            v-model="retrieveId"
                            @keyup.enter="handleRetrieve"
                            class="w-full bg-vault-surface-container-low border border-vault-outline-variant rounded py-3 pl-4 pr-12 font-mono-custom text-sm text-vault-on-surface focus:outline-none focus:border-vault-primary focus:ring-1 focus:ring-vault-primary transition-colors placeholder:text-vault-outline/50"
                            placeholder="Enter Secret URL or ID..."
                            type="text"
                        />
                        <button
                            @click="handleRetrieve"
                            :disabled="!retrieveId.trim() || isRetrieving"
                            aria-label="Retrieve secret"
                            class="absolute right-0 top-1/2 -translate-y-1/2 text-vault-outline hover:text-vault-primary transition-colors flex items-center justify-center h-full px-4 disabled:opacity-30"
                        >
                            <span v-if="!isRetrieving" class="material-symbols-outlined">arrow_forward</span>
                            <span v-else class="animate-spin h-5 w-5 border-2 border-vault-primary border-t-transparent rounded-full"></span>
                        </button>
                    </div>
                </div>

                <div v-else class="flex flex-col gap-6 animate-[fadeIn_0.3s_ease-out]">
                    <div v-if="!fetchedSecretPayload" class="flex flex-col items-center justify-center py-12 gap-4">
                        <span class="material-symbols-outlined text-[2.25rem] animate-spin text-vault-primary">sync</span>
                        <p class="text-vault-secondary font-body-md">Retrieving and preparing secure payload...</p>
                    </div>
                    <div v-else class="flex flex-col gap-6">
                        <div class="flex items-center gap-3 text-vault-primary">
                            <span class="material-symbols-outlined text-[1.75rem]">lock_open</span>
                            <h2 class="font-headline-md text-headline-md font-bold">Secret Retrieved</h2>
                        </div>

                        <div v-if="fetchedSecretPayload?.encryption_hint" class="bg-vault-surface-container-low border border-vault-outline-variant p-4 rounded text-vault-on-surface text-sm">
                            <strong class="text-vault-secondary">Hint:</strong> {{ fetchedSecretPayload.encryption_hint }}
                        </div>

                        <div v-if="!decryptedPayload" class="flex flex-col gap-4">
                            <p class="font-body-md text-body-md text-vault-on-surface-variant">
                                This secret requires a decryption key.
                            </p>
                            
                            <div class="flex flex-col gap-2">
                                <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none">Decryption Key</label>
                                <input
                                    v-model="decryptionKey"
                                    type="password"
                                    @keyup.enter="handleDecrypt"
                                    class="w-full bg-vault-surface-container-low border border-vault-outline-variant rounded py-3 px-4 font-body-md text-body-md text-vault-on-surface focus:outline-none focus:border-vault-primary focus:ring-1 focus:ring-vault-primary transition-all placeholder:text-vault-outline"
                                    placeholder="Enter key to decrypt..."
                                />
                                <p v-if="decryptError" class="text-vault-error text-xs">{{ decryptError }}</p>
                            </div>
                            
                            <button
                                @click="handleDecrypt"
                                type="button"
                                class="bg-vault-primary text-vault-on-primary font-label-md text-label-md py-3 px-8 rounded hover:bg-vault-primary-container transition-colors mt-2"
                            >
                                Decrypt Payload
                            </button>
                        </div>

                        <div v-else class="flex flex-col gap-4">
                            <div class="flex justify-between items-center select-none">
                                <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none">Decrypted Payload</label>
                                <button
                                    @click="handleCopyDecrypted"
                                    type="button"
                                    class="text-vault-primary hover:text-vault-primary-container font-label-sm text-[0.625rem] uppercase tracking-wider transition-colors flex items-center gap-1"
                                >
                                    <span class="material-symbols-outlined text-[1rem]">{{ decryptedCopied ? 'done' : 'content_copy' }}</span>
                                    {{ decryptedCopied ? 'Copied' : 'Copy' }}
                                </button>
                            </div>
                            <pre class="w-full bg-vault-surface-container-low border border-vault-outline-variant rounded p-4 font-mono-custom text-sm text-vault-on-surface overflow-x-auto break-all whitespace-pre-wrap select-text max-h-[25rem] overflow-y-auto">{{ decryptedPayload }}</pre>
                            
                            <div v-if="decryptedFiles.length > 0" class="flex flex-col gap-2 mt-4">
                                <label class="font-label-sm text-label-sm uppercase text-vault-secondary select-none">Attached Files</label>
                                <ul class="flex flex-col gap-2">
                                    <li 
                                        v-for="(file, i) in decryptedFiles" 
                                        :key="i" 
                                        @click="downloadAndDecryptFile(i)"
                                        class="bg-vault-surface-container-low hover:bg-vault-surface-container-high border border-vault-outline-variant p-3 rounded flex items-center justify-between cursor-pointer transition-colors group"
                                    >
                                        <span class="font-mono-custom text-sm text-vault-on-surface truncate pr-4 group-hover:text-vault-primary transition-colors">{{ file.name }}</span>
                                        <div class="text-vault-primary font-label-sm text-xs flex items-center gap-1 shrink-0 select-none">
                                            <span class="material-symbols-outlined text-[1rem] animate-spin" v-if="file.isDecrypting">sync</span>
                                            <span class="material-symbols-outlined text-[1rem]" v-else>download</span>
                                            {{ file.isDecrypting ? 'Decrypting...' : 'Download' }}
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="pt-6 border-t border-vault-outline-variant/50 flex justify-end">
                            <button
                                @click="handleClearRetrieved"
                                type="button"
                                class="w-full md:w-auto bg-vault-surface-container-low border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-3 px-8 rounded hover:bg-vault-surface-container-high transition-colors"
                            >
                                Done
                            </button>
                        </div>
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
