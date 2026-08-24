<script setup>
import { ref, computed, watch } from 'vue'
import { useForm, usePage, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import SheetModal from '../../Components/SheetModal.vue'

const props = defineProps({
    vehicles: { type: Object, default: () => ({}) },
    walletBalance: { type: Number, default: 0 },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)
const tenant = computed(() => page.props.auth?.tenant)
const locale = computed(() => page.props.locale || 'ar')

const showEdit = ref(false)
const showLang = ref(false)

const form = useForm({
    name: user.value?.name || '',
    phone: user.value?.phone || '',
})

const locales = computed(() => {
    const map = { ar: 'العربية', en: 'English', ku: 'کوردی' }
    return page.props.locales.map((l) => ({ key: l, label: map[l] || l }))
})

function save() {
    form.post(route('profile.update'), {
        preserveScroll: true,
        onSuccess: () => (showEdit.value = false),
    })
}

function setTheme(theme) {
    router.post(route('profile.theme'), { theme }, { preserveScroll: true })
}

function setLocale(l) {
    router.post(route('profile.locale'), { locale: l }, { preserveScroll: true })
}

function logout() {
    router.post(route('logout'))
}
</script>

<template>
    <AppShell :title="t('My Profile')">
        <div class="profile-head">
            <div class="profile-avatar">{{ user?.name?.charAt(0) }}</div>
            <b>{{ user?.name }}</b>
            <span class="mono">{{ user?.phone }}</span>
            <span v-if="tenant" class="uc-flag active">{{ tenant?.name }}</span>
            <div style="margin-top: 4px">
                <span class="badge b-primary">{{ user?.role === 'courier' ? t('Courier') : t('Merchant') }}</span>
            </div>
        </div>

        <div class="list-card">
            <div class="settings-row" @click="showEdit = true">
                <span class="sri">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 20h9 M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
                    </svg>
                </span>
                <span class="srt">{{ t('Edit Profile') }}</span>
                <span class="srv">›</span>
            </div>
            <div class="settings-row" @click="setTheme(user?.theme === 'dark' ? 'light' : 'dark')">
                <span class="sri">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z" />
                    </svg>
                </span>
                <span class="srt">{{ t('Dark Mode') }}</span>
                <span class="seg">
                    <button :class="{ active: user?.theme !== 'dark' }" @click.stop="setTheme('light')">{{ t('Off') }}</button>
                    <button :class="{ active: user?.theme === 'dark' }" @click.stop="setTheme('dark')">{{ t('On') }}</button>
                </span>
            </div>
            <div class="settings-row" @click="showLang = true">
                <span class="sri">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z M2 12h20 M12 2c3 3.5 3 16.5 0 20-3-3.5-3-16.5 0-20Z" />
                    </svg>
                </span>
                <span class="srt">{{ t('Language') }}</span>
                <span class="srv">{{ locale }}</span>
            </div>
        </div>

        <div style="margin-top: 16px">
            <div class="wallet-card" style="padding: 16px">
                <div class="wc-top" style="margin-bottom: 8px">
                    <span class="wc-badge">{{ t('Wallet') }}</span>
                </div>
                <div class="wc-value mono" style="font-size: 22px">{{ fmt(walletBalance) }} <span style="font-size: 12px">د.ع</span></div>
                <div class="wc-label">{{ t('Total Balance') }}</div>
            </div>
        </div>

        <button class="btn btn-danger" style="width: 100%; margin-top: 8px" @click="logout">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M16 17l5-5-5-5 M21 12H9" />
            </svg>
            {{ t('Logout') }}
        </button>

        <SheetModal :open="showEdit" :title="t('Edit Profile')" @close="showEdit = false">
            <div class="field">
                <label>{{ t('Full Name') }}</label>
                <input v-model="form.name" :placeholder="t('Full Name')" />
                <span v-if="form.errors.name" class="field-error">{{ form.errors.name }}</span>
            </div>
            <div class="field">
                <label>{{ t('Phone') }}</label>
                <input v-model="form.phone" dir="ltr" :placeholder="t('Phone')" />
                <span v-if="form.errors.phone" class="field-error">{{ form.errors.phone }}</span>
            </div>
            <button class="btn btn-primary" style="width: 100%" :disabled="form.processing" @click="save">
                <span v-if="form.processing" class="loader"></span>
                {{ t('Save') }}
            </button>
        </SheetModal>

        <SheetModal :open="showLang" :title="t('Language')" @close="showLang = false">
            <div v-for="l in locales" :key="l.key" class="settings-row" @click="setLocale(l.key)">
                <span class="srt">{{ l.label }}</span>
                <span v-if="locale === l.key" class="sri" style="background: var(--success-tint); color: var(--success)">✓</span>
            </div>
        </SheetModal>
    </AppShell>
</template>
