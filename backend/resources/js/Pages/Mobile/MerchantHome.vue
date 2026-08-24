<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppShell from '../../Components/AppShell.vue'
import HeroSlider from '../../Components/HeroSlider.vue'
import StatusBadge from '../../Components/StatusBadge.vue'

const props = defineProps({
    stats: { type: Object, required: true },
    recentOrders: { type: Array, default: () => [] },
    heroSlides: { type: Array, default: () => [] },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)
const tenant = computed(() => page.props.auth?.tenant)

const statCards = computed(() => [
    { icon: 'clipboard', label: t('My Orders Today'), value: props.stats.today, tint: 'var(--primary-tint)', color: 'var(--primary-strong)' },
    { icon: 'check', label: t('Delivered'), value: props.stats.delivered, tint: 'var(--success-tint)', color: 'var(--success)' },
    { icon: 'back', label: t('Returned'), value: props.stats.returned, tint: 'var(--danger-tint)', color: 'var(--danger)' },
    { icon: 'clock', label: t('Pending'), value: props.stats.pending, tint: 'var(--st-pending-tint)', color: 'var(--st-pending)' },
])

function statIcon(name) {
    const paths = {
        clipboard: 'M9 4h6v3H9z M9 4H5v17h14V4h-4 M9 11h6 M9 15h6',
        check: 'M20 6 9 17l-5-5',
        back: 'M9 14 4 9l5-5 M4 9h10a6 6 0 0 1 0 12h-3',
        clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-13v5l3 2',
    }
    return paths[name]
}

const greeting = computed(() => {
    const h = new Date().getHours()
    if (h < 12) return t('Good to see you')
    return t('Good to see you')
})
</script>

<template>
    <AppShell :title="greeting" :subtitle="user?.name">
        <template #title>
            {{ greeting }}
            <span class="tb-sub">{{ user?.name }} · {{ tenant?.name }}</span>
        </template>

        <div class="hero-card">
            <div class="hc-label">{{ t('My Due Balance') }}</div>
            <div class="hc-value mono">{{ fmt(stats.walletBalance) }} <span style="font-size: 13px; font-weight: 700">د.ع</span></div>
            <div class="hc-row">
                <span class="hc-chip">
                    <span class="live-dot"><i></i></span>
                    {{ t('Working now') }}
                </span>
                <span class="hc-chip">{{ t('Today') }}: {{ stats.today }}</span>
            </div>
        </div>

        <HeroSlider :slides="heroSlides" />

        <div class="stat-grid">
            <div v-for="s in statCards" :key="s.label" class="stat-card">
                <div class="si" :style="{ background: s.tint, color: s.color }">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="statIcon(s.icon)" />
                    </svg>
                </div>
                <div class="sv">{{ s.value }}</div>
                <div class="sl">{{ s.label }}</div>
            </div>
        </div>

        <div class="section-title">
            <h3>{{ t('Recent Orders') }}</h3>
            <a @click="$inertia.visit(route('app.orders'))">{{ t('See all') }}</a>
        </div>

        <div v-if="recentOrders.length" class="list-card">
            <div v-for="o in recentOrders" :key="o.id" class="order-row" @click="$inertia.visit(route('app.orders'))">
                <div class="order-ic">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8" />
                    </svg>
                </div>
                <div class="order-mid">
                    <b>{{ o.customer_name_ar }}</b>
                    <span class="mono">{{ o.track_no }}</span>
                </div>
                <div class="order-end">
                    <b class="mono">{{ fmt(o.price) }}</b>
                    <StatusBadge :status="o.status" style="margin-top: 5px" />
                </div>
            </div>
        </div>
        <div v-else class="empty-hint">{{ t('No orders yet') }}</div>
    </AppShell>
</template>
