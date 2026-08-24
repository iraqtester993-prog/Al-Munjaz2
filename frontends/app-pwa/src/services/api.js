const baseUrl = import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1'
const demoMode = import.meta.env.VITE_DEMO_MODE === 'true'
const demoOrders = [
  { id: 1, track_no: 'ALM-1000', customer_name: 'أحمد كريم', phone: '07711234567', address: 'الكرادة - بغداد', price: 25000, fee: 3000, status: 'pending' },
  { id: 2, track_no: 'ALM-1001', customer_name: 'سارة علي', phone: '07722334455', address: 'أربيل - عنكاوا', price: 45000, fee: 5000, status: 'courier' },
  { id: 3, track_no: 'ALM-1002', customer_name: 'زينب جاسم', phone: '07733445566', address: 'البصرة - العشار', price: 62000, fee: 8000, status: 'delivered' },
]

function demo(path) {
  if (path.startsWith('/auth/login')) return { token: 'preview-token', user: { id: 1, name: 'مستخدم تجريبي', username: 'preview', role: 'merchant', phone: '07700000000', provinces: [{ id: 1, name: 'بغداد', is_primary: true }] } }
  if (path === '/me') return { data: { id: 1, name: 'مستخدم تجريبي', username: 'preview', role: 'merchant', phone: '07700000000', provinces: [{ id: 1, name: 'بغداد', is_primary: true }] } }
  if (path.startsWith('/dashboard')) return { data: { orders_count: 3, statuses: { pending: 1, approved: 0, courier: 1, delivered: 1, returned: 0 }, delivered_value: 62000, wallet_balance: 245000, budget: 500000 } }
  if (path.startsWith('/orders')) return { data: demoOrders }
  if (path.startsWith('/wallet')) return { data: { balance: 245000, budget: 500000, transactions: [{ id: 1, type: 'settlement', amount: 62000, direction: 1, note: 'تسوية طلب ALM-1002' }, { id: 2, type: 'delivery_fee', amount: 8000, direction: -1, note: 'رسم توصيل' }] } }
  if (path.startsWith('/chats')) return { data: [{ id: 1, title: 'دعم المنجز', last_message: 'أهلاً بك، كيف يمكننا مساعدتك؟', unread: 1 }] }
  if (path.startsWith('/notifications')) return { data: [{ id: 1, title: 'طلب جديد', body: 'تمت إضافة طلب جديد إلى حسابك.', read_at: null }] }
  return { data: [] }
}

export async function api(path, { method = 'GET', body, token } = {}) {
  if (demoMode) return demo(path)
  const response = await fetch(`${baseUrl}${path}`, {
    method,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    ...(body ? { body: JSON.stringify(body) } : {}),
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(data.message || 'تعذر الاتصال بالخدمة')
  return data
}
