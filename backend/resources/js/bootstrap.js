import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

if (window.__locale) {
    document.documentElement.lang = window.__locale;
    document.documentElement.dir = (window.__locale === 'ar' || window.__locale === 'ku') ? 'rtl' : 'ltr';
}
