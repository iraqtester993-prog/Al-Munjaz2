<script setup>
import { computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import Flash from '../../Components/Flash.vue'

const page = usePage()
const errors = computed(() => page.props.errors || {})
const form = useForm({ username: '', password: '' })

function submit() {
    form.post('/dashboard/login', { preserveScroll: true })
}
</script>

<template>
    <div class="app-stage" style="padding: 24px 16px">
        <div class="app-shell" style="justify-content: center; overflow-y: auto">
            <Flash />
            <div style="padding: 40px 22px 26px; text-align: center">
                <div style="width: 68px; height: 68px; margin: 0 auto 14px; border-radius: 20px; background: linear-gradient(135deg, #163e66, #0b6e68); display: flex; align-items: center; justify-content: center; color: #fff">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3Z" /><path d="M9 12l2 2 4-4" /></svg>
                </div>
                <h1 style="font-size: 21px; font-weight: 900">{{ t('Admin Dashboard') }}</h1>
                <p class="text-muted" style="margin-top: 4px">{{ t('Platform management') }}</p>
            </div>

            <form @submit.prevent="submit" style="padding: 0 22px 18px">
                <div class="field">
                    <label>{{ t('Username') }}</label>
                    <input v-model="form.username" :placeholder="t('Username')" autocomplete="username" autofocus />
                    <span v-if="errors.username" class="field-error">{{ errors.username }}</span>
                </div>
                <div class="field">
                    <label>{{ t('Password') }}</label>
                    <input v-model="form.password" type="password" :placeholder="t('Password')" autocomplete="current-password" />
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%" :disabled="form.processing">{{ t('Login') }}</button>
            </form>

            <p class="text-muted" style="text-align:center; padding-bottom: 28px">
                <a class="link" href="/login">{{ t('Merchant App') }} / {{ t('Courier App') }}</a>
            </p>
        </div>
    </div>
</template>
