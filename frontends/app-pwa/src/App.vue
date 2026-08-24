<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from './services/api'
import StartScreen from './components/StartScreen.vue'

const previewReset = new URLSearchParams(location.search).has('reset')
const token = ref(previewReset ? '' : (localStorage.getItem('almunjaz_token') || ''))
const me = ref(previewReset ? null : JSON.parse(localStorage.getItem('almunjaz_user') || 'null'))
const page = ref('home'), authView = ref('start'), orderFilter = ref('all'), loading = ref(false), error = ref('')
const login = ref({ username: '', password: '', role: 'merchant' })
const dashboard = ref({}), orders = ref([]), wallet = ref({ balance: 0, budget: 0, transactions: [] }), chats = ref([]), notifications = ref([]), slide = ref(0)
const isMerchant = computed(() => me.value?.role !== 'courier')
const money = value => new Intl.NumberFormat('ar-IQ').format(value || 0) + ' د.ع'
const statusName = { pending: 'قيد الانتظار', approved: 'تم القبول', courier: 'مع المندوب', delivered: 'تم التسليم', returned: 'مرتجع' }
const visibleOrders = computed(() => orderFilter.value === 'all' ? orders.value : orders.value.filter(order => order.status === orderFilter.value))
const darkMode = ref(false)
const toggleTheme = () => { darkMode.value = !darkMode.value; document.documentElement.dataset.theme = darkMode.value ? 'dark' : 'light' }
const hero = computed(() => isMerchant.value ? [
  ['تتبّع طلباتك لحظة بلحظة', 'اعرف حالة كل طلب وتفاصيله من مكان واحد', '📦'],
  ['طلباتك بين يديك', 'أضف الطلبات وتابع تحصيلاتك بسهولة', '✨'],
  ['محفظة واضحة وآمنة', 'راجع أرصدتك وحركاتك المالية دائماً', '💳'],
] : [
  ['مرحباً بك في المنجز', 'طلبات جاهزة للتوصيل في محافظتك', '🏍️'],
  ['أرباحك واضحة', 'تابع التحصيل والميزانية اليومية', '💰'],
  ['كن متاحاً للعمل', 'فعّل حالتك لاستلام الطلبات الجديدة', '📍'],])

async function refresh() { if (!token.value) return; loading.value = true; error.value = ''; try { const [user, summary, orderList, walletData, chatList, notificationList] = await Promise.all([api('/me',{token:token.value}),api('/dashboard',{token:token.value}),api('/orders?per_page=20',{token:token.value}),api('/wallet',{token:token.value}),api('/chats',{token:token.value}),api('/notifications',{token:token.value})]); me.value=user.data; dashboard.value=summary.data; orders.value=orderList.data||[]; wallet.value=walletData.data; chats.value=chatList.data||[]; notifications.value=notificationList.data||[]; localStorage.setItem('almunjaz_user',JSON.stringify(me.value)) } catch(e){ error.value=e.message } finally { loading.value=false } }
async function submitLogin() { loading.value=true; error.value=''; try { const result=await api('/auth/login',{method:'POST',body:{ username:login.value.username,password:login.value.password,role:login.value.role,device_name:'app-pwa'}}); token.value=result.token;me.value=result.user;localStorage.setItem('almunjaz_token',token.value);localStorage.setItem('almunjaz_user',JSON.stringify(me.value));await refresh() } catch(e){error.value=e.message}finally{loading.value=false} }
async function logout(){try{await api('/auth/logout',{method:'POST',token:token.value})}catch(_){}token.value='';me.value=null;localStorage.removeItem('almunjaz_token');localStorage.removeItem('almunjaz_user')}
onMounted(refresh)
</script>

