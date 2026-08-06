<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { User, Shield } from '@lucide/vue';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
        icon: User,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: Shield,
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
const page = usePage();
const user = computed(() => page.props.auth?.user);
</script>

<template>
    <div class="vault-light bg-vault-background text-vault-on-background min-h-screen flex flex-col font-body-md antialiased selection:bg-[#dbe1ff] selection:text-[#00174b] relative overflow-x-hidden">
        <div class="absolute inset-0 bg-dot-grid pointer-events-none z-0"></div>

        <!-- Custom Header -->
        <header class="fixed top-0 left-0 right-0 z-50 flex justify-between items-center px-4 sm:px-6 md:px-12 py-3 md:py-4 bg-vault-surface/80 backdrop-blur-xl border-b border-vault-outline-variant shadow-sm transition-all duration-300">
            <div class="flex items-center gap-4 sm:gap-8 max-w-[1200px] w-full mx-auto">
                <div class="flex items-center gap-4 sm:gap-8 flex-1">
                    <Link class="flex items-center gap-2 font-headline-md text-headline-md font-bold text-vault-on-surface hover:opacity-90" href="/">
                        <img src="/ilusion-logo.png" alt="Ilusion" class="w-10 h-10 md:w-12 md:h-12 object-contain" />
                        Ilusion
                    </Link>
                </div>
                <div class="flex items-center gap-3">
                    <template v-if="user">
                        <span class="font-body-md text-body-md text-vault-on-surface-variant select-none hidden sm:inline-block mr-2">
                            hello <span class="font-semibold text-vault-on-surface">{{ user.name }}</span>
                        </span>
                        <Link
                            href="/profile"
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
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-grow flex flex-col items-center justify-start px-4 sm:px-6 md:px-12 pt-24 pb-12 relative z-10 w-full max-w-[1200px] mx-auto gap-8">
            <div class="w-full max-w-[960px] bg-vault-surface-container-lowest border border-vault-outline-variant rounded-xl p-6 md:p-8 shadow-sm flex flex-col lg:flex-row lg:space-x-12">
                <aside class="w-full max-w-xl lg:w-48 mb-6 lg:mb-0">
                    <nav class="flex flex-col space-y-1" aria-label="Settings">
                        <Link
                            v-for="item in sidebarNavItems"
                            :key="toUrl(item.href)"
                            :href="item.href"
                            class="w-full justify-start py-2 px-3 rounded font-medium text-sm transition-all duration-200 flex items-center gap-2 border border-transparent"
                            :class="[
                                isCurrentOrParentUrl(item.href)
                                    ? 'bg-vault-primary text-white font-semibold shadow-sm'
                                    : 'text-vault-on-surface hover:bg-vault-surface hover:border-vault-outline-variant'
                            ]"
                        >
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </nav>
                </aside>

                <div class="flex-1 md:max-w-2xl">
                    <section class="space-y-12">
                        <slot />
                    </section>
                </div>
            </div>
        </main>
    </div>
</template>
