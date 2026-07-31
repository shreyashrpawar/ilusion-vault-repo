<script setup lang="ts">
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, watch } from 'vue';
import { useStorage } from '@vueuse/core';
import { toast } from 'vue-sonner';
import { Toaster } from '@/components/ui/sonner';
import { useConfirm } from '@/composables/useConfirm';
import ConfirmModal from '@/components/ConfirmModal.vue';
import { encryptText } from '@/lib/crypto';
import { profile, login, home, logout } from '@/routes';
import axios from 'axios';

const { confirm } = useConfirm();

interface Secret {
    secret_id: string;
    identifier: string | null;
    url: string;
    expiry_date: string;
    burn_on_read: boolean;
    recipient_email: string | null;
    created_at: string;
}

const props = withDefaults(defineProps<{
    secrets?: Secret[];
}>(), {
    secrets: () => []
});

const page = usePage();
const isLoggedIn = computed(() => !!page.props.auth?.user);

const localSecrets = ref<Secret[]>([...props.secrets]);

const guestSecrets = useStorage<Secret[]>('ilusion_guest_secrets', []);

const displayedSecrets = computed(() => {
    if (isLoggedIn.value) return localSecrets.value;
    return guestSecrets.value.filter(s => new Date(s.expiry_date) > new Date());
});

watch(() => props.secrets, (newVal) => {
    localSecrets.value = [...newVal];
}, { deep: true });


const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success('Secret link copied to clipboard!');
};

const deleteSecret = async (secretId: string) => {
    const isConfirmed = await confirm({
        title: 'Delete Secret',
        message: 'Are you sure you want to delete this secret? This action is permanent and will delete all associated files.',
        confirmText: 'Delete',
        cancelText: 'Keep it',
        type: 'danger'
    });
    if (isConfirmed) {
        try {
            await axios.delete(`/api/secrets/${secretId}`);
            if (isLoggedIn.value) {
                localSecrets.value = localSecrets.value.filter(s => s.secret_id !== secretId);
            } else {
                guestSecrets.value = guestSecrets.value.filter(s => s.secret_id !== secretId);
            }
            toast.success('Secret deleted successfully.');
        } catch (error: any) {
            toast.error('Failed to delete secret: ' + (error.response?.data?.message || error.message));
        }
    }
};

// Animated text scramble effect for the hero
const scrambleChars = '█▓▒░AES-GCM256#@!$%&*';
const targetText = 'Security is not an Ilusion.';
const displayText = ref('');
const scrambleComplete = ref(false);

onMounted(() => {
    let iteration = 0;
    const maxIterations = targetText.length;
    const interval = setInterval(() => {
        displayText.value = targetText.split('').map((char, idx) => {
            if (idx < iteration) { return char; }
            if (char === ' ') { return ' '; }

            return scrambleChars[Math.floor(Math.random() * scrambleChars.length)];
        }).join('');
        iteration += 0.5;
        if (iteration >= maxIterations) {
            clearInterval(interval);
            displayText.value = targetText;
            scrambleComplete.value = true;
        }
    }, 30);

    // Filter guest secrets that have expired locally
    guestSecrets.value = guestSecrets.value.filter(s => new Date(s.expiry_date) > new Date());

    // Verify remaining guest secrets against the server to check if they were burned/deleted
    if (guestSecrets.value.length > 0) {
        const ids = guestSecrets.value.map(s => s.secret_id);
        axios.post('/api/secrets/check', { ids })
            .then(res => {
                const existing = res.data.existing || [];
                guestSecrets.value = guestSecrets.value.filter(s => existing.includes(s.secret_id));
            })
            .catch(err => {
                console.error('Failed to verify guest secrets:', err);
            });
    }
});

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

// Live interactive encryption playground state
const playgroundPlaintext = ref('database_url="postgresql://db_user:password@localhost:5432/production"');
const playgroundKey = ref('browser-random-key-xyz');
const isPlaygroundEncrypting = ref(false);
const playgroundEncrypted = ref(false);
const playgroundResult = ref<{ ciphertext: string; salt: string; iv: string } | null>(null);

const generatedDemoLink = computed(() => {
    if (!playgroundResult.value) {
return '';
}

    return `https://ilusion.io/secret/s_${Math.random().toString(36).substring(2, 10)}`;
});

