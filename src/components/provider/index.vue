<template>
  <k-panel-inside>
    <k-view>
      <k-header>
        {{ provider ? provider.displayName : "" }}
        <template #buttons>
          <k-button-group v-if="tab === 'settings'">
            <k-button
              icon="add"
              variant="filled"
              size="sm"
              @click="$refs.settingsTab.openAddModelDialog()"
            >
              {{ $t("simplify.models.add") }}
            </k-button>
          </k-button-group>

          <k-button-group
            v-if="hasChanges && (tab === 'settings' || tab === 'budget')"
            layout="collapsed"
          >
            <k-button
              icon="undo"
              theme="notice"
              variant="filled"
              size="sm"
              @click="discardChanges"
            >
              {{ $t("discard") }}
            </k-button>

            <k-button
              icon="check"
              theme="notice"
              variant="filled"
              size="sm"
              @click="saveChanges"
            >
              {{ $t("save") }}
            </k-button>
          </k-button-group>
        </template>
      </k-header>

      <nav v-if="provider" class="k-tabs">
        <k-link
          v-for="tabItem in tabs"
          :key="tabItem.name"
          :to="tabItem.link"
          :title="tabItem.label"
          :aria-label="tabItem.label"
          :aria-current="tab === tabItem.name ? 'true' : undefined"
          data-variant="dimmed"
          data-has-text="true"
          class="k-tabs-button k-button"
        >
          <span class="k-button-icon">
            <k-icon v-if="tabItem.icon" :type="tabItem.icon" />
          </span>
          <span class="k-button-text">{{ tabItem.label }}</span>
          <span
            v-if="tabItem.badge > 0"
            :data-theme="tabItem.theme || 'notice'"
            class="k-button-badge"
          >
            {{ tabItem.badge }}
          </span>
        </k-link>
      </nav>

      <settings-tab
        ref="settingsTab"
        v-if="tab === 'settings'"
        :provider="provider"
        :provider-id="providerId"
        :provider-models="providerModels"
      />

      <budget-tab
        v-if="tab === 'budget'"
        :provider="provider"
        :provider-id="providerId"
        :budget-data="budgetData"
        :saved-budget="savedBudget"
        :budget-summary="budgetSummary"
        @budget-reset="handleBudgetReset"
      />

      <stats-tab
        v-if="tab === 'stats'"
        :provider="provider"
        :provider-id="providerId"
      />
    </k-view>
  </k-panel-inside>
</template>

<script>
import SettingsTab from "./settings.vue";
import BudgetTab from "./budget.vue";
import StatsTab from "./stats.vue";

export default {
  components: {
    SettingsTab,
    BudgetTab,
    StatsTab,
  },
  props: {
    providerId: {
      type: String,
      required: true,
    },
    providerData: {
      type: Object,
      default: null,
    },
    providerModels: {
      type: Array,
      default: () => [],
    },
    tab: {
      type: String,
      default: "ratelimit",
    },
  },
  data() {
    return {
      provider: this.providerData,
      budgetData: {
        dailyBudget: 0,
        monthlyBudget: 0,
      },
      savedBudget: {
        dailyBudget: 0,
        monthlyBudget: 0,
      },

      apiTestStatus: null, // null | 'testing' | 'success' | 'error'

      budgetSummary: null,
      budgetLoading: false,
    };
  },
  computed: {
    tabs() {
      if (!this.provider) return [];

      return [
        {
          name: "settings",
          label: this.$t("simplify.provider.tabs.settings"),
          link: `simplify/providers/${this.providerId}`,
          icon: "settings",
          badge: 0,
        },
        {
          name: "budget",
          label: this.$t("simplify.provider.tabs.budget"),
          link: `simplify/providers/${this.providerId}/budget`,
          icon: "cart",
          badge: this.budgetChangesCount,
          theme: "notice",
        },
        {
          name: "stats",
          label: this.$t("simplify.provider.tabs.stats"),
          link: `simplify/providers/${this.providerId}/stats`,
          icon: "chart",
          badge: 0,
        },
      ];
    },
    hasChanges() {
      return (
        this.budgetData.dailyBudget !== this.savedBudget.dailyBudget ||
        this.budgetData.monthlyBudget !== this.savedBudget.monthlyBudget
      );
    },
    budgetChangesCount() {
      let count = 0;

      if (this.budgetData.dailyBudget !== this.savedBudget.dailyBudget) {
        count++;
      }

      if (this.budgetData.monthlyBudget !== this.savedBudget.monthlyBudget) {
        count++;
      }

      return count;
    },
  },
  async mounted() {
    await this.loadProvider();
    await this.loadBudgetSummary();
    if (this.tab === "stats") {
      await this.loadStats();
    }

    // Register keyboard shortcut for CMD-S / CTRL-S
    this.handleKeyboardShortcut = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "s") {
        e.preventDefault();
        if (this.hasChanges) {
          this.saveChanges();
        }
      }
    };
    window.addEventListener("keydown", this.handleKeyboardShortcut);
  },
  beforeDestroy() {
    // Cleanup keyboard shortcut listener
    if (this.handleKeyboardShortcut) {
      window.removeEventListener("keydown", this.handleKeyboardShortcut);
    }
  },
  methods: {
    async loadProvider() {
      try {
        const response = await this.$api.get(
          `simplify/provider/${this.providerId}`
        );

        if (response.success) {
          // Preserve displayName and icon from initial props
          const displayName = this.provider?.displayName;
          const icon = this.provider?.icon;

          this.provider = response.provider;

          // Restore enriched metadata
          if (displayName) this.provider.displayName = displayName;
          if (icon) this.provider.icon = icon;

          this.budgetData = {
            dailyBudget: response.settings?.dailyBudget || 0,
            monthlyBudget: response.settings?.monthlyBudget || 0,
          };
          this.savedBudget = { ...this.budgetData };
        }
      } catch (error) {
        console.error("Failed to load provider:", error);
        this.$panel.notification.error(this.$t("simplify.provider.loadError"));
      }
    },
    async saveChanges() {
      try {
        const response = await this.$api.post(
          `simplify/provider/${this.providerId}/settings`,
          {
            dailyBudget: parseFloat(this.budgetData.dailyBudget) || 0,
            monthlyBudget: parseFloat(this.budgetData.monthlyBudget) || 0,
          }
        );

        if (response.success) {
          this.savedBudget = { ...this.budgetData };
          // Reload budget summary to refresh stats
          await this.loadBudgetSummary();
          this.$panel.notification.success();
        } else {
          this.$panel.notification.error(
            response.message || this.$t("simplify.provider.saveError")
          );
        }
      } catch (error) {
        console.error("Failed to save provider settings:", error);
        this.$panel.notification.error(this.$t("simplify.provider.saveError"));
      }
    },
    discardChanges() {
      this.budgetData = { ...this.savedBudget };
    },
    async handleBudgetReset() {
      await this.loadProvider();
      await this.loadBudgetSummary();
    },
    async loadBudgetSummary() {
      this.budgetLoading = true;
      try {
        const response = await this.$api.get(
          `simplify/providers/${this.providerId}/budget`
        );

        if (response.success) {
          this.budgetSummary = response.summary;
        }
      } catch (error) {
        console.error("Failed to load budget summary:", error);
      } finally {
        this.budgetLoading = false;
      }
    },
  },
};
</script>
