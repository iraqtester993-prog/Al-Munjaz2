<script setup>
defineProps({ dark: Boolean })
const emit = defineEmits(['select-role', 'toggle-theme'])
</script>

<template>
  <section class="start-screen" :class="{ dark }">
    <header class="start-top">
      <div class="start-brand"><span class="mark"><img src="/assets/logo.png" alt="شعار المنجز"></span><b>المنجز السريع</b></div>
      <button class="icon-round" @click="emit('toggle-theme')" :aria-label="dark ? 'الوضع الفاتح' : 'الوضع الداكن'">{{ dark ? '☾' : '☼' }}</button>
    </header>
    <div class="start-middle">
      <div class="start-copy"><span>منصة التوصيل الذكية</span><h1>كل طلباتك<br>في مكان واحد</h1><p>إدارة أسرع للتاجر وتجربة توصيل أوضح للمندوب.</p></div>
      <div class="role-cards">
        <button class="reference-role-card" @click="emit('select-role','merchant')"><i>⌂</i><span><b>تطبيق التاجر</b><small>طلباتي، كشف حسابي، محفظتي</small></span><em>‹</em></button>
        <button class="reference-role-card" @click="emit('select-role','courier')"><i>♧</i><span><b>تطبيق المندوب</b><small>توصيلاتي، تحصيلاتي، محفظتي</small></span><em>‹</em></button>
      </div>
    </div>
    <footer class="install-banner"><i>⇩</i><p>أضِف المنجز السريع إلى شاشتك الرئيسية للوصول السريع والعمل دون اتصال جزئياً</p><button>تثبيت</button></footer>
  </section>
</template>

<style scoped>
.start-screen{min-height:100vh;padding:26px 22px;color:#fff;background:linear-gradient(175deg,#075450,#0b6e68 60%,#de8a2e);display:flex;flex-direction:column;font-family:Cairo,Arial,sans-serif}.start-screen.dark{background:linear-gradient(175deg,#0a1211,#123230 60%,#7b4c16)}.start-top{display:flex;align-items:center;justify-content:space-between}.start-brand{display:flex;align-items:center;gap:9px;font-size:15px}.mark{width:40px;height:40px;border-radius:13px;background:#fff;display:grid;place-items:center;overflow:hidden}.mark img{width:100%;height:100%;object-fit:contain}.icon-round{width:34px;height:34px;border:1px solid #ffffff40;border-radius:10px;background:#ffffff18;color:#fff;font-size:18px}.start-middle{flex:1;display:flex;flex-direction:column;justify-content:center}.start-copy{margin-bottom:38px}.start-copy>span{font-size:11px;font-weight:800;opacity:.78}.start-copy h1{font-size:32px;line-height:1.45;margin:7px 0;font-weight:900}.start-copy p{font-size:12px;line-height:2;opacity:.85;margin:0}.role-cards{display:grid;gap:12px}.reference-role-card{display:flex;align-items:center;gap:13px;padding:16px;border:1px solid #ffffff45;border-radius:18px;background:#ffffff1f;color:#fff;text-align:right;font:inherit;backdrop-filter:blur(6px)}.reference-role-card i{width:43px;height:43px;display:grid;place-items:center;border-radius:13px;background:#fff;color:#075450;font-size:22px;font-style:normal}.reference-role-card span{flex:1}.reference-role-card b,.reference-role-card small{display:block}.reference-role-card b{font-size:14px}.reference-role-card small{font-size:10px;opacity:.84;margin-top:3px}.reference-role-card em{font-size:25px;font-style:normal}.install-banner{display:flex;align-items:center;gap:8px;margin-top:24px;padding:11px;border-radius:14px;background:#ffffff16;border:1px solid #ffffff25}.install-banner i{font-size:21px}.install-banner p{flex:1;font-size:9px;line-height:1.7;margin:0}.install-banner button{border:0;border-radius:8px;background:#fff;color:#075450;padding:7px 9px;font:inherit;font-size:10px;font-weight:800}
</style>
