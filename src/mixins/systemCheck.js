/**
 * System Check Mixin
 *
 * Performs one-time PHP CLI check on first component mount
 * Shows notification if there are errors or warnings
 */
export default {
  data() {
    return {
      systemCheckPerformed: false,
    };
  },

  async mounted() {
    // Reset flag when coming back to Simplify from outside
    if (
      !window.__simplifyLastPath ||
      !window.__simplifyLastPath.includes("/panel/simplify")
    ) {
      window.__simplifySystemCheckDone = false;
    }

    // Store current path
    window.__simplifyLastPath = window.location.pathname;

    // Only perform check once per Simplify session
    if (window.__simplifySystemCheckDone) {
      return;
    }

    try {
      const response = await this.$api.get("simplify/system/php-cli-check");

      // Only mark as done if status is OK
      // If there are errors, keep checking on every page load
      if (response.status === "ok") {
        window.__simplifySystemCheckDone = true;
      } else if (response.status === "error") {
        this.showSystemError(response);
      }
    } catch (error) {
      // Even if there's an error, try to show notification if we have error data
      if (error && error.status === "error") {
        this.showSystemError(error);
      }
    }
  },

  methods: {
    showSystemError(checkResult) {
      const errors = checkResult.errors || [];
      if (errors.length === 0) {
        return;
      }

      const error = errors[0];
      let message;

      // Check if error is an object with translation key
      if (typeof error === "object" && error.key) {
        message = window.panel.$t(error.key, error.data || {});
      } else {
        // Fallback for old format (plain string)
        message = error;
      }

      window.panel.notification.error(message);
    },
  },
};
