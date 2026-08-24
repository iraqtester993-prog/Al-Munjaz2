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

const statCards = computed(() => [
    { icon: 'check', label: t('Delivered Today'), value: props.stats.deliveredToday, tint: 'var(--success-tint)', color: 'var(--success)' },
    { icon: 'cash', label: t('Collected Today'), value: props.stats.collectedToday, tint: 'var(--primary-tint)', color: 'var(--primary-strong)', money: true },
    { icon: 'box', label: t('With Me'), value: props.stats.withMe, tint: 'var(--st-courier-tint)', color: 'var(--st-courier)' },
    { icon: 'clock', label: t('Available'), value: props.stats.available, tint: 'var(--st-pending-tint)', color: 'var(--st-pending)' },
])

function statIcon(name) {
    const paths = {
        check: 'M20 6 9 17l-5-5',
        cash: 'M3 6h18v12H3z M3 10h18 M7 15h4',
        box: 'M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8',
        clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-13v5l3 2',
    }
    return paths[name]
}

function toggleDuty() {
    // onDuty is a static flag from the server; flip locally for UI feedback
    props.stats.onDuty = !props.stats.onDuty
}
</script>

<template>
    <AppShell :title="t('My Deliveries')" :subtitle="user?.name">
        <template #title>
            {{ t('My Deliveries') }}
            <span class="tb-sub">{{ user?.name }}</span>
        </template>

        <div class="hero-card" :style="stats.onDuty ? {} : 'filter: saturate(.6)'">
            <div class="hc-label">{{ t('Collected Today') }}</div>
            <div class="hc-value mono">{{ fmt(stats.collectedToday) }} <span style="font-size: 13px; font-weight: 700">د.ع</span></div>
            <div class="hc-row">
                <span class="hc-chip">
                    <span class="live-dot"><i></i></span>
                    {{ stats.onDuty ? t('Available for Work') : t('Currently Unavailable') }}
                </span>
                <span class="hc-chip">{{ t('Delivered Today') }}: {{ stats.deliveredToday }}</span>
            </div>
            <div class="hc-row" style="margin-top: 12px">
                <button class="hc-chip" style="background: rgba(255,255,255,.22); font-weight: 800" @click="toggleDuty">
                    {{ stats.onDuty ? t('Go Offline') : t('Go Online') }}
                </button>
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
                <div class="sv mono">{{ s.money ? fmt(s.value) : s.value }}</div>
                <div class="sl">{{ s.label }}</div>
            </div>
        </div>

        <div class="section-title">
            <h3>{{ t('Recent Deliveries') }}</h3>
            <a @click="$inertia.visit(route('app.orders'))">{{ t('See all') }}</a>
        </div>

        <div v-if="recentOrders.length" class="order-cards-grid">
            <div v-for="o in recentOrders" :key="o.id" class="deliv-card" @click="$inertia.visit(route('app.orders'))">
                <div class="deliv-top">
                    <div style="display:flex; align-items:center; gap:11px">
                        <div class="order-ic" :style="{ background: 'var(--st-courier-tint)', color: 'var(--st-courier)' }">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8" />
                            </svg>
                        </div>
                        <div class="order-mid">
                            <b>{{ o.customer_name_ar }}</b>
                            <span class="mono">{{ o.track_no }}</span>
                        </div>
                    </div>
                    <div style="text-align:end">
                        <b class="mono" style="font-size:14px">{{ fmt(o.price) }}</b>
                        <StatusBadge :status="o.status" style="margin-top: 5px" />
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="empty-hint">{{ t('No deliveries yet') }}</div>
    </AppShell>
</template>
