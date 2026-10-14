<template>
  <div>
    <h2 class="mb-5 text-lg font-extrabold text-primary tracking-tight">{{ t('admin.title') }}</h2>
    <div class="overflow-hidden rounded-xl border border-gray-light/50 shadow-sm">
      <table class="w-full border-collapse">
        <thead>
          <tr class="bg-surface-sunken">
            <th class="border-b border-gray-light/50 p-3 text-left text-xs font-bold uppercase tracking-wider text-primary/60">{{ t('common.name') }}</th>
            <th class="border-b border-gray-light/50 p-3 text-left text-xs font-bold uppercase tracking-wider text-primary/60">{{ t('admin.email') }}</th>
            <th class="border-b border-gray-light/50 p-3 text-left text-xs font-bold uppercase tracking-wider text-primary/60">{{ t('admin.role') }}</th>
            <th class="border-b border-gray-light/50 p-3 text-left text-xs font-bold uppercase tracking-wider text-primary/60">{{ t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in users" :key="u.id" class="hover:bg-surface-sunken transition-colors last:border-b-0">
            <td class="border-b border-gray-light/30 p-3 text-sm">
              <input
                v-model="u.name"
                class="rounded border border-gray-light/60 p-1.5 text-sm bg-surface focus:border-primary outline-none transition-all w-full"
                @blur="updateUser(u)"
              />
            </td>
            <td class="border-b border-gray-light/30 p-3 text-sm">{{ u.email }}</td>
            <td class="border-b border-gray-light/30 p-3">
              <select
                v-model="u.role"
                class="rounded border border-gray-light/60 p-1.5 text-base bg-surface focus:border-primary outline-none transition-all"
                @change="updateUser(u)"
              >
                <option value="user">{{ t('admin.user') }}</option>
                <option value="admin">{{ t('admin.admin') }}</option>
              </select>
            </td>
            <td class="border-b border-gray-light/30 p-3">
              <button
                class="rounded bg-secondary/10 px-3 py-1.5 text-xs font-bold text-secondary hover:bg-secondary hover:text-white transition-all active:scale-95"
                @click="deleteUser(u.id)"
              >
                {{ t('common.delete') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="mt-8 border-t border-gray-light/50 pt-6">
      <h3 class="mb-4 text-base font-bold text-primary">{{ t('admin.addUser') }}</h3>
      <div class="flex gap-2">
        <input
          id="new-user-name"
          v-model="newUserName"
          name="name"
          :placeholder="t('common.name')"
          class="rounded border border-gray-light/60 px-3 py-2 text-base focus:border-primary outline-none transition-all flex-grow max-w-[200px]"
        />
        <input
          id="new-user-email"
          v-model="newUserEmail"
          name="email"
          :placeholder="t('admin.email')"
          type="email"
          autocomplete="email"
          class="rounded border border-gray-light/60 px-3 py-2 text-base focus:border-primary outline-none transition-all flex-grow max-w-sm"
        />
        <select
          v-model="newUserRole"
          class="rounded border border-gray-light/60 px-3 py-2 text-base bg-surface focus:border-primary outline-none transition-all"
        >
          <option value="user">{{ t('admin.user') }}</option>
          <option value="admin">{{ t('admin.admin') }}</option>
        </select>
        <button
          class="rounded bg-surface-inverse px-5 py-2 text-sm text-white font-bold hover:shadow-md transition-all active:scale-[0.98]"
          @click="addUser"
        >
          {{ t('admin.addUser') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import { API_BASE } from '../constants'
const { t } = useI18n()

const token = ref(localStorage.getItem('token'))

const props = defineProps({
  showNotification: { type: Function, required: true }
})

const users = ref([])
const newUserEmail = ref('')
const newUserName = ref('')
const newUserRole = ref('user')

const fetchUsers = async () => {
  try {
    const res = await axios.get(`${API_BASE}/users`, {
      headers: { Authorization: `Bearer ${token.value}` }
    })
    users.value = res.data.data
  } catch (err) {
    console.error('Failed to fetch users', err)
  }
}

const addUser = async () => {
  try {
    await axios.post(`${API_BASE}/users`, {
      email: newUserEmail.value,
      name: newUserName.value,
      role: newUserRole.value
    }, {
      headers: { Authorization: `Bearer ${token.value}` }
    })
    newUserEmail.value = ''
    newUserName.value = ''
    await fetchUsers()
  } catch (err) {
    props.showNotification('Error', 'Failed to add user: ' + (err.response?.data?.error || err.message), 'error')
  }
}

const updateUser = async (u) => {
  try {
    await axios.post(`${API_BASE}/users`, {
      email: u.email,
      name: u.name,
      role: u.role
    }, {
      headers: { Authorization: `Bearer ${token.value}` }
    })
    // If we updated ourselves, we should fetch /me or at least let App know? 
    // But AdminView doesn't have access to useAuth directly.
    // For now, at least refresh the list.
    await fetchUsers()
  } catch (err) {
    props.showNotification('Error', 'Failed to update user: ' + (err.response?.data?.error || err.message), 'error')
    await fetchUsers()
  }
}

const deleteUser = async (id) => {
  if (!confirm(t('admin.deleteConfirm'))) return
  try {
    await axios.delete(`${API_BASE}/users/${id}`, {
      headers: { Authorization: `Bearer ${token.value}` }
    })
    await fetchUsers()
  } catch (err) {
    props.showNotification('Error', 'Failed to delete user: ' + (err.response?.data?.error || err.message), 'error')
  }
}

onMounted(() => {
  fetchUsers()
})
</script>
