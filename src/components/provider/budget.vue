<template>
  <div class="k-provider-budget">
    <k-grid variant="fields">
      <!-- Daily Budget Column -->
      <k-column width="1/2">
        <div v-if="dailyBudgetStat" class="k-field">
          <header class="k-field-header">
            <label class="k-label k-field-label">
              <span class="k-label-text">{{
                $t("simplify.provider.budget.daily")
              }}</span>
            </label>
            <div
              style="display: flex; gap: var(--spacing-2); align-items: center"
            >
              <button
                v-if="hasCustomDailyBudget"
                data-has-icon="true"
                data-size="xs"
                data-variant="filled"
                type="button"
                class="k-button"
                :title="$t('simplify.provider.budget.reset.button')"
                @click="resetDailyBudget"
              >
                <span class="k-button-icon">
                  <svg aria-hidden="true" data-type="undo" class="k-icon">
                    <use xlink:href="#icon-undo"></use>
                  </svg>
                </span>
              </button>
            </div>
          </header>
          <dl data-size="large" class="k-stats">
            <div class="k-stat" :data-theme="dailyBudgetStat.theme">
              <dt class="k-stat-label">
                <svg
                  aria-hidden="true"
                  :data-type="dailyBudgetStat.icon"
                  class="k-icon"
                >
                  <use :xlink:href="'#icon-' + dailyBudgetStat.icon"></use>
                </svg>
                {{ dailyBudgetStat.label }}
              </dt>
              <dd class="k-stat-value">{{ dailyBudgetStat.value }}</dd>
              <dd class="k-stat-info">{{ dailyBudgetStat.info }}</dd>
            </div>
          </dl>
        </div>

        <div class="k-fieldset">
          <k-grid variant="fields">
            <k-column width="1/1">
              <k-field class="k-range-field" style="margin-top: 1.2rem">
                <k-input
                  type="range"
                  :value="budgetData.dailyBudget"
                  @input="budgetData.dailyBudget = $event"
                  :min="0"
                  :max="100"
                  :step="1"
                  :after="provider?.provider_currency || 'USD'"
                />
              </k-field>
              <k-text
                class="k-help"
                theme="help"
                style="margin-top: var(--spacing-2)"
                v-html="$t('simplify.provider.budget.dailyBudget.help')"
              />
            </k-column>
          </k-grid>
        </div>
      </k-column>

      <!-- Monthly Budget Column -->
      <k-column width="1/2">
        <div v-if="monthlyBudgetStat" class="k-field">
          <header class="k-field-header">
            <label class="k-label k-field-label">
              <span class="k-label-text">{{
                $t("simplify.provider.budget.monthly")
              }}</span>
            </label>
            <div
              style="display: flex; gap: var(--spacing-2); align-items: center"
            >
              <button
                v-if="hasCustomMonthlyBudget"
                data-has-icon="true"
                data-size="xs"
                data-variant="filled"
                type="button"
                class="k-button"
                :title="$t('simplify.provider.budget.reset.button')"
                @click="resetMonthlyBudget"
              >
                <span class="k-button-icon">
                  <svg aria-hidden="true" data-type="undo" class="k-icon">
                    <use xlink:href="#icon-undo"></use>
                  </svg>
                </span>
              </button>
            </div>
          </header>
          <dl data-size="large" class="k-stats">
            <div class="k-stat" :data-theme="monthlyBudgetStat.theme">
              <dt class="k-stat-label">
                <svg
                  aria-hidden="true"
                  :data-type="monthlyBudgetStat.icon"
                  class="k-icon"
                >
                  <use :xlink:href="'#icon-' + monthlyBudgetStat.icon"></use>
                </svg>
                {{ monthlyBudgetStat.label }}
              </dt>
              <dd class="k-stat-value">{{ monthlyBudgetStat.value }}</dd>
              <dd class="k-stat-info">{{ monthlyBudgetStat.info }}</dd>
            </div>
          </dl>
        </div>

        <div class="k-fieldset">
          <k-grid variant="fields">
            <k-column width="1/1">
              <k-field class="k-range-field" style="margin-top: 1.2rem">
                <k-input
                  type="range"
                  :value="budgetData.monthlyBudget"
                  @input="budgetData.monthlyBudget = $event"
                  :min="0"
                  :max="100"
                  :step="1"
                  :after="provider?.provider_currency || 'USD'"
                />
              </k-field>
              <k-text
                class="k-help"
                theme="help"
                style="margin-top: var(--spacing-2)"
                v-html="$t('simplify.provider.budget.monthlyBudget.help')"
              />
            </k-column>
          </k-grid>
        </div>
      </k-column>
    </k-grid>
  </div>
</template>

<script>
import costFormatting from "../../mixins/costFormatting.js";

