<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from './services/api'

const token = ref(localStorage.getItem('almunjaz_token') || '')
const me = ref(JSON.parse(localStorage.getItem('almunjaz_user') || 'null'))
const page = ref('home')
const loading = ref(false)
const error = ref('')
const login = ref({ username: '', password: '' })
const dashboard = ref({})
const orders = ref([])

const isMerchant = computed(() => me.value?.role === 'merchant')
const title = computed(() => ({ home: 'الرئيسية', orders: 'طلباتي', wallet: 'المحفظة', chats: 'الدردشة', profile: 'حسابي' })[page.value])
const money = value => new Intl.NumberFormat('ar-IQ').format(value || 0) + ' د.ع'

async function refresh() {
  if (!token.value) return
  loading.value = true; error.value = ''
  try {
    const [user, summary, orderList] = await Promise.all([api('/me', { token: token.value }), api('/dashboard', { token: token.value }), api('/orders?per_page=20', { token: token.value })])
    me.value = user.data; dashboard.value = summary.data; orders.value = orderList.data || []
    localStorage.setItem('almunjaz_user', JSON.stringify(me.value))
  } catch (e) { error.value = e.message }
  finally { loading.value = false }
}
async function submitLogin() {
  loading.value = true; error.value = ''
  try {
    const result = await api('/auth/login', { method: 'POST', body: { ...login.value, device_name: 'app-pwa' } })
    token.value = result.token; me.value = result.user
    localStorage.setItem('almunjaz_token', token.value); localStorage.setItem('almunjaz_user', JSON.stringify(me.value)); await refresh()
  } catch (e) { error.value = e.message } finally { loading.value = false }
}
async function logout() {
  try { await api('/auth/logout', { method: 'POST', token: token.value }) } catch (_) {}
  token.value = ''; me.value = null; localStorage.removeItem('almunjaz_token'); localStorage.removeItem('almunjaz_user')
}
onMounted(refresh)
</script>

<template>
  <main class="shell">
    <section v-if="!token" class="login-screen">
      <div class="brand-mark">م</div><h1>المنجز</h1><p>منصة التوصيل وإدارة الطلبات</p>
      <form @submit.prevent="submitLogin" class="card form">
        <label>اسم المستخدم<input v-model="login.username" required autocomplete="username" /></label>
        <label>كلمة المرور<input v-model="login.password" required type="password" autocomplete="current-password" /></label>
        <p v-if="error" class="error">{{ error }}</p><button :disabled="loading">{{ loading ? 'جارِ الدخول…' : 'تسجيل الدخول' }}</button>
      </form>
    </section>
    <template v-else>
      <header><div><small>مرحباً بك</small><h1>{{ me?.name }}</h1></div><button class="icon" @click="refresh" aria-label="تحديث">↻</button></header>
      <p v-if="error" class="error">{{ error }}</p>
      <section v-if="page === 'home'">
        <h2>{{ title }}</h2><div class="hero"><span>إجمالي الطلبات اليوم</span><strong>{{ dashboard.orders_count || 0 }}</strong><small>طلب مسجل في المنصة</small></div>
        <div class="stats"><article class="card"><span>قيد الانتظار</span><b>{{ dashboard.statuses?.pending || 0 }}</b></article><article class="card"><span>تم التسليم</span><b>{{ dashboard.statuses?.delivered || 0 }}</b></article></div>
        <h3>{{ isMerchant ? 'أحدث الطلبات' : 'طلبات التوصيل' }}</h3><div v-for="order in orders.slice(0, 4)" :key="order.id" class="card order"><b>{{ order.track_no }}</b><span :class="'status '+order.status">{{ order.status }}</span><p>{{ order.customer_name }} — {{ order.address }}</p><strong>{{ money(order.price) }}</strong></div>
      </section>
      <section v-else-if="page === 'orders'"><h2>الطلبات</h2><div v-for="order in orders" :key="order.id" class="card order"><div><b>{{ order.track_no }}</b><span :class="'status '+order.status">{{ order.status }}</span></div><p>{{ order.customer_name }} · {{ order.phone }}</p><p>{{ order.address }}</p><strong>{{ money(order.price) }}</strong></div><p v-if="!orders.length" class="muted">لا توجد طلبات حالياً.</p></section>
      <section v-else-if="page === 'wallet'"><h2>المحفظة</h2><div class="hero"><span>الرصيد المتاح</span><strong>{{ money(dashboard.wallet_balance) }}</strong><small>الميزانية: {{ money(dashboard.budget) }}</small></div><div class="card"><b>ملخص اليوم</b><p>قيمة الطلبات المسلّمة: {{ money(dashboard.delivered_value) }}</p></div></section>
      <section v-else-if="page === 'chats'"><h2>الدردشة</h2><div class="card"><b>دعم المنجز</b><p>قريباً: محادثات فورية مرتبطة بالطلب.</p></div></section>
      <section v-else><h2>الحساب</h2><div class="card"><b>{{ me?.name }}</b><p>{{ me?.phone || me?.username }}</p><p>الدور: {{ me?.role }}</p></div><button class="danger" @click="logout">تسجيل الخروج</button></section>
      <nav><button v-for="item in [['home','⌂','الرئيسية'],['orders','▤','طلباتي'],['wallet','◉','المحفظة'],['chats','◌','الدردشة'],['profile','◠','حسابي']]" :key="item[0]" :class="{ active: page === item[0] }" @click="page = item[0]"><b>{{ item[1] }}</b><small>{{ item[2] }}</small></button></nav>
    </template>
  </main>
</template>
