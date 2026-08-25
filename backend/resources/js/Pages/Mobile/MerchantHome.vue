<script setup>
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppShell from '../../Components/AppShell.vue'
import HeroSlider from '../../Components/HeroSlider.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import DonutChart from '../../Components/DonutChart.vue'
import OrderForm from '../../Components/OrderForm.vue'

const props = defineProps({
    stats: { type: Object, required: true },
    recentOrders: { type: Array, default: () => [] },
    heroSlides: { type: Array, default: () => [] },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)
const tenant = computed(() => page.props.auth?.tenant)
const showOrderForm = ref(false)

const deliveryRate = computed(() => props.stats.total ? Math.round((props.stats.delivered / props.stats.total) * 100) : 0)
const statusItems = computed(() => [
    { label: 'قيد الانتظار', value: props.stats.pending, color: 'var(--st-pending)' },
    { label: 'تم قبول الطلب', value: props.stats.approved, color: 'var(--st-approved)' },
    { label: 'بحوزة المندوب', value: props.stats.courier, color: 'var(--st-courier)' },
    { label: 'تم التسليم', value: props.stats.delivered, color: 'var(--st-delivered)' },
    { label: 'راجع', value: props.stats.returned, color: 'var(--st-returned)' },
])

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

        <HeroSlider :slides="heroSlides" />

        <button class="home-new-order" @click="showOrderForm = true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
            إضافة طلب جديد
        </button>

        <div class="hero-card">
            <div class="hc-label">{{ t('My Orders Today') }}</div>
            <div class="hc-value mono">{{ stats.today }}</div>
            <div class="hc-row">
                <span class="hc-chip">
                    نسبة التسليم: {{ deliveryRate }}%
                </span>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-card-head"><h4>توزيع حالات الطلبات</h4></div>
            <DonutChart :items="statusItems" />
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

        <OrderForm :open="showOrderForm" @close="showOrderForm = false" />
    </AppShell>
</template>

<style scoped>
.home-new-order {
    width: 100%; padding: 16px; border-radius: 16px; background: var(--primary); color: #fff;
    display: flex; align-items: center; justify-content: center; gap: 10px; font: inherit;
    font-size: 14px; font-weight: 800; margin-bottom: 16px; border: 0; cursor: pointer;
    box-shadow: 0 8px 24px -6px color-mix(in srgb, var(--primary) 55%, transparent);
}
</style>