export default {
  name: "BudgetTab",
  mixins: [costFormatting],
  props: {
    provider: {
      type: Object,
      required: true,
    },
    providerId: {
      type: String,
      required: true,
    },
    budgetData: {
      type: Object,
      required: true,
    },
    savedBudget: {
      type: Object,
      required: true,
    },
    budgetSummary: {
      type: Object,
      default: null,
    },
  },
  computed: {
    dailyBudgetStat() {
      if (!this.budgetSummary) return null;

      const dailyBudgetValue = this.budgetData.dailyBudget || 0;
      const dailySpentValue = this.budgetSummary.daily.spent || 0;

      // Wenn Budget 0, zeige "deaktiviert"
      if (dailyBudgetValue <= 0) {
        return {
          label: this.$t("simplify.provider.budget.daily"),
          value: this.$t("simplify.provider.budget.disabled"),
          info: "\u00A0", // Non-breaking space to keep height
          icon: "calendar",
          theme: "info",
        };
      }

      const currency = this.provider?.provider_currency || "USD";
      const dailyBudgetText = new Intl.NumberFormat(
        this.$panel.translation.code,
        {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        }
      ).format(dailyBudgetValue);
      const dailySpent = this.formatCostValue(dailySpentValue);
      const dailyRemainingValue = Math.max(
        0,
        dailyBudgetValue - dailySpentValue
      );
      const dailyRemaining = this.formatCost(dailyRemainingValue, true);
      const dailyPercent = Math.round(
        (dailySpentValue / dailyBudgetValue) * 100
      );

      // Theme basierend auf live-berechneten Werten
      let dailyTheme = "positive";
      if (dailySpentValue >= dailyBudgetValue) dailyTheme = "negative";

      return {
        label: this.$t("simplify.provider.budget.daily"),
        value: `${dailySpent} / ${dailyBudgetText} ${currency}`,
        info: `${dailyPercent}% ${this.$t(
          "simplify.provider.budget.used"
        )} • ${dailyRemaining} ${this.$t(
          "simplify.provider.budget.remaining"
        )}`,
        icon: "calendar",
        theme: dailyTheme,
      };
    },
    monthlyBudgetStat() {
      if (!this.budgetSummary) return null;

      const monthlyBudgetValue = this.budgetData.monthlyBudget || 0;
      const monthlySpentValue = this.budgetSummary.monthly.spent || 0;

      // Wenn Budget 0, zeige "deaktiviert"
      if (monthlyBudgetValue <= 0) {
        return {
          label: this.$t("simplify.provider.budget.monthly"),
          value: this.$t("simplify.provider.budget.disabled"),
          info: "\u00A0", // Non-breaking space to keep height
          icon: "calendar",
          theme: "info",
        };
      }

      const currency = this.provider?.provider_currency || "USD";
      const monthlyBudgetText = new Intl.NumberFormat(
        this.$panel.translation.code,
        {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        }
      ).format(monthlyBudgetValue);
      const monthlySpent = this.formatCostValue(monthlySpentValue);
      const monthlyRemainingValue = Math.max(
        0,
        monthlyBudgetValue - monthlySpentValue
      );
      const monthlyRemaining = this.formatCost(monthlyRemainingValue, true);
      const monthlyPercent = Math.round(
        (monthlySpentValue / monthlyBudgetValue) * 100
      );

      // Theme basierend auf live-berechneten Werten
      let monthlyTheme = "positive";
      if (monthlySpentValue >= monthlyBudgetValue) monthlyTheme = "negative";

      return {
        label: this.$t("simplify.provider.budget.monthly"),
        value: `${monthlySpent} / ${monthlyBudgetText} ${currency}`,
        info: `${monthlyPercent}% ${this.$t(
          "simplify.provider.budget.used"
        )} • ${monthlyRemaining} ${this.$t(
          "simplify.provider.budget.remaining"
        )}`,
        icon: "calendar",
        theme: monthlyTheme,
      };
    },
    hasCustomDailyBudget() {
      return this.budgetSummary?.daily?.spent > 0;
    },
    hasCustomMonthlyBudget() {
      return this.budgetSummary?.monthly?.spent > 0;
    },
  },
  methods: {
    async resetDailyBudget() {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.provider.budget.reset.daily.confirm"),
          submitButton: this.$t("simplify.provider.budget.reset.button"),
          cancelButton: this.$t("cancel"),
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.post(
                `simplify/providers/${this.providerId}/budget/reset`,
                { periodType: "daily" }
              );

              if (response.success) {
                this.$emit("budget-reset");
              } else {
                this.$panel.notification.error(
                  response.message ||
                    this.$t("simplify.provider.budget.reset.error")
                );
              }
            } catch (error) {
              console.error("Failed to reset budget:", error);
              this.$panel.notification.error(
                this.$t("simplify.provider.budget.reset.error")
              );
            }
            this.$panel.dialog.close();
          },
        },
      });
    },
    async resetMonthlyBudget() {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.provider.budget.reset.monthly.confirm"),
          submitButton: this.$t("simplify.provider.budget.reset.button"),
          cancelButton: this.$t("cancel"),
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.post(
                `simplify/providers/${this.providerId}/budget/reset`,
                { periodType: "monthly" }
              );

              if (response.success) {
                this.$emit("budget-reset");
              } else {
                this.$panel.notification.error(
                  response.message ||
                    this.$t("simplify.provider.budget.reset.error")
                );
              }
            } catch (error) {
              console.error("Failed to reset budget:", error);
              this.$panel.notification.error(
                this.$t("simplify.provider.budget.reset.error")
              );
            }
            this.$panel.dialog.close();
          },
        },
      });
    },
  },
};
</script>
