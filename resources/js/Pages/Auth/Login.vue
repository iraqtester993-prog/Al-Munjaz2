<script setup>
import { ref, computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import Flash from '../../Components/Flash.vue'

const page = usePage()
const errors = computed(() => page.props.errors || {})

const roles = [
    { key: 'merchant', label: t('Merchant App'), icon: 'shop', desc: t('My orders, statement, wallet') },
    { key: 'courier', label: t('Courier App'), icon: 'bike', desc: t('My deliveries, collections, wallet') },
    { key: 'admin', label: t('Admin Dashboard'), icon: 'shield', desc: t('Platform management') },
]

const form = useForm({ username: '', password: '', role: 'merchant' })

function icon(name) {
    const paths = {
        shop: 'M4 10v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V10 M2 7l1-3h18l1 3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z',
        bike: 'M5 18a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14-8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z M5 10h14 M12 10l-2-4h5',
        shield: 'M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3Z',
    }
    return paths[name]
}

function demo() {
    form.username = form.role === 'admin' ? 'admin' : form.role
    form.password = '123456'
}

function submit() {
    form.post('/login', { preserveScroll: true })
}
</script>

<template>
    <div class="app-stage" style="padding: 24px 16px">
        <div class="app-shell" style="justify-content: flex-start; overflow-y: auto">
            <Flash />
            <div style="padding: 40px 22px 26px; text-align: center">
                <div style="width: 68px; height: 68px; margin: 0 auto 14px; border-radius: 20px; background: linear-gradient(135deg, var(--primary), var(--accent)); display: flex; align-items: center; justify-content: center; color: #fff">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19 12 4l8 15 M8 13h8" />
                    </svg>
                </div>
                <h1 style="font-size: 21px; font-weight: 900">{{ t('Merchant App') }}</h1>
                <p class="text-muted" style="margin-top: 4px">{{ t('Same company account on Al-Munjaz Al-Saree, with an interface built for your role.') }}</p>
            </div>

            <div style="padding: 0 22px 18px">
                <div class="role-tabs">
                    <button v-for="r in roles" :key="r.key" class="role-tab" :class="{ active: form.role === r.key }" @click="form.role = r.key">
                        <span class="role-ico">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path :d="icon(r.icon)" />
                            </svg>
                        </span>
                        <span>{{ r.label }}</span>
                    </button>
                </div>

                <form @submit.prevent="submit" style="margin-top: 18px">
                    <div class="field">
                        <label>{{ t('Username') }}</label>
                        <input v-model="form.username" :placeholder="t('Username')" autocomplete="username" />
                        <span v-if="errors.username" class="field-error" style="display:block; font-size:10.5px; color:var(--danger); font-weight:700; margin-top:4px">{{ errors.username }}</span>
                    </div>
                    <div class="field">
                        <label>{{ t('Password') }}</label>
                        <input v-model="form.password" type="password" :placeholder="t('Password')" autocomplete="current-password" />
                        <span v-if="errors.password" class="field-error" style="display:block; font-size:10.5px; color:var(--danger); font-weight:700; margin-top:4px">{{ errors.password }}</span>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%" :disabled="form.processing">
                        <span v-if="form.processing" class="loader"></span>
                        {{ t('Login') }}
                    </button>
                </form>

                <button class="btn btn-ghost" style="width: 100%; margin-top: 10px" @click="demo">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7Z" />
                    </svg>
                    {{ t('Use demo account') }}
                </button>

                <p class="text-muted" style="text-align: center; margin-top: 16px">
                    {{ t('New here?') }}
                    <a class="link" @click="$inertia.visit(route('register', 'merchant'))" style="font-weight: 800">{{ t('Register') }}</a>
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.role-tabs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.role-tab {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 7px;
    padding: 12px 6px;
    border-radius: 14px;
    border: 1.5px solid var(--border);
    background: var(--surface);
    font-size: 10.5px;
    font-weight: 800;
    color: var(--ink-soft);
    cursor: pointer;
}
.role-tab.active {
    border-color: var(--primary);
    background: var(--primary-tint);
    color: var(--primary-strong);
}
.role-ico {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    background: var(--surface-2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ink-faint);
}
.role-tab.active .role-ico {
    background: var(--primary);
    color: #fff;
}
</style>
