import { defineStore } from 'pinia'

export const useWorldStore = defineStore('world', {
  state: () => ({
    _country: 'US',
    _currency: 'USD',
    _locale: 'en-US',
    _timezone: 'America/New_York',
    _dialCode: '+1',
    _loaded: false,
  }),

  getters: {
    country: state => state._country,
    currency: state => state._currency,
    locale: state => state._locale,
    timezone: state => state._timezone,
    dialCode: state => state._dialCode,
    loaded: state => state._loaded,
  },

  actions: {
    async fetch() {
      if (this._loaded) return

      try {
        const res = await fetch('/api/public/world')

        if (!res.ok) return

        const data = await res.json()

        this._country = data.country || 'US'
        this._currency = data.currency || 'USD'
        this._locale = data.locale || 'en-US'
        this._timezone = data.timezone || 'America/New_York'
        this._dialCode = data.dial_code || '+1'
        this._loaded = true
      }
      catch {
        // Use defaults
      }
    },

    /**
     * Apply settings from an already-fetched payload (e.g. after admin save).
     */
    apply(data) {
      this._country = data.country || this._country
      this._currency = data.currency || this._currency
      this._locale = data.locale || this._locale
      this._timezone = data.timezone || this._timezone
      this._dialCode = data.dial_code || this._dialCode
      this._loaded = true
    },

    /**
     * Apply settings from a Market object (ADR-104).
     * Called on company switch to use the company's market data.
     */
    applyMarket(market) {
      if (!market) return
      this._country = market.key || this._country
      this._currency = market.currency || this._currency
      this._locale = market.locale || this._locale
      this._timezone = market.timezone || this._timezone
      this._dialCode = market.dial_code || this._dialCode
      this._loaded = true
    },
  },
})
