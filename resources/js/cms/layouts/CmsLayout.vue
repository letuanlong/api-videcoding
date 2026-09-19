<script setup>
import { computed, ref } from 'vue';
import { RouterLink, RouterView, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { menuFor, ROLE_LABELS } from '../utils/permissions';
import UserAvatar from '../components/UserAvatar.vue';

const auth = useAuthStore();
const router = useRouter();

const menuOpen = ref(false);
const menu = computed(() => menuFor(auth.role));

async function logout() {
    await auth.logout();
    router.replace({ name: 'login' });
}
</script>

<template>
    <div class="min-h-screen lg:flex">
        <!-- Thanh trên cùng cho màn hình nhỏ -->
        <header class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
            <span class="font-semibold text-slate-900">CMS</span>
            <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1 text-sm"
                :aria-expanded="menuOpen"
                aria-controls="cms-sidebar"
                data-testid="menu-toggle"
                @click="menuOpen = !menuOpen"
            >
                Menu
            </button>
        </header>

        <aside
            id="cms-sidebar"
            class="w-full shrink-0 border-r border-slate-200 bg-white lg:block lg:w-64"
            :class="menuOpen ? 'block' : 'hidden'"
            data-testid="sidebar"
        >
            <div class="hidden px-6 py-5 text-lg font-semibold text-slate-900 lg:block">CMS</div>

            <div v-if="auth.user" class="flex items-center gap-3 border-y border-slate-100 px-6 py-4">
                <UserAvatar :name="auth.user.name" :src="auth.user.avatar" />
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-slate-900" data-testid="sidebar-user-name">{{ auth.user.name }}</p>
                    <p class="text-xs text-slate-500" data-testid="sidebar-user-role">{{ ROLE_LABELS[auth.user.role] ?? auth.user.role }}</p>
                </div>
            </div>

            <nav class="space-y-1 px-3 py-4" aria-label="Main">
                <RouterLink
                    v-for="item in menu"
                    :key="item.key"
                    :to="item.to"
                    class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
                    active-class="!bg-indigo-50 !text-indigo-700"
                    :data-testid="`nav-${item.key}`"
                    @click="menuOpen = false"
                >
                    {{ item.label }}
                </RouterLink>

                <button
                    type="button"
                    class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
                    data-testid="nav-logout"
                    @click="logout"
                >
                    Logout
                </button>
            </nav>
        </aside>

        <main class="min-w-0 flex-1 p-4 sm:p-6 lg:p-8">
            <RouterView />
        </main>
    </div>
</template>