async function handlePlaygroundEncrypt() {
    if (!playgroundPlaintext.value.trim() || !playgroundKey.value.trim()) {
return;
}
    
    isPlaygroundEncrypting.value = true;
    playgroundEncrypted.value = false;
    
    // Simulate encryption processing time for a nice animation effect
    await new Promise((resolve) => setTimeout(resolve, 800));
    
    try {
        const jsonStr = await encryptText(playgroundPlaintext.value, playgroundKey.value);
        playgroundResult.value = JSON.parse(jsonStr);
        playgroundEncrypted.value = true;
    } catch (err) {
        console.error(err);
    } finally {
        isPlaygroundEncrypting.value = false;
    }
}

const activeFaqIndex = ref<number | null>(null);

function toggleFaq(index: number) {
    if (activeFaqIndex.value === index) {
        activeFaqIndex.value = null;
    } else {
        activeFaqIndex.value = index;
    }
}
</script>

<template>
    <Head title="Ilusion Vault — Encrypted Data Vault & Secure Sharing | Open Source">
        <meta name="description" content="Zero-Knowledge client-side encryption. Every bit of data, including the files, is encrypted in the browser before being sent to the server. The key used during encryption can be used to view the actual data, and only the person encrypting the data knows it, because the key is not stored anywhere. So, it enables sensitive data to be accessed and shared securely." />

        <link rel="canonical" href="https://ilusion.io/" />
    </Head>

    <div class="vault-light bg-vault-background text-vault-on-background min-h-screen flex flex-col font-body-md antialiased selection:bg-[#e4e4e7] selection:text-[#18181b] relative overflow-x-hidden">
        <!-- Animated gradient mesh background -->
        <div class="absolute inset-0 pointer-events-none z-0 overflow-hidden">
            <div class="gradient-orb gradient-orb-1"></div>
            <div class="gradient-orb gradient-orb-2"></div>
            <div class="gradient-orb gradient-orb-3"></div>
        </div>
        <div class="absolute inset-0 bg-dot-grid pointer-events-none z-0"></div>

        <header class="fixed top-0 left-0 right-0 z-50 flex justify-between items-center px-4 sm:px-6 md:px-12 py-3 md:py-4 bg-vault-surface/80 backdrop-blur-xl border-b border-vault-outline-variant shadow-sm transition-all duration-300">
            <div class="flex items-center gap-4 sm:gap-8 max-w-[75rem] w-full mx-auto">
                <div class="flex items-center gap-4 sm:gap-8 flex-1">
                    <Link class="flex items-center gap-2 font-headline-md text-headline-md font-bold text-vault-on-surface hover:opacity-90" :href="home()">
                        <img src="/ilusion-logo.png" alt="Ilusion" class="p-1 w-5 h-5 md:w-8 md:h-8 object-contain" />
                        Ilusion Vault
                    </Link>
                </div>
                <div class="flex items-center gap-3">
                    <Link href="/contact" class="font-label-md text-label-md text-vault-on-surface hover:text-vault-primary transition-colors duration-200 mr-2 uppercase tracking-widest hidden sm:inline-block border-b-2 border-transparent hover:border-vault-primary">Contact</Link>
                    <template v-if="$page.props.auth?.user">
                        <span class="font-body-md text-body-md text-vault-on-surface-variant select-none hidden sm:flex items-center mr-2 gap-1.5">
                            Hello <span class="font-semibold text-vault-on-surface">{{ $page.props.auth.user.name }}</span>
                        </span>
                    <Link href="/" class="bg-vault-surface-container-lowest border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-2 px-4 rounded hover:bg-vault-surface-container-low transition-colors duration-200 scale-95 hover:scale-100 ease-in-out inline-flex items-center justify-center mr-2">Open Vault</Link>
                        <Link :href="logout().url" method="post" as="button" class="bg-vault-surface-container-lowest border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-2 px-4 rounded hover:bg-vault-surface-container-low transition-colors duration-200 scale-95 hover:scale-100 ease-in-out inline-flex items-center justify-center">Logout</Link>
                    </template>
                    <template v-else>
                        <Link :href="login()" class="bg-vault-surface-container-lowest border border-vault-outline-variant text-vault-on-surface font-label-md text-label-md py-2 px-4 rounded hover:bg-vault-surface-container-low transition-colors duration-200 scale-95 hover:scale-100 ease-in-out inline-flex items-center justify-center">Sign In</Link>
                    </template>
                </div>
            </div>
        </header>

        <main class="flex-grow flex flex-col items-center justify-center px-4 sm:px-6 md:px-12 pt-28 pb-20 relative z-10 w-full max-w-[75rem] mx-auto">
            <!-- Hero Section with Scramble Animation -->
            <div class="text-center mb-12 max-w-4xl mx-auto px-2 pt-4">
                <a href="https://github.com/ilusion-io/ilusion-vault" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2.5 px-4 py-2 rounded-full bg-vault-surface-container-low hover:bg-[#18181b] hover:text-white text-vault-on-surface font-label-sm text-sm sm:text-base transition-all duration-300 shadow-sm mb-8 border border-vault-outline-variant hover:border-[#18181b] hover:scale-105 group cursor-pointer">
                    <svg class="w-4 h-4 sm:w-5 sm:h-5 fill-current" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>
                    <span class="font-semibold tracking-wide">Star us on GitHub</span>
                    <span class="text-vault-outline/50">|</span>
                    <span class="text-vault-primary font-bold group-hover:text-vault-primary-fixed">E2EE Vault</span>
                </a>
                
                <!-- Animated scramble headline -->
                <h1 class="font-display text-6xl sm:text-7xl md:text-[4.25rem] font-bold text-vault-on-surface mb-6 leading-[1.05] tracking-tight select-none font-mono" :class="{ 'scramble-mono': !scrambleComplete, 'scramble-done': scrambleComplete }">
                    <span v-for="(char, i) in displayText.split('')" :key="i" :class="{ 'bg-gradient-text': scrambleComplete && i >= 19 && char !== '.' && char !== ' ', 'text-vault-primary/40': !scrambleComplete && char !== ' ' && i >= Math.floor(displayText.length * 0.7) }">{{ char }}</span>
                </h1>
                
                <p class="text-zinc-600 text-lg sm:text-xl md:text-xl max-w-3xl mx-auto mb-10 leading-relaxed font-sans font-medium animate-[fadeUp_0.6s_ease-out_0.8s_both]">
                   Zero-Knowledge client-side encryption. Every bit of data, including the files, is encrypted in the browser before being sent to the server. The key used during encryption can be used to view the actual data, and only the person encrypting the data knows it, because the key is not stored anywhere. 
                </p>

                <!-- Call to Action Buttons -->
                <div class="flex flex-col sm:flex-row items-center justify-center gap-5 max-w-xl mx-auto animate-[fadeUp_0.6s_ease-out_1s_both]">
                    <Link href="/create" class="w-full sm:w-auto bg-vault-primary text-vault-on-primary font-sans font-semibold text-base sm:text-lg py-4 px-8 rounded-lg hover:bg-zinc-800 transition-all flex items-center justify-center gap-2.5 shadow-[0_4px_20px_rgba(24,24,27,0.25)] hover:shadow-[0_8px_30px_rgba(24,24,27,0.35)] hover:-translate-y-1 duration-300 group">
                        <span class="material-symbols-outlined text-[1.25rem] group-hover:animate-[wiggle_0.3s_ease-in-out]" style="font-variation-settings: 'FILL' 1;">lock</span>
                        Create Secure Secret
                    </Link>
                    <Link href="/view" class="w-full sm:w-auto bg-white/70 backdrop-blur-xl border border-vault-outline-variant/60 text-vault-on-surface font-sans font-semibold text-base sm:text-lg py-4 px-8 rounded-lg hover:bg-white hover:border-vault-outline-variant transition-all flex items-center justify-center gap-2.5 shadow-sm hover:shadow-lg hover:-translate-y-1 duration-300">
                        <span class="material-symbols-outlined text-[1.25rem]">key</span>
                        Decrypt &amp; Access
                    </Link>
                </div>
            </div>

            <!-- Logged-in View or Guest with History: Active Secrets -->
            <div v-if="isLoggedIn || displayedSecrets.length > 0" class="w-full max-w-[74rem]">
                <div class="w-full bg-vault-surface-container-lowest border border-vault-outline-variant rounded-xl shadow-sm overflow-hidden mt-8">
                    <div class="px-6 py-5 border-b border-vault-outline-variant flex justify-between items-center bg-vault-surface/40">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-vault-secondary text-[1.25rem]">vpn_key</span>
                            <h2 class="text-lg font-bold text-vault-on-surface font-headline-md">{{ isLoggedIn ? 'Active Secrets' : 'Guest History (Local)' }}</h2>
                        </div>
                        <span class="bg-vault-primary/10 text-vault-primary px-2.5 py-0.5 rounded-full text-sm font-semibold font-mono">
                            {{ displayedSecrets.length }} Active
                        </span>
                    </div>

                    <!-- Empty State -->
                    <div v-if="displayedSecrets.length === 0" class="p-12 text-center flex flex-col items-center justify-center">
                        <span class="material-symbols-outlined text-6xl text-vault-outline-variant mb-4">folder_open</span>
                        <p class="text-vault-on-surface-variant font-medium mb-1 font-headline-md text-base">No active secrets found</p>
                        <p class="text-sm text-vault-outline mb-6">Create a new secret to share it securely or store it in your vault.</p>
                        <Link href="/create" class="bg-vault-primary text-vault-on-primary font-headline-md text-sm py-2.5 px-5 rounded hover:bg-vault-primary-container transition-all flex items-center justify-center gap-2 shadow-sm hover:scale-105 duration-200">
                            <span class="material-symbols-outlined text-[1rem]">lock</span>
                            Create Secure Secret
                        </Link>
                    </div>

                    <!-- Secrets Table / Grid -->
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-vault-surface/30 text-sm font-semibold uppercase tracking-wider text-vault-secondary border-b border-vault-outline-variant">
                                    <th class="py-4 px-6 font-headline-md text-[0.6875rem]">Identifier / ID</th>
                                    <th class="py-4 px-6 font-headline-md text-[0.6875rem]">Created At</th>
                                    <th class="py-4 px-6 font-headline-md text-[0.6875rem]">Expires At</th>
                                    <th class="py-4 px-6 text-center font-headline-md text-[0.6875rem]">Settings</th>
                                    <th class="py-4 px-6 text-right font-headline-md text-[0.6875rem]">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-vault-outline-variant">
                                <tr v-for="secret in displayedSecrets" :key="secret.secret_id" class="hover:bg-vault-surface/20 transition-colors">
                                    <td class="py-4 px-6 font-mono text-sm text-vault-on-surface max-w-[9.375rem] truncate select-text" :title="secret.identifier || secret.secret_id">
                                        {{ secret.identifier || secret.secret_id }}
                                    </td>
                                    <td class="py-4 px-6 text-sm text-vault-on-surface-variant">
                                        {{ formatDate(secret.created_at) }}
                                    </td>
                                    <td class="py-4 px-6 text-sm text-vault-on-surface-variant">
                                        {{ formatDate(secret.expiry_date) }}
                                    </td>
                                    <td class="py-4 px-6 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <span v-if="secret.burn_on_read" class="inline-flex items-center px-2 py-0.5 rounded text-[0.625rem] font-medium bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300" title="Will burn after 1st read">
                                                Burn on Read
                                            </span>
                                            <span v-if="secret.recipient_email" class="inline-flex items-center px-2 py-0.5 rounded text-[0.625rem] font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300" :title="'Emailed to: ' + secret.recipient_email">
                                                Emailed
                                            </span>
                                        </div>
                                    </td>
                                    <td class="py-4 px-6 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button @click="copyToClipboard(secret.url)" class="p-2 text-vault-secondary hover:text-vault-primary hover:bg-vault-surface rounded transition-all duration-200" title="Copy Secret Link">
                                                <span class="material-symbols-outlined text-[1.125rem]">content_copy</span>
                                            </button>
                                            <a :href="secret.url" class="p-2 text-vault-secondary hover:text-vault-primary hover:bg-vault-surface rounded transition-all duration-200" target="_blank" title="View Secret">
                                                <span class="material-symbols-outlined text-[1.125rem]">open_in_new</span>
                                            </a>
                                            <button @click="deleteSecret(secret.secret_id)" class="p-2 text-vault-secondary hover:text-vault-error hover:bg-vault-surface rounded transition-all duration-200" title="Delete Secret">
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
            <!-- Guest View: Visualizer & Landing sections -->
            <div v-else class="w-full flex flex-col items-center justify-center">
                <!-- Use Cases Section -->
            <div class="w-full max-w-[74rem] mt-20 text-center">
                <h2 class="text-[2.25rem] md:text-[2.75rem] font-bold text-vault-on-surface tracking-tight mb-4 font-sans" style="font-family: 'Instrument Sans', sans-serif;">Built for every sensitive payload</h2>
                <p class="text-zinc-500 text-base max-w-2xl mx-auto mb-16 font-sans">
                    Whether sharing temporary credentials, backing up highly confidential personal files, or storing private journals and secrets that nobody else must ever see.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 text-left">
                    <div class="group bg-white/40 border border-vault-outline-variant/60 rounded-xl p-6 shadow-sm hover:border-vault-primary/40 hover:shadow-md transition-all duration-300">
                        <div class="w-10 h-10 rounded-lg bg-vault-primary/5 text-vault-primary flex items-center justify-center mb-4 group-hover:scale-110 transition-all duration-300">
                            <span class="material-symbols-outlined text-[1.25rem]">visibility_off</span>
                        </div>
                        <h4 class="text-base font-bold text-vault-on-surface mb-2 font-sans" style="font-family: 'Instrument Sans', sans-serif;">Private Journals &amp; Secrets</h4>
                        <p class="text-vault-on-surface-variant text-sm leading-relaxed font-sans" style="color: #4b5563;">
                            Write down highly confidential notes, confessions, diaries, or private thoughts. Since decryption keys are never transmitted, your secrets remain entirely yours.
                        </p>
                    </div>

                    <div class="group bg-white/40 border border-vault-outline-variant/60 rounded-xl p-6 shadow-sm hover:border-vault-primary/40 hover:shadow-md transition-all duration-300">
                        <div class="w-10 h-10 rounded-lg bg-vault-primary/5 text-vault-primary flex items-center justify-center mb-4 group-hover:scale-110 transition-all duration-300">
                            <span class="material-symbols-outlined text-[1.25rem]">folder_zip</span>
                        </div>
                        <h4 class="text-base font-bold text-vault-on-surface mb-2 font-sans" style="font-family: 'Instrument Sans', sans-serif;">Sensitive Files &amp; Backups</h4>
                        <p class="text-vault-on-surface-variant text-sm leading-relaxed font-sans" style="color: #4b5563;">
                            Store personal tax forms, medical records, private media files, or confidential business archives up to 100MB directly in your secure vault.
                        </p>
                    </div>

                    <div class="group bg-white/40 border border-vault-outline-variant/60 rounded-xl p-6 shadow-sm hover:border-vault-primary/40 hover:shadow-md transition-all duration-300">
                        <div class="w-10 h-10 rounded-lg bg-vault-primary/5 text-vault-primary flex items-center justify-center mb-4 group-hover:scale-110 transition-all duration-300">
                            <span class="material-symbols-outlined text-[1.25rem]">vpn_key</span>
                        </div>
                        <h4 class="text-base font-bold text-vault-on-surface mb-2 font-sans" style="font-family: 'Instrument Sans', sans-serif;">Credentials &amp; API Keys</h4>
                        <p class="text-vault-on-surface-variant text-sm leading-relaxed font-sans" style="color: #4b5563;">
                            Safely share access tokens, Stripe client keys, database connection strings, and environment configurations without leaving plaintext footprints.
                        </p>
                    </div>

                    <div class="group bg-white/40 border border-vault-outline-variant/60 rounded-xl p-6 shadow-sm hover:border-vault-primary/40 hover:shadow-md transition-all duration-300">
                        <div class="w-10 h-10 rounded-lg bg-vault-primary/5 text-vault-primary flex items-center justify-center mb-4 group-hover:scale-110 transition-all duration-300">
                            <span class="material-symbols-outlined text-[1.25rem]">local_fire_department</span>
                        </div>
                        <h4 class="text-base font-bold text-vault-on-surface mb-2 font-sans" style="font-family: 'Instrument Sans', sans-serif;">Burn-on-View Messages</h4>
                        <p class="text-vault-on-surface-variant text-sm leading-relaxed font-sans" style="color: #4b5563;">
                            Send login codes, master passwords, or time-sensitive messages to trusted contacts. The data automatically incinerates immediately after being read.
                        </p>
                    </div>
                </div>
            </div>


            <!-- Interactive Security Architecture Workflow -->
            <div class="w-full max-w-[74rem] mt-32 text-center">
                <h2 class="text-[2.25rem] md:text-[2.75rem] font-bold text-vault-on-surface tracking-tight mb-20 font-sans" style="font-family: 'Instrument Sans', sans-serif;">How Illusion Vault Protects You</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-x-16 gap-y-12 text-left">
                    <div class="flex flex-col">
                        <span class="text-vault-on-surface-variant/40 font-mono text-base font-bold tracking-widest mb-4">01</span>
                        <h4 class="text-xl sm:text-2xl font-bold text-vault-on-surface mb-3 font-sans" style="font-family: 'Instrument Sans', sans-serif;">Locally Encrypt</h4>
                        <p class="text-vault-on-surface-variant text-[0.9375rem] leading-relaxed font-sans" style="font-family: 'Inter', sans-serif; color: #4b5563;">
                            When you write text or add files, your browser encrypts the payload using AES-256-GCM with the encryption key you entered or generated. The key exists only in memory (RAM) for decryption in the browser and is never saved to disk or browser storage.
                        </p>
                    </div>

                    <div class="flex flex-col">
                        <span class="text-vault-on-surface-variant/40 font-mono text-base font-bold tracking-widest mb-4">02</span>
                        <h4 class="text-xl sm:text-2xl font-bold text-vault-on-surface mb-3 font-sans" style="font-family: 'Instrument Sans', sans-serif;">Secure Store</h4>
                        <p class="text-vault-on-surface-variant text-[0.9375rem] leading-relaxed font-sans" style="font-family: 'Inter', sans-serif; color: #4b5563;">
                            The data and files are encrypted, and the ciphertext is saved to the database, and encrypted files are uploaded to the storage bucket. Indeed, with access to the storage systems, no one can read your data.
                        </p>
                    </div>

                    <div class="flex flex-col">
                        <span class="text-vault-on-surface-variant/40 font-mono text-base font-bold tracking-widest mb-4">03</span>
                        <h4 class="text-xl sm:text-2xl font-bold text-vault-on-surface mb-3 font-sans" style="font-family: 'Instrument Sans', sans-serif;">Zero-Knowledge Access</h4>
                        <p class="text-vault-on-surface-variant text-[0.9375rem] leading-relaxed font-sans" style="font-family: 'Inter', sans-serif; color: #4b5563;">
                            The user visits the link with the secret ID. They need to input the decryption key in the browser, decrypt the payload on the device, and the data is shown on the screen. The key or history is never saved anywhere.
                        </p>
                    </div>
                </div>
            </div>

            <!-- FAQ Section -->
            <div class="w-full max-w-[74rem] mt-32 text-center">
                <h2 class="text-[2.25rem] md:text-[2.75rem] font-bold text-vault-on-surface tracking-tight mb-4 font-sans" style="font-family: 'Instrument Sans', sans-serif;">Frequently Asked Questions</h2>
                <p class="text-zinc-500 text-base max-w-2xl mx-auto mb-16 font-sans">
                    Everything you need to know about the architecture, security mechanisms, and capabilities of Ilusion Vault.
                </p>

                <div class="max-w-3xl mx-auto text-left flex flex-col gap-4">
                    <div class="border border-vault-outline-variant bg-white/40 rounded-lg overflow-hidden transition-all duration-200">
                        <button @click="toggleFaq(0)" class="w-full py-4 px-6 flex justify-between items-center text-vault-on-surface hover:bg-vault-surface-container-low/40 transition-colors focus:outline-none">
                            <span class="text-base font-bold font-sans" style="font-family: 'Instrument Sans', sans-serif;">How does zero-knowledge client-side encryption work?</span>
                            <span class="material-symbols-outlined transform transition-transform duration-200" :class="{ 'rotate-180': activeFaqIndex === 0 }">expand_more</span>
                        </button>
                        <div v-show="activeFaqIndex === 0" class="px-6 pb-5 pt-1 text-sm text-vault-on-surface-variant leading-relaxed border-t border-vault-outline-variant/40 bg-vault-surface/20 font-sans" style="color: #4b5563;">
                            When you generate a secret, your payload is encrypted inside the browser using AES-GCM (256-bit) and PBKDF2. The decryption key is generated locally and appended to the URL fragment hash (#key). Since browser fragment identifiers are never sent to servers in HTTP requests, our infrastructure never sees, intercepts, or logs your key.
                        </div>
                    </div>

                    <div class="border border-vault-outline-variant bg-white/40 rounded-lg overflow-hidden transition-all duration-200">
                        <button @click="toggleFaq(1)" class="w-full py-4 px-6 flex justify-between items-center text-vault-on-surface hover:bg-vault-surface-container-low/40 transition-colors focus:outline-none">
                            <span class="text-base font-bold font-sans" style="font-family: 'Instrument Sans', sans-serif;">What happens if I lose my decryption key?</span>
                            <span class="material-symbols-outlined transform transition-transform duration-200" :class="{ 'rotate-180': activeFaqIndex === 1 }">expand_more</span>
                        </button>
                        <div v-show="activeFaqIndex === 1" class="px-6 pb-5 pt-1 text-sm text-vault-on-surface-variant leading-relaxed border-t border-vault-outline-variant/40 bg-vault-surface/20 font-sans" style="color: #4b5563;">
                            Because we maintain a strict zero-knowledge model, we do not store, backup, or recover encryption keys. If you lose the unique link containing your decryption fragment, your data is lost forever and cannot be decrypted by anyone, including the server administrators.
                        </div>
                    </div>

                    <div class="border border-vault-outline-variant bg-white/40 rounded-lg overflow-hidden transition-all duration-200">
                        <button @click="toggleFaq(2)" class="w-full py-4 px-6 flex justify-between items-center text-vault-on-surface hover:bg-vault-surface-container-low/40 transition-colors focus:outline-none">
                            <span class="text-base font-bold font-sans" style="font-family: 'Instrument Sans', sans-serif;">Is Ilusion Vault open source?</span>
                            <span class="material-symbols-outlined transform transition-transform duration-200" :class="{ 'rotate-180': activeFaqIndex === 2 }">expand_more</span>
                        </button>
                        <div v-show="activeFaqIndex === 2" class="px-6 pb-5 pt-1 text-sm text-vault-on-surface-variant leading-relaxed border-t border-vault-outline-variant/40 bg-vault-surface/20 font-sans" style="color: #4b5563;">
                            Yes. Security should always be verifiable. Ilusion Vault is fully open source under the MIT License, meaning you can audit the source code, review the WebCrypto encryption implementation details, or self-host it on your own server.
                        </div>
                    </div>

                    <div class="border border-vault-outline-variant bg-white/40 rounded-lg overflow-hidden transition-all duration-200">
                        <button @click="toggleFaq(3)" class="w-full py-4 px-6 flex justify-between items-center text-vault-on-surface hover:bg-vault-surface-container-low/40 transition-colors focus:outline-none">
                            <span class="text-base font-bold font-sans" style="font-family: 'Instrument Sans', sans-serif;">How does auto-burn work?</span>
                            <span class="material-symbols-outlined transform transition-transform duration-200" :class="{ 'rotate-180': activeFaqIndex === 3 }">expand_more</span>
                        </button>
                        <div v-show="activeFaqIndex === 3" class="px-6 pb-5 pt-1 text-sm text-vault-on-surface-variant leading-relaxed border-t border-vault-outline-variant/40 bg-vault-surface/20 font-sans" style="color: #4b5563;">
                            "Burn on Read" deletes the secret permanently from the database the moment it is fetched and decrypted by the recipient. Once retrieved, the data is completely wiped out of memory and storage on the server, ensuring it can never be accessed again.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom CTA Banner -->
            <div class="w-full max-w-[74rem] mt-32 mb-16 bg-gradient-to-br from-zinc-900 to-black text-white rounded-2xl p-8 md:p-12 text-center relative overflow-hidden shadow-xl">
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_bottom_left,rgba(56,189,248,0.1),transparent_40%)]"></div>
                <div class="relative z-10 max-w-2xl mx-auto flex flex-col items-center gap-6">
                    <h3 class="text-3xl md:text-4xl font-bold font-sans tracking-tight" style="font-family: 'Instrument Sans', sans-serif;">Ready to secure your data?</h3>
                    <p class="text-zinc-400 text-base md:text-lg leading-relaxed font-sans">
                        Start encrypting your credentials, text blocks, and file transfers with bank-grade local-first security. Free to get started.
                    </p>
                    <Link href="/create" class="bg-white text-black hover:bg-zinc-200 font-sans font-bold py-3.5 px-8 rounded-lg transition-all shadow-md hover:scale-105 hover:-translate-y-0.5 duration-200 mt-2">
                        Get Started Now
                    </Link>
                </div>
            </div>

            </div>
        </main>

        <footer class="w-full py-6 md:py-8 px-4 sm:px-6 md:px-12 flex flex-col md:flex-row justify-between items-center gap-6 bg-vault-surface border-t border-vault-outline-variant z-10 relative mt-auto">
            <div class="flex items-center gap-2 font-label-md text-label-md uppercase tracking-widest text-vault-on-surface">
                <img src="/ilusion-logo.png" alt="Ilusion" class="w-8 h-8 object-contain opacity-80" />
                Ilusion Vault
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
    font-family: 'Space Grotesk', sans-serif;
    font-size: 3.75rem;
    font-weight: 700;
    line-height: 1.1;
    letter-spacing: -0.02em;
    word-spacing: 0.16em;
}
.font-headline-md {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.35rem;
    font-weight: 600;
    line-height: 1.2;
    letter-spacing: -0.01em;
    word-spacing: 0.12em;
}
.font-body-md {
    font-family: 'Inter', sans-serif;
    font-size: 0.95rem;
    font-weight: 400;
    line-height: 1.5;
}
.font-label-md {
    font-family: 'Inter', sans-serif;
    font-size: 0.8125rem;
    font-weight: 600;
    line-height: 1;
    letter-spacing: 0.08em;
}
.font-label-sm {
    font-family: 'Inter', sans-serif;
    font-size: 0.6875rem;
    font-weight: 600;
    line-height: 1;
    letter-spacing: 0.1em;
}