<template>
<main class="shell">
  <section v-if="!token" class="auth-shell">
    <StartScreen v-if="authView==='start'" :dark="darkMode" @toggle-theme="toggleTheme" @select-role="role => { login.role=role; authView='login' }" />
    <div v-else-if="authView==='account'" class="account-screen"><button class="back-link" @click="authView='start'">← رجوع</button><img class="brand-mark" src="/icons/almunjaz.png" alt="شعار المنجز"><h1>كيف ستستخدم المنجز؟</h1><p>اختر نوع الحساب المناسب لك</p><button class="role-card" @click="login.role='merchant';authView='login'"><span>🏪</span><div><b>تطبيق التاجر</b><small>أنشئ طلباتك، تابع الشحنات وأدر محفظتك.</small></div><i>‹</i></button><button class="role-card" @click="login.role='courier';authView='login'"><span>🏍️</span><div><b>تطبيق المندوب</b><small>استلم التوصيلات، حدّث الحالات وتابع تحصيلاتك.</small></div><i>‹</i></button><p class="account-note">إنشاء حساب جديد متاح بعد اختيار نوع الحساب.</p></div>
    <div v-else class="login-screen"><button class="back-link" @click="authView='account'">← رجوع</button><img class="brand-mark" src="/icons/almunjaz.png" alt="شعار المنجز"><div class="auth-role-title"><span>{{login.role==='merchant'?'🏪':'🏍️'}}</span><div><h1>{{login.role==='merchant'?'تطبيق التاجر':'تطبيق المندوب'}}</h1><p>سجّل دخولك للمتابعة</p></div></div><form @submit.prevent="submitLogin" class="login-card"><label>اسم المستخدم<input v-model="login.username" required autocomplete="username" placeholder="أدخل اسم المستخدم"></label><label>كلمة المرور<input v-model="login.password" required type="password" autocomplete="current-password" placeholder="أدخل كلمة المرور"></label><p v-if="error" class="error">{{error}}</p><button class="primary" :disabled="loading">{{loading?'جارِ الدخول…':'تسجيل الدخول'}}</button><button type="button" class="text-button" @click="authView='account'">تغيير نوع الحساب</button><p class="hint">نسخة المعاينة: اكتب أي بيانات للدخول</p></form></div>
  </section>
  <template v-else>
    <header class="top"><div><h1>{{isMerchant?'أهلاً بك، '+me?.name:'مساء الله بالخير'}}</h1><small>{{isMerchant?'حساب التاجر':'مندوب تجريبي'}}</small></div><div><button class="bell" @click="toggleTheme">{{darkMode?'☾':'☼'}}</button><button class="bell" @click="page='chats'">♧<i v-if="notifications.filter(n=>!n.read_at).length"></i></button></div></header>
    <p v-if="error" class="error">{{error}}</p>
    <section v-if="page==='home' && isMerchant" class="merchant-home">
      <div class="merchant-hero" :style="{backgroundImage:`linear-gradient(90deg,rgba(8,31,29,.80),rgba(8,31,29,.10)),url(https://picsum.photos/seed/masar-m1/800/300)`}"><div><b>طلباتك تحت السيطرة</b><p>أنشئ طلبك وتابع مساره خطوة بخطوة.</p></div></div><div class="hero-dots"><i class="active"></i><i></i><i></i></div>
      <button class="new-order" @click="page='orders'">＋ إنشاء طلب جديد</button>
      <div class="merchant-summary"><span>طلباتي اليوم</span><strong>{{dashboard.orders_count||0}}</strong><small>✓ نسبة التسليم: 96%</small></div>
      <div class="distribution"><h2>توزيع حالات الطلبات</h2><div class="distribution-row"><div class="donut"><b>{{orders.length}}</b><small>طلبات الشهر</small></div><div><p><i class="pending"></i>قيد الانتظار <b>{{dashboard.statuses?.pending||0}}</b></p><p><i class="approved"></i>تم القبول <b>{{dashboard.statuses?.approved||0}}</b></p><p><i class="courier"></i>مع المندوب <b>{{dashboard.statuses?.courier||0}}</b></p><p><i class="delivered"></i>تم التسليم <b>{{dashboard.statuses?.delivered||0}}</b></p></div></div></div>
      <div class="section-head"><h2>أحدث الطلبات</h2><button @click="page='orders'">عرض الكل</button></div><article v-for="order in orders.slice(0,4)" :key="order.id" class="order-card"><div class="order-icon">▱</div><div class="order-info"><b>{{order.customer_name}}</b><small>{{order.track_no}} · {{order.address}}</small></div><div class="order-side"><em :class="order.status">{{statusName[order.status]||order.status}}</em><b>{{money(order.price)}}</b></div></article>
    </section>
    <section v-else-if="page==='home'" class="courier-home">
      <div class="courier-hero" :style="{backgroundImage:`linear-gradient(90deg,rgba(8,31,29,.82),rgba(8,31,29,.12)),url(https://picsum.photos/seed/masar-c2/800/300)`}"><div><b>أعلى تحصيل لك هذا الأسبوع</b><p>700,000 د.ع يوم الخميس · استمر بنفس الأداء</p></div></div><div class="hero-dots"><i></i><i class="active"></i><i></i></div>
      <div class="collection-card"><span>المتحصل اليوم</span><strong>{{money(dashboard.delivered_value)}}</strong><div><button>🏍️ توصيلاتي اليوم: {{dashboard.statuses?.delivered||0}}</button><button @click="refresh">🟢 أنا متاح للعمل</button></div></div>
      <div class="available-title"><h2>طلبات جديدة متاحة</h2><span>الوقت المتاح: 30 دقيقة</span></div>
      <article v-for="order in orders.filter(o=>o.status==='pending')" :key="order.id" class="available-order"><div class="available-body"><div class="available-head"><b>{{order.customer_name}}</b><em>طلب جديد متاح</em></div><small>{{order.track_no}} · {{order.address}}</small><div class="available-summary"><b>{{money(order.price)}}</b><span>▱ طلب عادي</span></div><p><strong>ملاحظة الطلب:</strong> يرجى التعامل بحذر مع الطرد.</p></div><div class="available-footer"><button @click="page='orders'">عرض التفاصيل</button><strong>● الوقت المتاح للاستلام: 26:44 د</strong></div><i class="expiry"></i></article>
    </section>
    <section v-else-if="page==='orders'" class="orders-page"><h2>{{isMerchant?'طلباتي':'توصيلاتي'}}</h2><div class="order-status-grid"><button v-for="item in [['all','▦','كل الطلبات'],['pending','◷','قيد الانتظار'],['approved','✓','تم القبول'],['courier','♧',isMerchant?'مع المندوب':'بحوزتي'],['delivered','✓','تم التسليم'],['returned','↶','مرتجع']]" :key="item[0]" :class="['status-tile',item[0],{selected:orderFilter===item[0]}]" @click="orderFilter=item[0]"><i>{{item[1]}}</i><b>{{item[0]==='all'?orders.length:orders.filter(o=>o.status===item[0]).length}}</b><small>{{item[2]}}</small></button></div><div class="order-list-title"><button v-if="orderFilter!=='all'" @click="orderFilter='all'">‹</button><b>{{orderFilter==='all'?'كل الطلبات':statusName[orderFilter]}}</b><span>{{visibleOrders.length}}</span></div><article v-for="order in visibleOrders" :key="order.id" class="order-card full"><div class="order-icon">▱</div><div class="order-info"><b>{{order.customer_name}}</b><small>{{order.track_no}} · {{order.phone}}</small><small>📍 {{order.address}}</small></div><div class="order-side"><em :class="order.status">{{statusName[order.status]||order.status}}</em><b>{{money(order.price)}}</b></div></article><p v-if="!visibleOrders.length" class="empty">لا توجد طلبات حالياً.</p></section>
    <section v-else-if="page==='wallet'"><h2>المحفظة</h2><div class="wallet-card"><span>الرصيد المتاح</span><strong>{{money(wallet.balance)}}</strong><div><small>الميزانية</small><b>{{money(wallet.budget)}}</b></div></div><div class="section-head"><h2>آخر الحركات</h2><button>كشف الحساب</button></div><article v-for="tx in wallet.transactions" :key="tx.id" class="transaction"><span :class="tx.direction>0?'plus':'minus'">{{tx.direction>0?'↙':'↗'}}</span><div><b>{{tx.note||tx.type}}</b><small>{{tx.date||'اليوم'}}</small></div><strong :class="tx.direction>0?'credit':'debit'">{{tx.direction>0?'+':'-'}}{{money(tx.amount)}}</strong></article></section>
    <section v-else-if="page==='chats'"><h2>الدردشة والإشعارات</h2><article v-for="chat in chats" :key="chat.id" class="chat-card"><span>💬</span><div><b>{{chat.title}}</b><small>{{chat.last_message||'لا توجد رسائل بعد'}}</small></div><i v-if="chat.unread">{{chat.unread}}</i></article><article v-for="notice in notifications" :key="notice.id" class="notice"><b>{{notice.title}}</b><p>{{notice.body}}</p></article></section>
    <section v-else><h2>حسابي</h2><div class="profile-card"><img src="/icons/almunjaz.png" alt=""><div><b>{{me?.name}}</b><small>{{me?.phone||me?.username}}</small><small>📍 {{me?.provinces?.[0]?.name||'المحافظة غير محددة'}}</small></div></div><div class="settings"><button>🌐 اللغة <span>العربية</span></button><button>◐ المظهر <span>فاتح</span></button><button>❔ الدعم والمساعدة <span>‹</span></button><button>ⓘ عن المنجز <span>‹</span></button></div><button class="logout" @click="logout">تسجيل الخروج</button></section>
    <nav><button v-for="item in [['home','⌂','الرئيسية'],['orders','▤',isMerchant?'طلباتي':'توصيلاتي'],['wallet','◉','المحفظة'],['chats','◌','الدردشة'],['profile','◠','حسابي']]" :key="item[0]" :class="{active:page===item[0]}" @click="page=item[0]"><b>{{item[1]}}</b><small>{{item[2]}}</small></button></nav>
  </template>
</main>
</template>
