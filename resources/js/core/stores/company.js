import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useCompanyStore = defineStore('company', {
  state: () => ({
    _company: null,
    _members: [],
  }),

  getters: {
    company: state => state._company,
    members: state => state._members,
  },

  actions: {
    async fetchCompany() {
      const data = await $api('/company')

      this._company = data.company

      return data.company
    },

    async updateCompany({ name }) {
      const data = await $api('/company', {
        method: 'PUT',
        body: { name },
      })

      this._company = data.company

      return data.company
    },

    async fetchMembers() {
      const data = await $api('/company/members')

      this._members = data.members

      return data.members
    },

    async addMember({ email, role }) {
      const data = await $api('/company/members', {
        method: 'POST',
        body: { email, role },
      })

      this._members.push(data.member)

      return data.member
    },

    async updateMember(id, { role }) {
      const data = await $api(`/company/members/${id}`, {
        method: 'PUT',
        body: { role },
      })

      const index = this._members.findIndex(m => m.id === id)
      if (index !== -1)
        this._members[index] = data.member

      return data.member
    },

    async removeMember(id) {
      await $api(`/company/members/${id}`, {
        method: 'DELETE',
      })

      this._members = this._members.filter(m => m.id !== id)
    },
  },
})
