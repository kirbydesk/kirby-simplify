/**
 * Mixin for cost formatting utilities
 * Used by provider components (budget, stats, etc.)
 */
export default {
  data() {
    return {
      // Note: Keep in sync with BudgetManager::DEFAULT_CURRENCY (PHP)
      defaultCurrency: "USD",
    };
  },
  methods: {
    /**
     * Format cost value with currency
     * @param {number|null} cost - The cost value (null if pricing unavailable)
     * @param {boolean} limitDecimals - Limit to 2 decimals (default: 4)
     * @param {boolean} currencyBefore - Place currency before value
     * @returns {string} Formatted cost string or "?" if pricing unavailable
     */
    formatCost(cost, limitDecimals = false, currencyBefore = false) {
      // Return "?" if pricing data is not available
      if (cost == null) return "?";

      const currency = this.provider?.pricing?.currency || "USD";
      const formatted = new Intl.NumberFormat(this.$panel.translation.code, {
        minimumFractionDigits: 2,
        maximumFractionDigits: limitDecimals ? 2 : 4,
      }).format(cost);
      return currencyBefore
        ? `${currency} ${formatted}`
        : `${formatted} ${currency}`;
    },

    /**
     * Format cost value without currency (always 4 decimals)
     * @param {number|null} cost - The cost value (null if pricing unavailable)
     * @returns {string} Formatted cost value or "?" if pricing unavailable
     */
    formatCostValue(cost) {
      // Return "?" if pricing data is not available
      if (cost == null) return "?";

      return new Intl.NumberFormat(this.$panel.translation.code, {
        minimumFractionDigits: 4,
        maximumFractionDigits: 4,
      }).format(cost);
    },

    /**
     * Format number with locale-specific formatting
     * @param {number} number - The number to format
     * @returns {string} Formatted number
     */
    formatNumber(number) {
      if (number == null) return "0";
      return new Intl.NumberFormat(this.$panel.translation.code).format(number);
    },
  },
};