/* Animated gradient orbs */
.gradient-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(100px);
    opacity: 0.25;
    animation-timing-function: ease-in-out;
    animation-iteration-count: infinite;
    animation-direction: alternate;
}
.gradient-orb-1 {
    width: 40rem; height: 40rem;
    top: -15%; left: -10%;
    background: radial-gradient(circle, rgba(160,160,170,0.08) 0%, transparent 70%);
    animation: orbFloat1 12s infinite alternate;
}
.gradient-orb-2 {
    width: 35rem; height: 35rem;
    bottom: -10%; right: -5%;
    background: radial-gradient(circle, rgba(100,100,110,0.06) 0%, transparent 70%);
    animation: orbFloat2 15s infinite alternate;
}
.gradient-orb-3 {
    width: 25rem; height: 25rem;
    top: 40%; left: 50%;
    background: radial-gradient(circle, rgba(200,200,210,0.05) 0%, transparent 70%);
    animation: orbFloat3 10s infinite alternate;
}
@keyframes orbFloat1 { from { transform: translate(0,0) scale(1); } to { transform: translate(60px,40px) scale(1.1); } }
@keyframes orbFloat2 { from { transform: translate(0,0) scale(1); } to { transform: translate(-50px,-30px) scale(1.15); } }
@keyframes orbFloat3 { from { transform: translate(-50%,-50%) scale(1); } to { transform: translate(-40%,-40%) scale(1.2); } }

/* Scramble headline transition */
.scramble-mono { font-family: 'JetBrains Mono', monospace; transition: font-family 0.5s ease; }
.scramble-done { font-family: 'Instrument Sans', sans-serif !important; transition: font-family 0.5s ease; }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes wiggle {
    0%,100% { transform: rotate(0deg); }
    25% { transform: rotate(-8deg); }
    75% { transform: rotate(8deg); }
}

.bg-gradient-text {
    background: linear-gradient(135deg, #18181b 0%, #52525b 50%, #8c8c8c 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    display: inline-block;
}

@media (max-width: 768px) {
    .font-display { font-size: 2.75rem; }
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
    opacity: 0.35;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
