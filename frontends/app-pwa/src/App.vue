<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from './services/api'

const previewReset = new URLSearchParams(location.search).has('reset')
const token = ref(previewReset ? '' : (localStorage.getItem('almunjaz_token') || ''))
const me = ref(previewReset ? null : JSON.parse(localStorage.getItem('almunjaz_user') || 'null'))
const page = ref('home'), authView = ref('start'), loading = ref(false), error = ref('')
const login = ref({ username: '', password: '', role: 'merchant' })
const dashboard = ref({}), orders = ref([]), wallet = ref({ balance: 0, budget: 0, transactions: [] }), chats = ref([]), notifications = ref([]), slide = ref(0)
const isMerchant = computed(() => me.value?.role !== 'courier')
const money = value => new Intl.NumberFormat('ar-IQ').format(value || 0) + ' د.ع'
const statusName = { pending: 'قيد الانتظار', approved: 'تم القبول', courier: 'مع المندوب', delivered: 'تم التسليم', returned: 'مرتجع' }
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
    <div v-if="authView==='start'" class="welcome-screen"><div class="welcome-top"><button aria-label="اللغة">ع</button><span>المنجز السريع</span></div><div class="welcome-art"><img class="welcome-logo" src="/icons/almunjaz.png" alt="شعار المنجز"><div class="route-line"></div><span>📦</span><span>🏍️</span></div><div class="welcome-copy"><h1>توصيل أسرع، إدارة أذكى</h1><p>منصة المنجز تجمع التاجر والمندوب في تجربة واحدة سهلة وآمنة.</p></div><div class="welcome-actions"><button class="primary" @click="authView='account'">ابدأ الآن <b>←</b></button><button class="text-button" @click="authView='login'">لدي حساب بالفعل</button></div></div>
    <div v-else-if="authView==='account'" class="account-screen"><button class="back-link" @click="authView='start'">← رجوع</button><img class="brand-mark" src="/icons/almunjaz.png" alt="شعار المنجز"><h1>كيف ستستخدم المنجز؟</h1><p>اختر نوع الحساب المناسب لك</p><button class="role-card" @click="login.role='merchant';authView='login'"><span>🏪</span><div><b>تطبيق التاجر</b><small>أنشئ طلباتك، تابع الشحنات وأدر محفظتك.</small></div><i>‹</i></button><button class="role-card" @click="login.role='courier';authView='login'"><span>🏍️</span><div><b>تطبيق المندوب</b><small>استلم التوصيلات، حدّث الحالات وتابع تحصيلاتك.</small></div><i>‹</i></button><p class="account-note">إنشاء حساب جديد متاح بعد اختيار نوع الحساب.</p></div>
    <div v-else class="login-screen"><button class="back-link" @click="authView='account'">← رجوع</button><img class="brand-mark" src="/icons/almunjaz.png" alt="شعار المنجز"><div class="auth-role-title"><span>{{login.role==='merchant'?'🏪':'🏍️'}}</span><div><h1>{{login.role==='merchant'?'تطبيق التاجر':'تطبيق المندوب'}}</h1><p>سجّل دخولك للمتابعة</p></div></div><form @submit.prevent="submitLogin" class="login-card"><label>اسم المستخدم<input v-model="login.username" required autocomplete="username" placeholder="أدخل اسم المستخدم"></label><label>كلمة المرور<input v-model="login.password" required type="password" autocomplete="current-password" placeholder="أدخل كلمة المرور"></label><p v-if="error" class="error">{{error}}</p><button class="primary" :disabled="loading">{{loading?'جارِ الدخول…':'تسجيل الدخول'}}</button><button type="button" class="text-button" @click="authView='account'">تغيير نوع الحساب</button><p class="hint">نسخة المعاينة: اكتب أي بيانات للدخول</p></form></div>
  </section>
  <template v-else>
    <header class="top"><div><small>{{isMerchant?'أهلاً بك في تطبيق التاجر':'أهلاً بك في تطبيق المندوب'}}</small><h1>{{me?.name}}</h1></div><div><button class="bell" @click="page='chats'">🔔<i v-if="notifications.filter(n=>!n.read_at).length"></i></button><button class="avatar" @click="page='profile'">{{me?.name?.slice(0,1)}}</button></div></header>
    <p v-if="error" class="error">{{error}}</p>
    <section v-if="page==='home' && isMerchant">
      <div class="slider"><div class="slide-ico">{{hero[slide][2]}}</div><div><b>{{hero[slide][0]}}</b><p>{{hero[slide][1]}}</p></div><div class="dots"><button v-for="(_,i) in hero" :key="i" :class="{on:slide===i}" @click="slide=i"></button></div></div>
      <div class="balance"><div><span>{{isMerchant?'رصيدي المستحق':'تحصيل اليوم'}}</span><strong>{{money(isMerchant?wallet.balance:dashboard.delivered_value)}}</strong><small>{{isMerchant?'متابعة لحظية للحركات المالية':'تابع طلباتك المنجزة'}}</small></div><span class="balance-icon">{{isMerchant?'▣':'◈'}}</span></div>
      <div class="quick"><button @click="page='orders'"><b>＋</b><span>{{isMerchant?'إضافة طلب':'طلبات التوصيل'}}</span></button><button @click="page='wallet'"><b>◉</b><span>المحفظة</span></button><button @click="page='chats'"><b>◌</b><span>الدردشة</span></button><button @click="refresh"><b>↻</b><span>تحديث</span></button></div>
      <h2>ملخص اليوم</h2><div class="stats"><article><span>قيد الانتظار</span><b>{{dashboard.statuses?.pending||0}}</b></article><article><span>مع المندوب</span><b>{{dashboard.statuses?.courier||0}}</b></article><article><span>تم التسليم</span><b>{{dashboard.statuses?.delivered||0}}</b></article></div>
      <div class="section-head"><h2>{{isMerchant?'أحدث الطلبات':'توصيلاتي الأخيرة'}}</h2><button @click="page='orders'">عرض الكل</button></div><article v-for="order in orders.slice(0,4)" :key="order.id" class="order-card"><div class="order-icon">📦</div><div class="order-info"><b>{{order.customer_name}}</b><small>{{order.track_no}} · {{order.address}}</small></div><div class="order-side"><em :class="order.status">{{statusName[order.status]||order.status}}</em><b>{{money(order.price)}}</b></div></article>
    </section>
    <section v-else-if="page==='home'" class="courier-home">
      <div class="courier-hero" :style="{backgroundImage:`linear-gradient(90deg,rgba(8,31,29,.82),rgba(8,31,29,.12)),url(https://picsum.photos/seed/masar-c2/800/300)`}"><div><b>أعلى تحصيل لك هذا الأسبوع</b><p>700,000 د.ع يوم الخميس · استمر بنفس الأداء</p></div></div><div class="hero-dots"><i></i><i class="active"></i><i></i></div>
      <div class="collection-card"><span>المتحصل اليوم</span><strong>{{money(dashboard.delivered_value)}}</strong><div><button>🏍️ توصيلاتي اليوم: {{dashboard.statuses?.delivered||0}}</button><button @click="refresh">🟢 أنا متاح للعمل</button></div></div>
      <div class="available-title"><h2>طلبات جديدة متاحة</h2><span>الوقت المتاح: 30 دقيقة</span></div>
      <article v-for="order in orders.filter(o=>o.status==='pending')" :key="order.id" class="available-order"><div class="available-body"><div class="available-head"><b>{{order.customer_name}}</b><em>طلب جديد متاح</em></div><small>{{order.track_no}} · {{order.address}}</small><div class="available-summary"><b>{{money(order.price)}}</b><span>▱ طلب عادي</span></div><p><strong>ملاحظة الطلب:</strong> يرجى التعامل بحذر مع الطرد.</p></div><div class="available-footer"><button @click="page='orders'">عرض التفاصيل</button><strong>● الوقت المتاح للاستلام: 26:44 د</strong></div><i class="expiry"></i></article>
    </section>
    <section v-else-if="page==='orders'"><div class="section-head"><h2>{{isMerchant?'طلباتي':'طلبات التوصيل'}}</h2><button class="filter">⌄ الكل</button></div><article v-for="order in orders" :key="order.id" class="order-card full"><div class="order-icon">📦</div><div class="order-info"><b>{{order.customer_name}}</b><small>{{order.track_no}} · {{order.phone}}</small><small>📍 {{order.address}}</small></div><div class="order-side"><em :class="order.status">{{statusName[order.status]||order.status}}</em><b>{{money(order.price)}}</b></div></article><p v-if="!orders.length" class="empty">لا توجد طلبات حالياً.</p></section>
    <section v-else-if="page==='wallet'"><h2>المحفظة</h2><div class="wallet-card"><span>الرصيد المتاح</span><strong>{{money(wallet.balance)}}</strong><div><small>الميزانية</small><b>{{money(wallet.budget)}}</b></div></div><div class="section-head"><h2>آخر الحركات</h2><button>كشف الحساب</button></div><article v-for="tx in wallet.transactions" :key="tx.id" class="transaction"><span :class="tx.direction>0?'plus':'minus'">{{tx.direction>0?'↙':'↗'}}</span><div><b>{{tx.note||tx.type}}</b><small>{{tx.date||'اليوم'}}</small></div><strong :class="tx.direction>0?'credit':'debit'">{{tx.direction>0?'+':'-'}}{{money(tx.amount)}}</strong></article></section>
    <section v-else-if="page==='chats'"><h2>الدردشة والإشعارات</h2><article v-for="chat in chats" :key="chat.id" class="chat-card"><span>💬</span><div><b>{{chat.title}}</b><small>{{chat.last_message||'لا توجد رسائل بعد'}}</small></div><i v-if="chat.unread">{{chat.unread}}</i></article><article v-for="notice in notifications" :key="notice.id" class="notice"><b>{{notice.title}}</b><p>{{notice.body}}</p></article></section>
    <section v-else><h2>حسابي</h2><div class="profile-card"><img src="/icons/almunjaz.png" alt=""><div><b>{{me?.name}}</b><small>{{me?.phone||me?.username}}</small><small>📍 {{me?.provinces?.[0]?.name||'المحافظة غير محددة'}}</small></div></div><div class="settings"><button>🌐 اللغة <span>العربية</span></button><button>◐ المظهر <span>فاتح</span></button><button>❔ الدعم والمساعدة <span>‹</span></button><button>ⓘ عن المنجز <span>‹</span></button></div><button class="logout" @click="logout">تسجيل الخروج</button></section>
    <nav><button v-for="item in [['home','⌂','الرئيسية'],['orders','▤',isMerchant?'طلباتي':'توصيلاتي'],['wallet','◉','المحفظة'],['chats','◌','الدردشة'],['profile','◠','حسابي']]" :key="item[0]" :class="{active:page===item[0]}" @click="page=item[0]"><b>{{item[1]}}</b><small>{{item[2]}}</small></button></nav>
  </template>
</main>
</template>
